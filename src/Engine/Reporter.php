<?php
declare(strict_types=1);

namespace FNBBOS\Engine;

/**
 * Report generator. Produces CSV exports for the report set listed in the
 * spec. PDF rendering is left as a hook (renderPdf) — wire in dompdf or
 * mpdf if those packages are available.
 */
final class Reporter
{
    public static function exportCsv(string $name, array $columns, iterable $rows): string
    {
        $dir = (string)\config('storage.exports');
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $file = $dir . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $name) . '_' . date('Ymd_His') . '.csv';
        $fh = fopen($file, 'w');
        fputcsv($fh, $columns);
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) $line[] = $row[$col] ?? '';
            fputcsv($fh, $line);
        }
        fclose($fh);
        return $file;
    }

    public static function dailySales(int $companyId, string $from, string $to): array
    {
        $sql = '
            SELECT DATE(order_date) AS day,
                   SUM(gross_sales) AS gross,
                   SUM(sst_amount)  AS tax,
                   COUNT(*)         AS orders
            FROM sales_orders
            WHERE company_id = ? AND order_date BETWEEN ? AND ?
            GROUP BY DATE(order_date)
            ORDER BY day';
        $stmt = \db()->prepare($sql);
        $stmt->execute([$companyId, $from, $to]);
        return $stmt->fetchAll();
    }

    public static function platformSettlement(int $companyId, string $from, string $to): array
    {
        $sql = '
            SELECT p.name AS platform,
                   COUNT(so.id) AS orders,
                   SUM(so.gross_sales) AS gross,
                   SUM(sfc.commission_amount) AS commission,
                   SUM(sfc.payment_fee_amount) AS payment_fee,
                   SUM(sfc.tax_amount) AS tax,
                   SUM(sfc.net_settlement_system)   AS expected_net,
                   SUM(sfc.net_settlement_imported) AS reported_net,
                   SUM(sfc.difference) AS diff
            FROM sales_orders so
            JOIN sales_fee_calculations sfc ON sfc.sales_order_id = so.id
            JOIN platforms p ON p.id = so.platform_id
            WHERE so.company_id = ? AND so.order_date BETWEEN ? AND ?
            GROUP BY p.id, p.name';
        $stmt = \db()->prepare($sql);
        $stmt->execute([$companyId, $from, $to]);
        return $stmt->fetchAll();
    }

    public static function claimSummary(int $companyId, string $from, string $to): array
    {
        $sql = '
            SELECT c.claim_type,
                   COUNT(*) AS total,
                   SUM(CASE WHEN c.approval_status="approved" THEN 1 ELSE 0 END) AS approved,
                   SUM(CASE WHEN c.approval_status="rejected" THEN 1 ELSE 0 END) AS rejected,
                   SUM(c.amount) AS amount,
                   SUM(CASE WHEN c.risk_level IN ("high","critical") THEN 1 ELSE 0 END) AS high_risk
            FROM claims c
            WHERE c.company_id = ? AND c.claim_date BETWEEN ? AND ?
            GROUP BY c.claim_type
            ORDER BY amount DESC';
        $stmt = \db()->prepare($sql);
        $stmt->execute([$companyId, $from, $to]);
        return $stmt->fetchAll();
    }

    public static function abnormalClaims(int $companyId, string $from, string $to): array
    {
        $sql = '
            SELECT c.id, c.claim_id, u.name AS claimant, o.name AS outlet,
                   c.claim_type, c.amount, c.risk_score, c.risk_level,
                   c.risk_explanation, c.approval_status, c.submitted_at
            FROM claims c
            JOIN users u   ON u.id   = c.claimant_id
            JOIN outlets o ON o.id   = c.outlet_id
            WHERE c.company_id = ?
              AND c.claim_date BETWEEN ? AND ?
              AND c.risk_level IN ("high", "critical")
            ORDER BY c.risk_score DESC, c.submitted_at DESC';
        $stmt = \db()->prepare($sql);
        $stmt->execute([$companyId, $from, $to]);
        return $stmt->fetchAll();
    }

    public static function outletProfit(int $companyId, string $from, string $to): array
    {
        $sql = '
            SELECT o.id, o.name AS outlet,
                   COALESCE(SUM(sfc.net_settlement_system), 0) AS net_revenue,
                   COALESCE((
                     SELECT SUM(amount) FROM claims
                     WHERE company_id = o.company_id AND outlet_id = o.id
                       AND approval_status IN ("approved", "paid")
                       AND claim_date BETWEEN ? AND ?
                   ), 0) AS claims_paid,
                   o.operating_cost_target AS cost_target
            FROM outlets o
            LEFT JOIN sales_orders so ON so.outlet_id = o.id AND so.order_date BETWEEN ? AND ?
            LEFT JOIN sales_fee_calculations sfc ON sfc.sales_order_id = so.id
            WHERE o.company_id = ?
            GROUP BY o.id, o.name, o.operating_cost_target';
        $stmt = \db()->prepare($sql);
        $stmt->execute([$from, $to, $from, $to, $companyId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['profit_estimate'] = (float)$r['net_revenue'] - (float)$r['claims_paid'] - (float)$r['cost_target'];
        }
        return $rows;
    }

    public static function renderPdf(string $title, array $columns, iterable $rows): string
    {
        // Stub: return a path to a CSV; replace with dompdf/mpdf when available.
        return self::exportCsv($title . '_pdf_fallback', $columns, $rows);
    }
}
