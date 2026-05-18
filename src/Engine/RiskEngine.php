<?php
declare(strict_types=1);

namespace FNBBOS\Engine;

/**
 * Abnormal claim detection engine. Produces a 0–100 risk score, a level
 * (low / medium / high / critical) and a human-readable explanation.
 *
 * The score combines weighted factors:
 *   1.  Amount anomaly        (0–25)
 *   2.  Frequency anomaly     (0–15)
 *   3.  Duplicate receipt     (0–25)
 *   4.  Split claim detection (0–10)
 *   5.  Supplier anomaly      (0–10)
 *   6.  Time anomaly          (0–5)
 *   7.  Budget anomaly        (0–10)
 *   8.  Role/category mismatch(0–10)
 *   9.  OCR mismatch          (0–15)
 *   10. Profit impact         (0–15)
 *
 * Each factor records its individual contribution and reason in
 * claim_risk_factors so finance can audit the score.
 */
final class RiskEngine
{
    public static function score(array $claim, array $context = []): array
    {
        $factors = [];

        $factors[] = self::amountAnomaly($claim, $context);
        $factors[] = self::frequencyAnomaly($claim, $context);
        $factors[] = self::duplicateReceipt($claim, $context);
        $factors[] = self::splitClaim($claim, $context);
        $factors[] = self::supplierAnomaly($claim, $context);
        $factors[] = self::timeAnomaly($claim, $context);
        $factors[] = self::budgetAnomaly($claim, $context);
        $factors[] = self::roleMismatch($claim, $context);
        $factors[] = self::ocrMismatch($claim, $context);
        $factors[] = self::profitImpact($claim, $context);

        $factors = array_values(array_filter($factors, fn($f) => $f !== null));

        $total = 0;
        foreach ($factors as $f) $total += (int)$f['score'];
        $total = max(0, min(100, $total));

        $level = \riskLevelFromScore($total);

        $reasons = array_filter(array_column($factors, 'reason'));
        $explanation = self::buildExplanation($total, $level, $reasons);

        return [
            'score'       => $total,
            'level'       => $level,
            'factors'     => $factors,
            'explanation' => $explanation,
        ];
    }

    private static function amountAnomaly(array $claim, array $ctx): ?array
    {
        $amount  = (float)$claim['amount'];
        $avgStaff   = (float)($ctx['avg_staff']    ?? 0);
        $avgOutlet  = (float)($ctx['avg_outlet']   ?? 0);
        $avgCategory= (float)($ctx['avg_category'] ?? 0);
        $bench = max($avgStaff, $avgOutlet, $avgCategory);
        if ($bench <= 0) return null;
        $multiplier = $amount / $bench;
        $high = (float)\config('risk.amount_anomaly_multiplier_high', 2.0);
        $crit = (float)\config('risk.amount_anomaly_multiplier_critical', 3.0);

        if ($multiplier >= $crit) {
            return ['key' => 'amount_anomaly', 'score' => 25,
                'reason' => sprintf('Amount is %.1fx higher than the benchmark average.', $multiplier)];
        }
        if ($multiplier >= $high) {
            return ['key' => 'amount_anomaly', 'score' => 15,
                'reason' => sprintf('Amount is %.1fx higher than the benchmark average.', $multiplier)];
        }
        return ['key' => 'amount_anomaly', 'score' => 0, 'reason' => null];
    }

    private static function frequencyAnomaly(array $claim, array $ctx): ?array
    {
        $count = (int)($ctx['recent_claim_count'] ?? 0);
        $window = (int)\config('risk.frequency_window_days', 3);
        $threshold = (int)\config('risk.frequency_threshold', 5);
        if ($count >= $threshold) {
            return ['key' => 'frequency_anomaly', 'score' => 15,
                'reason' => sprintf('%d claims by the same staff in the last %d days.', $count, $window)];
        }
        if ($count >= max(1, $threshold - 2)) {
            return ['key' => 'frequency_anomaly', 'score' => 7,
                'reason' => sprintf('%d claims by the same staff in the last %d days.', $count, $window)];
        }
        return null;
    }

    private static function duplicateReceipt(array $claim, array $ctx): ?array
    {
        $dupes = (int)($ctx['duplicate_receipt_count'] ?? 0);
        if ($dupes > 0) {
            return ['key' => 'duplicate_receipt', 'score' => 25,
                'reason' => 'Receipt hash or OCR text matches a previously submitted claim.'];
        }
        return null;
    }

    private static function splitClaim(array $claim, array $ctx): ?array
    {
        $threshold  = (float)($ctx['approval_threshold'] ?? 0);
        $sameDay    = (int)($ctx['same_day_claims_count'] ?? 0);
        $sameDayTot = (float)($ctx['same_day_claims_total'] ?? 0);
        if ($threshold > 0 && $sameDay >= 2 && $sameDayTot >= $threshold * 0.95) {
            return ['key' => 'split_claim', 'score' => 10,
                'reason' => 'Multiple smaller claims on the same day total close to the next approval threshold.'];
        }
        return null;
    }

    private static function supplierAnomaly(array $claim, array $ctx): ?array
    {
        if (!empty($ctx['supplier_is_new'])) {
            return ['key' => 'supplier_new', 'score' => 5,
                'reason' => 'Supplier appears for the first time in the system.'];
        }
        if (!empty($ctx['supplier_used_only_by_one']) && (int)$ctx['supplier_used_only_by_one'] > 0) {
            return ['key' => 'supplier_single_user', 'score' => 10,
                'reason' => 'Supplier is used exclusively by one claimant.'];
        }
        return null;
    }

    private static function timeAnomaly(array $claim, array $ctx): ?array
    {
        $submittedAt = $claim['submitted_at'] ?? null;
        if (!$submittedAt) return null;
        $ts = strtotime((string)$submittedAt);
        if ($ts === false) return null;
        $h = (int)date('G', $ts);
        $dow = (int)date('N', $ts);
        if ($h < 6 || $h >= 23 || $dow >= 6) {
            return ['key' => 'time_anomaly', 'score' => 5,
                'reason' => 'Claim submitted outside normal working hours.'];
        }
        return null;
    }

    private static function budgetAnomaly(array $claim, array $ctx): ?array
    {
        $budget    = (float)($ctx['outlet_monthly_budget'] ?? 0);
        $usedSoFar = (float)($ctx['outlet_month_to_date']  ?? 0);
        if ($budget <= 0) return null;
        $projected = $usedSoFar + (float)$claim['amount'];
        $pct = $projected / $budget;
        $warn = (float)\config('risk.budget_warning_pct', 0.85);
        if ($pct >= 1.0) {
            return ['key' => 'budget_anomaly', 'score' => 10,
                'reason' => sprintf('Approving this claim would exceed the outlet monthly budget (%.0f%% utilised).', $pct * 100)];
        }
        if ($pct >= $warn) {
            return ['key' => 'budget_anomaly', 'score' => 5,
                'reason' => sprintf('Outlet has used %.0f%% of its monthly claim budget.', $pct * 100)];
        }
        return null;
    }

    private static function roleMismatch(array $claim, array $ctx): ?array
    {
        $role = (string)($ctx['claimant_role'] ?? '');
        $type = (string)($claim['claim_type'] ?? '');
        $disallowed = [
            'staff'         => ['marketing'],
            'outlet_manager'=> [],
        ];
        if (!empty($disallowed[$role]) && in_array($type, $disallowed[$role], true)) {
            return ['key' => 'role_mismatch', 'score' => 10,
                'reason' => 'Claim category does not normally apply to the claimant role.'];
        }
        return null;
    }

    private static function ocrMismatch(array $claim, array $ctx): ?array
    {
        if (!isset($ctx['ocr_total'])) return null;
        $ocrTotal = (float)$ctx['ocr_total'];
        $diff = abs($ocrTotal - (float)$claim['amount']);
        if ($ocrTotal > 0 && $diff / max($ocrTotal, 0.01) > 0.05) {
            return ['key' => 'ocr_mismatch', 'score' => 15,
                'reason' => sprintf('Claimed amount differs from OCR receipt amount by %s.',
                    \money($diff))];
        }
        return null;
    }

    private static function profitImpact(array $claim, array $ctx): ?array
    {
        $marginBefore = $ctx['outlet_margin_before'] ?? null;
        $marginAfter  = $ctx['outlet_margin_after']  ?? null;
        $target       = $ctx['outlet_margin_target'] ?? null;
        if ($marginBefore === null || $marginAfter === null || $target === null) return null;
        if ((float)$marginAfter < (float)$target && (float)$marginBefore >= (float)$target) {
            return ['key' => 'profit_impact', 'score' => 15,
                'reason' => 'This claim would push outlet margin below the management target.'];
        }
        return null;
    }

    private static function buildExplanation(int $score, string $level, array $reasons): string
    {
        if (empty($reasons)) {
            return sprintf('No abnormal patterns detected. Risk level: %s (%d/100).', strtoupper($level), $score);
        }
        $lead = sprintf('This claim is marked %s Risk (%d/100) because: ', strtoupper($level), $score);
        return $lead . implode(' ', array_map(fn($r) => '• ' . $r, $reasons));
    }
}
