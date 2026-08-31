<?php
declare(strict_types=1);

namespace FNBBOS\Engine;

use FNBBOS\AuditLog;
use FNBBOS\Engine\PlatformAdapters\AdapterInterface;
use FNBBOS\Engine\PlatformAdapters\FoodpandaAdapter;
use FNBBOS\Engine\PlatformAdapters\GrabFoodAdapter;
use FNBBOS\Engine\PlatformAdapters\MockAdapter;
use FNBBOS\Engine\PlatformAdapters\ShopeeFoodAdapter;

/**
 * Orchestrates a sync run for a (company, platform) over a date range.
 * Loads the credentials, picks the right adapter, fetches orders, and
 * persists them through the same Fee + Tax pipeline used by Importer.
 *
 * Adapter selection is by platforms.code (lower-cased). Unknown codes get
 * the MockAdapter so a tenant can demo the flow without partner creds.
 *
 * Every run writes a row to platform_sync_logs.
 */
final class PlatformSync
{
    public static function run(int $companyId, int $platformId, string $from, string $to, ?int $userId = null): array
    {
        $pdo = \db();

        $platformStmt = $pdo->prepare('SELECT id, code, name FROM platforms WHERE id = ? AND company_id = ?');
        $platformStmt->execute([$platformId, $companyId]);
        $platform = $platformStmt->fetch();
        if (!$platform) throw new \RuntimeException('Platform not found in this company.');

        $credStmt = $pdo->prepare('SELECT credentials FROM platform_credentials WHERE company_id = ? AND platform_id = ? AND is_active = 1');
        $credStmt->execute([$companyId, $platformId]);
        $credJson = $credStmt->fetchColumn();
        $credentials = $credJson ? (array)json_decode((string)$credJson, true) : [];

        $logStmt = $pdo->prepare('
            INSERT INTO platform_sync_logs
              (company_id, platform_id, triggered_by, date_from, date_to,
               orders_pulled, orders_new, status, started_at)
            VALUES (?,?,?,?,?,0,0,"running",?)');
        $logStmt->execute([$companyId, $platformId, $userId, $from, $to, \nowDb()]);
        $logId = (int)$pdo->lastInsertId();

        try {
            $adapter = self::adapterFor((string)$platform['code'], $credentials);
            $orders  = $adapter->fetchOrders($from, $to);

            $accepted = 0; $skipped = 0;
            foreach ($orders as $order) {
                $persisted = self::persistOrder($companyId, $platformId, $order);
                if ($persisted) $accepted++; else $skipped++;
            }

            $pdo->prepare('
                UPDATE platform_sync_logs
                SET orders_pulled = ?, orders_new = ?, status = "completed",
                    finished_at = ?, message = ?
                WHERE id = ?')
                ->execute([
                    count($orders), $accepted, \nowDb(),
                    sprintf('Adapter=%s. %d new, %d skipped.', $adapter->name(), $accepted, $skipped),
                    $logId,
                ]);

            $pdo->prepare('
                INSERT INTO platform_credentials (company_id, platform_id, credentials, last_sync_at, last_status, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE last_sync_at = VALUES(last_sync_at), last_status = VALUES(last_status), updated_at = VALUES(updated_at)')
                ->execute([
                    $companyId, $platformId, $credJson ?: '{}',
                    \nowDb(), 'ok', \nowDb(), \nowDb(),
                ]);

            AuditLog::record('platform.sync', 'platform', $platformId, [
                'log_id' => $logId, 'orders' => count($orders),
                'accepted' => $accepted, 'skipped' => $skipped,
                'from' => $from, 'to' => $to,
            ]);

            return ['log_id' => $logId, 'orders' => count($orders), 'new' => $accepted, 'skipped' => $skipped];
        } catch (\Throwable $e) {
            $pdo->prepare('
                UPDATE platform_sync_logs
                SET status = "failed", finished_at = ?, message = ?
                WHERE id = ?')
                ->execute([\nowDb(), substr($e->getMessage(), 0, 500), $logId]);
            throw $e;
        }
    }

    private static function adapterFor(string $code, array $credentials): AdapterInterface
    {
        return match (strtolower($code)) {
            'grabfood'   => new GrabFoodAdapter($credentials),
            'foodpanda'  => new FoodpandaAdapter($credentials),
            'shopeefood' => new ShopeeFoodAdapter($credentials),
            default      => new MockAdapter($credentials, $code),
        };
    }

    /**
     * Persist an order through the same path as the CSV importer (resolve
     * outlet by code, run Fee + Tax engines, write sales_orders + sales_fee_calculations).
     * Returns true if a new order was written, false if it was a duplicate.
     */
    private static function persistOrder(int $companyId, int $platformId, array $o): bool
    {
        $pdo = \db();

        $dup = $pdo->prepare('SELECT 1 FROM sales_orders WHERE company_id = ? AND order_id = ? LIMIT 1');
        $dup->execute([$companyId, (string)$o['order_id']]);
        if ($dup->fetchColumn()) return false;

        $outletCode = strtolower(trim((string)($o['outlet_code'] ?? '')));
        $oStmt = $pdo->prepare('SELECT id FROM outlets WHERE company_id = ? AND LOWER(code) = ? LIMIT 1');
        $oStmt->execute([$companyId, $outletCode]);
        $outletId = (int)$oStmt->fetchColumn();
        if (!$outletId) return false;

        $orderDate = (string)($o['order_date'] ?? date('Y-m-d'));
        $gross = (float)($o['gross_sales'] ?? 0);

        $rule = FeeEngine::resolveRule($platformId, $orderDate);
        $taxRule = TaxEngine::resolveRule($outletId, $platformId, $orderDate);
        $taxResult = $taxRule
            ? TaxEngine::calculate($gross, $taxRule)
            : ['tax' => (float)($o['sst_amount'] ?? 0), 'taxable' => $gross, 'rate' => 0.0, 'inclusive' => false];

        $sys = FeeEngine::calculate([
            'gross_sales'  => $gross,
            'voucher'      => (float)($o['voucher']      ?? 0),
            'discount'     => (float)($o['discount']     ?? 0),
            'refund'       => (float)($o['refund']       ?? 0),
            'delivery_fee' => (float)($o['delivery_fee'] ?? 0),
            'adjustment'   => (float)($o['adjustment']   ?? 0),
            'sst_amount'   => $taxResult['tax'],
        ], $rule, $taxResult);

        $rec = FeeEngine::reconcile(
            (float)($o['net_settlement'] ?? 0), $sys['net_settlement'],
            (float)($o['sst_amount'] ?? 0), $sys['tax_amount']);

        $pdo->prepare('
            INSERT INTO sales_orders
                (company_id, batch_id, platform_id, outlet_id, order_id, order_date,
                 gross_sales, item_subtotal, service_charge, sst_amount,
                 discount, voucher, refund, platform_commission, payment_fee,
                 delivery_fee, adjustment, net_settlement_imported,
                 settlement_date, bank_reference, created_at)
            VALUES (?,NULL,?,?,?,?, ?,?,?,?, ?,?,?,?,?, ?,?,?, ?,?,?)
        ')->execute([
            $companyId, $platformId, $outletId, (string)$o['order_id'], $orderDate,
            $gross,
            (float)($o['item_subtotal']       ?? 0),
            (float)($o['service_charge']      ?? 0),
            (float)($o['sst_amount']          ?? 0),
            (float)($o['discount']            ?? 0),
            (float)($o['voucher']             ?? 0),
            (float)($o['refund']              ?? 0),
            (float)($o['platform_commission'] ?? 0),
            (float)($o['payment_fee']         ?? 0),
            (float)($o['delivery_fee']        ?? 0),
            (float)($o['adjustment']          ?? 0),
            (float)($o['net_settlement']      ?? 0),
            $o['settlement_date'] ?? null,
            $o['bank_reference']  ?? null,
            \nowDb(),
        ]);
        $orderRowId = (int)$pdo->lastInsertId();

        $pdo->prepare('
            INSERT INTO sales_fee_calculations
                (sales_order_id, gross_sales, commission_amount, payment_fee_amount, service_fee_amount,
                 voucher_cost, promotion_cost, refund_amount, delivery_subsidy,
                 adjustment_amount, tax_amount, net_settlement_system,
                 net_settlement_imported, difference, variance_pct,
                 reconciliation_status, created_at)
            VALUES (?,?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?,?,?)
        ')->execute([
            $orderRowId,
            $sys['gross_sales'], $sys['commission_amount'], $sys['payment_fee_amount'], $sys['service_fee_amount'],
            $sys['voucher_cost'], $sys['promotion_cost'], $sys['refund_amount'], $sys['delivery_subsidy'],
            $sys['adjustment_amount'], $sys['tax_amount'], $sys['net_settlement'],
            (float)($o['net_settlement'] ?? 0), $rec['difference'], $rec['variance_pct'],
            $rec['status'], \nowDb(),
        ]);
        return true;
    }
}
