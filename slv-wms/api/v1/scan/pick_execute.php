<?php
// SLV WMS — /api/v1/scan/pick_execute.php
// POST — record one pick from a pick_item. Wraps lib/so.php → pick_execute()
//        which in turn calls record_issue(movement_type=PICK), so the FIFO
//        consume of stock_layers happens inside the same db_tx.
//
// Body (JSON or form):
//   pick_item_id  (required)
//   bin_id        (required) — the bin actually scanned; need not match
//                              suggested_bin_id (override is allowed)
//   qty           (required, > 0, <= remaining)
//   scan_uuid     (optional but recommended; UUID v4 from the client.
//                  Replaying the same uuid on the same line is a no-op.)
//
// Roles allowed: super_admin, warehouse_manager, picker, packer
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/_common.php';
$body = api_scan_boot(['super_admin','warehouse_manager','picker','packer']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST only.');

$pick_item_id = (int)($body['pick_item_id'] ?? 0);
$bin_id       = (int)($body['bin_id']       ?? 0);
$qtyRaw       = $body['qty']                ?? null;
$scanUuid     = $body['scan_uuid']          ?? null;

if ($pick_item_id <= 0 || $bin_id <= 0)            json_error(400, 'pick_item_id and bin_id are required.');
if (!is_numeric($qtyRaw) || (float)$qtyRaw <= 0)   json_error(400, 'qty must be > 0.');
$qty = (float)$qtyRaw;
if ($scanUuid !== null) {
    $scanUuid = trim((string)$scanUuid);
    if ($scanUuid !== '' && strlen($scanUuid) > 36) json_error(400, 'scan_uuid is malformed.');
    if ($scanUuid === '') $scanUuid = null;
}

// Cross-check: the SKU at the scanned bin must match the pick line's product.
// pick_execute() already enforces qty + warehouse access; this extra check
// gives the client a clearer error before any stock writes.
$check = db()->prepare(
    'SELECT pi.product_id,
            (SELECT 1 FROM stock_layers
              WHERE product_id = pi.product_id AND bin_id = ?
                AND status = "ACTIVE" AND qty_remaining > 0
              LIMIT 1) AS has_stock
       FROM pick_items pi WHERE pi.id = ?'
);
$check->execute([$bin_id, $pick_item_id]);
$row = $check->fetch();
if (!$row)            json_error(404, 'Pick item not found.');
if (!$row['has_stock']) json_error(409, 'No active stock for this SKU in that bin.');

try {
    $res = pick_execute(
        $pick_item_id, $bin_id, $qty, $scanUuid,
        (int)(current_user()['id'] ?? 0) ?: null
    );

    // Re-read the line + parent so the client UI can refresh in one round-trip.
    $get = db()->prepare(
        'SELECT pi.qty_picked, pi.qty_to_pick,
                pl.id AS pick_list_id, pl.status AS pick_list_status,
                pl.so_id, so.status AS so_status
           FROM pick_items pi
           JOIN pick_lists pl ON pl.id = pi.pick_list_id
           JOIN sales_orders so ON so.id = pl.so_id
          WHERE pi.id = ?'
    );
    $get->execute([$pick_item_id]);
    $info = $get->fetch();

    json_ok([
        'movement_id'      => $res['movement_id'],
        'pick_item_id'     => $pick_item_id,
        'qty_picked'       => (float)$info['qty_picked'],
        'qty_to_pick'      => (float)$info['qty_to_pick'],
        'remaining'        => round((float)$info['qty_to_pick'] - (float)$info['qty_picked'], 4),
        'pick_list_id'     => (int)$info['pick_list_id'],
        'pick_list_status' => (string)$info['pick_list_status'],
        'so_status'        => (string)$info['so_status'],
        'already'          => (bool)($res['already'] ?? false),
    ]);
} catch (Throwable $e) {
    json_error(400, $e->getMessage());
}
