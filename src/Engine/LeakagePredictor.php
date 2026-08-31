<?php
declare(strict_types=1);

namespace FNBBOS\Engine;

/**
 * Profit leakage prediction. Runs four detectors over the company's recent
 * data and returns ranked findings with severity, projected impact and a
 * plain-English evidence line. Findings are persisted to leakage_findings
 * so they survive between page loads and can be acknowledged by finance.
 *
 * Detectors:
 *   1. outlet_margin_at_risk — outlets where the projected month-end margin
 *      is below the configured operating cost target.
 *   2. platform_discrepancy_growth — platforms whose fee/tax discrepancies
 *      are trending upward across the last two windows.
 *   3. claim_category_growth — claim categories spending materially more
 *      this month than the trailing 90-day average.
 *   4. budget_burn_too_fast — outlets projected to exceed their monthly
 *      claim budget at the current pace.
 */
final class LeakagePredictor
{
    public static function runAll(int $companyId): array
    {
        $findings = [];
        $findings = array_merge($findings, self::outletMarginAtRisk($companyId));
        $findings = array_merge($findings, self::platformDiscrepancyGrowth($companyId));
        $findings = array_merge($findings, self::claimCategoryGrowth($companyId));
        $findings = array_merge($findings, self::budgetBurnTooFast($companyId));
        usort($findings, fn($a, $b) => self::severityRank($b['severity']) - self::severityRank($a['severity'])
            ?: ((float)$b['projected_impact'] <=> (float)$a['projected_impact']));
        return $findings;
    }

    public static function persist(int $companyId, array $findings): void
    {
        $pdo = \db();
        $pdo->beginTransaction();
        try {
            // Replace today's batch — we recompute each run.
            $pdo->prepare('DELETE FROM leakage_findings WHERE company_id = ? AND created_at >= CURDATE()')
                ->execute([$companyId]);
            $stmt = $pdo->prepare('
                INSERT INTO leakage_findings
                    (company_id, finding_type, severity, entity_type, entity_id,
                     projected_impact, evidence, created_at)
                VALUES (?,?,?,?,?,?,?,?)');
            $now = \nowDb();
            foreach ($findings as $f) {
                $stmt->execute([
                    $companyId, $f['finding_type'], $f['severity'],
                    $f['entity_type'] ?? null, $f['entity_id'] ?? null,
                    (float)$f['projected_impact'], $f['evidence'] ?? '', $now,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function severityRank(string $level): int
    {
        return ['low'=>1,'medium'=>2,'high'=>3,'critical'=>4][$level] ?? 0;
    }

    /**
     * 1. Outlet projected margin shortfall vs cost target.
     */
    private static function outletMarginAtRisk(int $companyId): array
    {
        $today   = (int)date('j');
        $lastDay = (int)date('t');
        $factor  = $today > 0 ? $lastDay / $today : 1.0;

        $sql = '
            SELECT o.id, o.name, o.operating_cost_target,
                   COALESCE(SUM(sfc.net_settlement_system), 0) AS net_mtd,
                   COALESCE((
                       SELECT SUM(amount) FROM claims
                       WHERE outlet_id = o.id AND approval_status IN ("approved","paid")
                         AND claim_date BETWEEN ? AND ?
                   ), 0) AS claims_mtd
            FROM outlets o
            LEFT JOIN sales_orders so ON so.outlet_id = o.id AND so.order_date BETWEEN ? AND ?
            LEFT JOIN sales_fee_calculations sfc ON sfc.sales_order_id = so.id
            WHERE o.company_id = ? AND o.is_active = 1
            GROUP BY o.id, o.name, o.operating_cost_target';

        $stmt = \db()->prepare($sql);
        $stmt->execute([
            date('Y-m-01'), date('Y-m-d'),
            date('Y-m-01'), date('Y-m-d'),
            $companyId,
        ]);
        $rows = $stmt->fetchAll();
        $findings = [];
        foreach ($rows as $r) {
            $target = (float)$r['operating_cost_target'];
            if ($target <= 0) continue;
            $netProjected   = (float)$r['net_mtd']    * $factor;
            $claimProjected = (float)$r['claims_mtd'] * $factor;
            $marginEom      = $netProjected - $claimProjected - $target;
            if ($marginEom >= 0) continue;

            $shortfallPct = abs($marginEom) / max($target, 1);
            $sev = $shortfallPct >= 0.5 ? 'critical' : ($shortfallPct >= 0.25 ? 'high' : 'medium');

            $findings[] = [
                'finding_type'     => 'outlet_margin_at_risk',
                'severity'         => $sev,
                'entity_type'      => 'outlet',
                'entity_id'        => (int)$r['id'],
                'projected_impact' => round(abs($marginEom), 2),
                'evidence'         => sprintf(
                    '%s: at current pace projects net %s and claims %s vs cost target %s — %s month-end shortfall (%.0f%%).',
                    $r['name'],
                    \money($netProjected), \money($claimProjected),
                    \money($target), \money(abs($marginEom)),
                    $shortfallPct * 100
                ),
            ];
        }
        return $findings;
    }

    /**
     * 2. Platforms with growing fee/tax discrepancies (last 30d vs prior 30d).
     */
    private static function platformDiscrepancyGrowth(int $companyId): array
    {
        $sql = "
            SELECT p.id, p.name,
                SUM(CASE WHEN so.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN ABS(sfc.difference) ELSE 0 END) AS recent_diff,
                SUM(CASE WHEN so.order_date <  DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                          AND so.order_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) THEN ABS(sfc.difference) ELSE 0 END) AS prior_diff
            FROM platforms p
            JOIN sales_orders so ON so.platform_id = p.id
            JOIN sales_fee_calculations sfc ON sfc.sales_order_id = so.id
            WHERE p.company_id = ?
              AND so.order_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
              AND sfc.reconciliation_status IN ('over_deducted','under_deducted','fee_discrepancy','tax_discrepancy')
            GROUP BY p.id, p.name
            HAVING recent_diff > 0";

        $stmt = \db()->prepare($sql);
        $stmt->execute([$companyId]);
        $findings = [];
        foreach ($stmt->fetchAll() as $r) {
            $recent = (float)$r['recent_diff'];
            $prior  = (float)$r['prior_diff'];
            if ($recent <= max($prior * 1.25, 50.0)) continue; // not growing meaningfully
            $delta = $recent - $prior;
            $sev = $delta >= 2000 ? 'critical' : ($delta >= 500 ? 'high' : 'medium');
            $findings[] = [
                'finding_type'     => 'platform_discrepancy_growth',
                'severity'         => $sev,
                'entity_type'      => 'platform',
                'entity_id'        => (int)$r['id'],
                'projected_impact' => round($delta, 2),
                'evidence'         => sprintf(
                    '%s discrepancies grew from %s (prior 30d) to %s (last 30d) — +%s.',
                    $r['name'], \money($prior), \money($recent), \money($delta)
                ),
            ];
        }
        return $findings;
    }

    /**
     * 3. Claim category spending materially above the trailing 90-day average.
     */
    private static function claimCategoryGrowth(int $companyId): array
    {
        $sql = "
            SELECT claim_type,
                SUM(CASE WHEN claim_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01') THEN amount ELSE 0 END) AS mtd,
                SUM(CASE WHEN claim_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                          AND claim_date <  DATE_FORMAT(CURDATE(),'%Y-%m-01') THEN amount ELSE 0 END) AS prior_90,
                COUNT(CASE WHEN claim_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01') THEN 1 END) AS mtd_cnt
            FROM claims
            WHERE company_id = ?
              AND approval_status IN ('approved','paid','submitted')
              AND claim_date >= DATE_SUB(CURDATE(), INTERVAL 120 DAY)
            GROUP BY claim_type";

        $stmt = \db()->prepare($sql);
        $stmt->execute([$companyId]);
        $today   = (int)date('j');
        $lastDay = (int)date('t');
        $factor  = $today > 0 ? $lastDay / $today : 1.0;

        $findings = [];
        foreach ($stmt->fetchAll() as $r) {
            $monthlyAvg   = (float)$r['prior_90'] / 3.0; // 90 days ≈ 3 months
            $projectedEom = (float)$r['mtd'] * $factor;
            if ($monthlyAvg <= 0 || $projectedEom <= max($monthlyAvg * 1.5, 200.0)) continue;
            $delta = $projectedEom - $monthlyAvg;
            $sev = $delta >= 2000 ? 'critical' : ($delta >= 500 ? 'high' : 'medium');
            $findings[] = [
                'finding_type'     => 'claim_category_growth',
                'severity'         => $sev,
                'entity_type'      => 'claim_type',
                'entity_id'        => null,
                'projected_impact' => round($delta, 2),
                'evidence'         => sprintf(
                    'Claim category "%s" projects %s this month vs trailing 3-month average of %s (+%s, %d claims so far).',
                    $r['claim_type'], \money($projectedEom), \money($monthlyAvg), \money($delta), (int)$r['mtd_cnt']
                ),
            ];
        }
        return $findings;
    }

    /**
     * 4. Outlets projected to exceed monthly claim budget at current pace.
     */
    private static function budgetBurnTooFast(int $companyId): array
    {
        $today   = (int)date('j');
        $lastDay = (int)date('t');
        $factor  = $today > 0 ? $lastDay / $today : 1.0;

        $sql = "
            SELECT o.id, o.name, o.monthly_claim_budget,
                COALESCE(SUM(c.amount), 0) AS mtd
            FROM outlets o
            LEFT JOIN claims c ON c.outlet_id = o.id
                AND c.claim_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')
                AND c.approval_status IN ('approved','paid','submitted')
            WHERE o.company_id = ? AND o.is_active = 1 AND o.monthly_claim_budget > 0
            GROUP BY o.id, o.name, o.monthly_claim_budget";

        $stmt = \db()->prepare($sql);
        $stmt->execute([$companyId]);
        $findings = [];
        foreach ($stmt->fetchAll() as $r) {
            $budget    = (float)$r['monthly_claim_budget'];
            $projected = (float)$r['mtd'] * $factor;
            if ($projected <= $budget) continue;
            $overage = $projected - $budget;
            $sev = $overage / $budget >= 0.5 ? 'critical' : ($overage / $budget >= 0.2 ? 'high' : 'medium');
            $findings[] = [
                'finding_type'     => 'budget_burn_too_fast',
                'severity'         => $sev,
                'entity_type'      => 'outlet',
                'entity_id'        => (int)$r['id'],
                'projected_impact' => round($overage, 2),
                'evidence'         => sprintf(
                    '%s projected to spend %s on claims vs %s budget (+%s, %.0f%% over).',
                    $r['name'], \money($projected), \money($budget), \money($overage), ($overage / $budget) * 100
                ),
            ];
        }
        return $findings;
    }
}
