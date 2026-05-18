<?php
// SLV WMS — /api/v1/scan/grn_putaway.php
// POST — execute a putaway from a GRN line into a bin. Wraps
//        grn_putaway_execute() (lib/grn.php) which in turn calls
//        record_putaway() in lib/stock.php. Idempotent on scan_uuid.
//
// Body (JSON or form):
//   grn_item_id  (required)
//   bin_id       (required)
//   qty          (required, > 0)
//   unit_cost    (optional — falls back to the line's unit_cost)
//   scan_uuid    (optional — recommended; client-generated UUID v4)
//
// Roles allowed: super_admin, warehouse_manager, receiver
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/_common.php';
$body = api_scan_boot(['super_admin','warehouse_manager','receiver']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST only.');

$line_id  = (int)($body['grn_item_id'] ?? 0);
$bin_id   = (int)($body['bin_id']      ?? 0);
$qtyRaw   = $body['qty']               ?? null;
$cstRaw   = $body['unit_cost']         ?? null;
$scanUuid = $body['scan_uuid']         ?? null;

if ($line_id <= 0 || $bin_id <= 0)                json_error(400, 'grn_item_id and bin_id are required.');
if (!is_numeric($qtyRaw) || (float)$qtyRaw <= 0)  json_error(400, 'qty must be > 0.');
$qty  = (float)$qtyRaw;
$cost = ($cstRaw === null || $cstRaw === '') ? null : (float)$cstRaw;
if ($scanUuid !== null) {
    $scanUuid = trim((string)$scanUuid);
    if ($scanUuid === '' || strlen($scanUuid) > 36) json_error(400, 'scan_uuid is malformed.');
}

// Warehouse-access guard against the line's parent GRN.
$stmt = db()->prepare(
    'SELECT g.warehouse_id FROM grn_items gi
       JOIN grn g ON g.id = gi.grn_id
      WHERE gi.id = ? AND g.company_id = ?'
);
$stmt->execute([$line_id, company_id()]);
$wid = (int)($stmt->fetchColumn() ?: 0);
if ($wid <= 0) json_error(404, 'GRN line not found.');
require_warehouse_access($wid);

try {
    $putaway_id = grn_putaway_execute(
        $line_id, $bin_id, $qty, $cost, $scanUuid,
        (int)(current_user()['id'] ?? 0) ?: null
    );

    // Re-read line totals + parent status so the client can refresh.
    $r = db()->prepare(
        "SELECT gi.qty_received, gi.qty_putaway, g.id AS grn_id, g.status
           FROM grn_items gi JOIN grn g ON g.id = gi.grn_id
          WHERE gi.id = ?"
    );
    $r->execute([$line_id]);
    $line = $r->fetch();

    json_ok([
        'putaway_id'    => $putaway_id,
        'grn_id'        => (int)$line['grn_id'],
        'grn_status'    => $line['status'],
        'qty_received'  => (float)$line['qty_received'],
        'qty_putaway'   => (float)$line['qty_putaway'],
        'qty_remaining' => round((float)$line['qty_received'] - (float)$line['qty_putaway'], 4),
    ]);
} catch (Throwable $e) {
    json_error(400, $e->getMessage());
}
