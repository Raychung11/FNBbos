<?php
declare(strict_types=1);

namespace FNBBOS\Engine;

use FNBBOS\Auth;
use FNBBOS\AuditLog;

/**
 * CSV importer for sales orders. Validates each row against the rules in the
 * spec (duplicate order ID, missing outlet/platform, invalid amount, negative
 * settlement, SST mismatch, fee mismatch) before persisting. A batch row is
 * created in sales_import_batches and every row's calculation snapshot is
 * stored in sales_fee_calculations.
 */
final class Importer
{
    /** @var string[] */
    public const REQUIRED_COLUMNS = [
        'platform_code', 'outlet_code', 'order_id', 'order_date', 'gross_sales',
    ];

    public const OPTIONAL_COLUMNS = [
        'item_subtotal', 'service_charge', 'sst_amount', 'discount', 'voucher',
        'refund', 'platform_commission', 'payment_fee', 'delivery_fee',
        'adjustment', 'net_settlement', 'settlement_date', 'bank_reference',
    ];

    public static function ingestCsv(string $filePath, int $companyId, int $userId): array
    {
        if (!is_readable($filePath)) {
            throw new \RuntimeException('Uploaded file is not readable.');
        }
        $fh = fopen($filePath, 'r');
        if (!$fh) throw new \RuntimeException('Cannot open uploaded file.');

        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh);
            throw new \RuntimeException('CSV is empty.');
        }
        $header = array_map(static fn($h) => strtolower(trim((string)$h)), $header);

        foreach (self::REQUIRED_COLUMNS as $col) {
            if (!in_array($col, $header, true)) {
                fclose($fh);
                throw new \RuntimeException('Missing required column: ' . $col);
            }
        }

        $pdo = \db();
        $pdo->beginTransaction();
        try {
            $batchStmt = $pdo->prepare('
                INSERT INTO sales_import_batches
                  (company_id, uploaded_by, file_name, total_rows, accepted_rows, rejected_rows, status, created_at)
                VALUES (?,?,?,?,?,?, ?, ?)');
            $batchStmt->execute([$companyId, $userId, basename($filePath), 0, 0, 0, 'processing', \nowDb()]);
            $batchId = (int)$pdo->lastInsertId();

            $orderStmt = $pdo->prepare('
                INSERT INTO sales_orders
                    (company_id, batch_id, platform_id, outlet_id, order_id, order_date,
                     gross_sales, item_subtotal, service_charge, sst_amount,
                     discount, voucher, refund, platform_commission, payment_fee,
                     delivery_fee, adjustment, net_settlement_imported,
                     settlement_date, bank_reference, created_at)
                VALUES
                    (?,?,?,?,?,?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

            $calcStmt = $pdo->prepare('
                INSERT INTO sales_fee_calculations
                    (sales_order_id,
                     gross_sales, commission_amount, payment_fee_amount, service_fee_amount,
                     voucher_cost, promotion_cost, refund_amount, delivery_subsidy,
                     adjustment_amount, tax_amount, net_settlement_system,
                     net_settlement_imported, difference, variance_pct,
                     reconciliation_status, created_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

            $errStmt = $pdo->prepare('
                INSERT INTO sales_import_errors
                    (batch_id, row_number, order_id, error_code, message, created_at)
                VALUES (?,?,?,?,?,?)');

            $platformLookup = self::buildLookup('platforms', 'code', 'id', $companyId);
            $outletLookup   = self::buildLookup('outlets',   'code', 'id', $companyId);

            $accepted = 0; $rejected = 0; $rowNum = 1;
            $errors = [];
            while (($row = fgetcsv($fh)) !== false) {
                $rowNum++;
                if (count($row) === 1 && trim((string)$row[0]) === '') continue;
                $data = array_combine($header, array_pad($row, count($header), ''));
                if ($data === false) continue;

                $orderId = trim((string)$data['order_id']);
                $errors_for_row = [];

                $platformId = $platformLookup[strtolower(trim((string)$data['platform_code']))] ?? null;
                $outletId   = $outletLookup[strtolower(trim((string)$data['outlet_code']))]     ?? null;
                if (!$platformId)  $errors_for_row[] = ['missing_platform', 'Unknown platform code: ' . $data['platform_code']];
                if (!$outletId)    $errors_for_row[] = ['missing_outlet',   'Unknown outlet code: '   . $data['outlet_code']];
                if ($orderId === '') $errors_for_row[] = ['missing_order_id','Order ID is empty'];

                $gross = \asFloat($data['gross_sales'] ?? 0);
                if ($gross < 0) $errors_for_row[] = ['invalid_amount', 'Gross sales is negative'];

                $netImported = \asFloat($data['net_settlement'] ?? 0);
                if ($netImported < 0) $errors_for_row[] = ['negative_settlement', 'Net settlement is negative'];

                if ($orderId !== '') {
                    $dup = $pdo->prepare('SELECT 1 FROM sales_orders WHERE company_id = ? AND order_id = ? LIMIT 1');
                    $dup->execute([$companyId, $orderId]);
                    if ($dup->fetchColumn()) $errors_for_row[] = ['duplicate_order_id', 'Duplicate order ID'];
                }

                if ($errors_for_row) {
                    foreach ($errors_for_row as [$code, $msg]) {
                        $errStmt->execute([$batchId, $rowNum, $orderId, $code, $msg, \nowDb()]);
                        $errors[] = ['row' => $rowNum, 'order_id' => $orderId, 'code' => $code, 'message' => $msg];
                    }
                    $rejected++;
                    continue;
                }

                $orderDate = (string)$data['order_date'];
                $settleDate = !empty($data['settlement_date']) ? (string)$data['settlement_date'] : null;

                $orderStmt->execute([
                    $companyId, $batchId, $platformId, $outletId, $orderId, $orderDate,
                    $gross,
                    \asFloat($data['item_subtotal']        ?? 0),
                    \asFloat($data['service_charge']       ?? 0),
                    \asFloat($data['sst_amount']           ?? 0),
                    \asFloat($data['discount']             ?? 0),
                    \asFloat($data['voucher']              ?? 0),
                    \asFloat($data['refund']               ?? 0),
                    \asFloat($data['platform_commission']  ?? 0),
                    \asFloat($data['payment_fee']          ?? 0),
                    \asFloat($data['delivery_fee']         ?? 0),
                    \asFloat($data['adjustment']           ?? 0),
                    $netImported,
                    $settleDate,
                    $data['bank_reference'] ?? null,
                    \nowDb(),
                ]);
                $orderRowId = (int)$pdo->lastInsertId();

                // Run engines
                $rule = FeeEngine::resolveRule((int)$platformId, $orderDate);
                $taxRule = TaxEngine::resolveRule((int)$outletId, (int)$platformId, $orderDate);
                $taxResult = $taxRule
                    ? TaxEngine::calculate($gross, $taxRule)
                    : ['tax' => \asFloat($data['sst_amount'] ?? 0), 'taxable' => $gross, 'rate' => 0.0, 'inclusive' => false];

                $sys = FeeEngine::calculate([
                    'gross_sales'  => $gross,
                    'voucher'      => \asFloat($data['voucher']      ?? 0),
                    'discount'     => \asFloat($data['discount']     ?? 0),
                    'refund'       => \asFloat($data['refund']       ?? 0),
                    'delivery_fee' => \asFloat($data['delivery_fee'] ?? 0),
                    'adjustment'   => \asFloat($data['adjustment']   ?? 0),
                    'sst_amount'   => $taxResult['tax'],
                ], $rule, $taxResult);

                $rec = FeeEngine::reconcile(
                    $netImported, $sys['net_settlement'],
                    \asFloat($data['sst_amount'] ?? 0), $sys['tax_amount']);

                $calcStmt->execute([
                    $orderRowId,
                    $sys['gross_sales'], $sys['commission_amount'], $sys['payment_fee_amount'], $sys['service_fee_amount'],
                    $sys['voucher_cost'], $sys['promotion_cost'], $sys['refund_amount'], $sys['delivery_subsidy'],
                    $sys['adjustment_amount'], $sys['tax_amount'], $sys['net_settlement'],
                    $netImported, $rec['difference'], $rec['variance_pct'],
                    $rec['status'], \nowDb(),
                ]);
                $accepted++;
            }
            fclose($fh);

            $pdo->prepare('UPDATE sales_import_batches SET total_rows=?, accepted_rows=?, rejected_rows=?, status=? WHERE id=?')
                ->execute([$rowNum - 1, $accepted, $rejected, 'completed', $batchId]);

            AuditLog::record('sales.import', 'sales_import_batch', $batchId, [
                'file' => basename($filePath), 'accepted' => $accepted, 'rejected' => $rejected,
            ]);
            $pdo->commit();
            return ['batch_id' => $batchId, 'total' => $rowNum - 1, 'accepted' => $accepted, 'rejected' => $rejected, 'errors' => $errors];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function buildLookup(string $table, string $keyCol, string $valCol, int $companyId): array
    {
        $stmt = \db()->prepare("SELECT {$keyCol}, {$valCol} FROM {$table} WHERE company_id = ? OR company_id IS NULL");
        $stmt->execute([$companyId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[strtolower((string)$r[$keyCol])] = (int)$r[$valCol];
        }
        return $out;
    }
}
