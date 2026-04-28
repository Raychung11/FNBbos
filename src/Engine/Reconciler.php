<?php
declare(strict_types=1);

namespace FNBBOS\Engine;

/**
 * Bank-statement / settlement reconciliation. Matches expected platform
 * settlements (sum of sales_fee_calculations.net_settlement_system) against
 * actual bank receipts grouped by platform + outlet + date window.
 */
final class Reconciler
{
    public static function match(int $companyId, string $fromDate, string $toDate): array
    {
        $sql = "
            SELECT
                so.platform_id,
                so.outlet_id,
                DATE(so.settlement_date) AS settle_date,
                SUM(sfc.net_settlement_system)   AS expected,
                SUM(sfc.net_settlement_imported) AS reported
            FROM sales_orders so
            JOIN sales_fee_calculations sfc ON sfc.sales_order_id = so.id
            WHERE so.company_id = ?
              AND so.settlement_date BETWEEN ? AND ?
            GROUP BY so.platform_id, so.outlet_id, DATE(so.settlement_date)
        ";
        $stmt = \db()->prepare($sql);
        $stmt->execute([$companyId, $fromDate, $toDate]);
        $expected = $stmt->fetchAll();

        $bankSql = "
            SELECT bt.platform_id, bt.outlet_id, DATE(bt.value_date) AS bank_date,
                   SUM(bt.amount) AS received
            FROM bank_transactions bt
            WHERE bt.company_id = ?
              AND bt.value_date BETWEEN ? AND ?
              AND bt.direction = 'credit'
            GROUP BY bt.platform_id, bt.outlet_id, DATE(bt.value_date)
        ";
        $b = \db()->prepare($bankSql);
        $b->execute([$companyId, $fromDate, $toDate]);
        $bank = $b->fetchAll();

        $bankIndex = [];
        foreach ($bank as $row) {
            $key = $row['platform_id'] . '|' . $row['outlet_id'] . '|' . $row['bank_date'];
            $bankIndex[$key] = (float)$row['received'];
        }

        $results = [];
        foreach ($expected as $row) {
            $exp  = (float)$row['expected'];
            $key  = $row['platform_id'] . '|' . $row['outlet_id'] . '|' . $row['settle_date'];
            $rec  = $bankIndex[$key] ?? 0.0;
            $diff = round($rec - $exp, 4);
            $delayDays = 0;
            $status = 'pending';

            if ($rec <= 0) {
                $status = 'pending';
            } elseif (abs($diff) < 0.01) {
                $status = 'received';
            } elseif ($diff < 0) {
                $status = 'underpaid';
            } else {
                $status = 'overpaid';
            }

            $results[] = [
                'platform_id' => (int)$row['platform_id'],
                'outlet_id'   => (int)$row['outlet_id'],
                'settle_date' => $row['settle_date'],
                'expected'    => $exp,
                'received'    => $rec,
                'difference'  => $diff,
                'delay_days'  => $delayDays,
                'status'      => $status,
            ];
        }
        return $results;
    }
}
