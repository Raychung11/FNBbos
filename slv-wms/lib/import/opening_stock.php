<?php
// SLV WMS — lib/import/opening_stock.php
// Importer for: opening stock layers (Phase 3 cut-over).
// CSV columns:
//   warehouse_code, sku_code, bin_code, qty, unit_cost, received_at
// Required: warehouse_code, sku_code, bin_code, qty, unit_cost.
// `bin_code` may be either the bin's short code (last segment) or its
//  full_code (e.g. WH01/Z-A/R01/B03). The latter is preferred because
//  short codes can repeat across racks.
// `received_at` is optional; defaults to the import job's created_at-ish
//  (NOW()). Use the same date across an import to make FIFO order
//  deterministic.

declare(strict_types=1);

function csv_import_opening_stock_required_headers(): array
{
    return ['warehouse_code', 'sku_code', 'bin_code', 'qty', 'unit_cost'];
}

/**
 * @return array{ok:bool, error?:string}
 */
function csv_import_opening_stock_process_row(array $row, int $row_no, array $opts): array
{
    $whCode  = strtoupper(trim((string)($row['warehouse_code'] ?? '')));
    $sku     = strtoupper(trim((string)($row['sku_code']       ?? '')));
    $binCode = trim((string)($row['bin_code']                  ?? ''));
    $qtyRaw  = trim((string)($row['qty']                       ?? ''));
    $costRaw = trim((string)($row['unit_cost']                 ?? ''));
    $rxAt    = trim((string)($row['received_at']               ?? ''));

    if ($whCode === '' || $sku === '' || $binCode === '') {
        return ['ok' => false, 'error' => 'warehouse_code, sku_code, bin_code are required'];
    }
    if (!is_numeric($qtyRaw)  || (float)$qtyRaw  <= 0) return ['ok' => false, 'error' => 'qty must be > 0'];
    if (!is_numeric($costRaw) || (float)$costRaw <  0) return ['ok' => false, 'error' => 'unit_cost must be >= 0'];

    if ($rxAt !== '') {
        $ts = strtotime($rxAt);
        if ($ts === false) return ['ok' => false, 'error' => "received_at '$rxAt' not a valid date/time"];
        $rxAt = date('Y-m-d H:i:s', $ts);
    } else {
        $rxAt = null;
    }

    // Look ups (do not run inside the FIFO transaction; cheap reads).
    $w = db()->prepare('SELECT id FROM warehouses WHERE company_id = ? AND code = ? LIMIT 1');
    $w->execute([company_id(), $whCode]);
    $wid = $w->fetchColumn();
    if ($wid === false) return ['ok' => false, 'error' => "warehouse '$whCode' not found"];
    $wid = (int)$wid;

    $p = db()->prepare('SELECT id FROM products WHERE company_id = ? AND sku_code = ? LIMIT 1');
    $p->execute([company_id(), $sku]);
    $pid = $p->fetchColumn();
    if ($pid === false) return ['ok' => false, 'error' => "sku '$sku' not found"];
    $pid = (int)$pid;

    // Resolve bin: try full_code first (unambiguous), then last-segment within the warehouse.
    $bid = opening_stock_resolve_bin($wid, $binCode);
    if ($bid === null) return ['ok' => false, 'error' => "bin '$binCode' not found in $whCode"];

    try {
        record_putaway(
            company_id(), $wid, $pid, $bid,
            (float)$qtyRaw, (float)$costRaw,
            'OPENING',
            (int)($opts['job_id'] ?? 0) ?: null,
            (int)($opts['user_id'] ?? 0) ?: null,
            null,                  // no scan_uuid for CSV imports
            $rxAt,
            'CSV opening-stock row #' . $row_no
        );
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function opening_stock_resolve_bin(int $warehouse_id, string $binCode): ?int
{
    // Try full_code first (e.g. WH01/Z-A/R01/B03).
    $stmt = db()->prepare(
        'SELECT b.id FROM bins b
           JOIN racks r ON r.id = b.rack_id
           JOIN zones z ON z.id = r.zone_id
          WHERE z.warehouse_id = ? AND b.full_code = ?
          LIMIT 1'
    );
    $stmt->execute([$warehouse_id, $binCode]);
    $hit = $stmt->fetchColumn();
    if ($hit !== false) return (int)$hit;

    // Fall back to short code, but only if unambiguous within the warehouse.
    $stmt = db()->prepare(
        'SELECT b.id FROM bins b
           JOIN racks r ON r.id = b.rack_id
           JOIN zones z ON z.id = r.zone_id
          WHERE z.warehouse_id = ? AND b.code = ?
          LIMIT 2'
    );
    $stmt->execute([$warehouse_id, strtoupper($binCode)]);
    $hits = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($hits) === 1) return (int)$hits[0];

    return null;
}
