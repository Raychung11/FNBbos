<?php
// SLV WMS — /api/v1/scan/pick_list_get.php
// GET — full state of one pick list with its items in walk-path order.
//        Each item carries the suggested-bin info plus the SKU's primary
//        barcode so the phone client can pre-validate scans against the
//        expected line.
// Query params: id (required)
// Roles allowed: super_admin, warehouse_manager, picker, packer
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/_common.php';
api_scan_boot(['super_admin','warehouse_manager','picker','packer']);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) json_error(400, 'id is required.');

$stmt = db()->prepare(
    'SELECT pl.*, w.code AS warehouse_code, w.name AS warehouse_name,
            so.so_no,
            c.code AS customer_code, c.name AS customer_name
       FROM pick_lists pl
       JOIN warehouses w   ON w.id = pl.warehouse_id
       JOIN sales_orders so ON so.id = pl.so_id
       JOIN customers   c  ON c.id = so.customer_id
      WHERE pl.id = ? AND pl.company_id = ?'
);
$stmt->execute([$id, company_id()]);
$pl = $stmt->fetch();
if (!$pl) json_error(404, 'Pick list not found.');
require_warehouse_access((int)$pl['warehouse_id']);

$items = db()->prepare(
    'SELECT pi.id, pi.product_id, pi.suggested_bin_id,
            pi.qty_to_pick, pi.qty_picked, pi.picked_from_bin_id,
            pi.walk_order, pi.scan_uuid, pi.movement_id, pi.picked_at,
            p.sku_code, p.name AS product_name, p.uom,
            (SELECT pb.barcode FROM product_barcodes pb
              WHERE pb.product_id = pi.product_id AND pb.is_primary = 1
              LIMIT 1) AS primary_barcode,
            sb.full_code AS suggested_full,
            sb.code      AS suggested_code,
            ab.full_code AS picked_full
       FROM pick_items pi
       JOIN products p ON p.id = pi.product_id
  LEFT JOIN bins   sb ON sb.id = pi.suggested_bin_id
  LEFT JOIN bins   ab ON ab.id = pi.picked_from_bin_id
      WHERE pi.pick_list_id = ?
   ORDER BY pi.walk_order, pi.id'
);
$items->execute([$id]);

$lines = [];
foreach ($items->fetchAll() as $r) {
    $lines[] = [
        'id'                 => (int)$r['id'],
        'product_id'         => (int)$r['product_id'],
        'sku_code'           => $r['sku_code'],
        'product_name'       => $r['product_name'],
        'uom'                => $r['uom'],
        'primary_barcode'    => $r['primary_barcode'],
        'walk_order'         => (int)$r['walk_order'],
        'qty_to_pick'        => (float)$r['qty_to_pick'],
        'qty_picked'         => (float)$r['qty_picked'],
        'remaining'          => round((float)$r['qty_to_pick'] - (float)$r['qty_picked'], 4),
        'suggested_bin_id'   => $r['suggested_bin_id'] === null ? null : (int)$r['suggested_bin_id'],
        'suggested_full'     => $r['suggested_full'],
        'suggested_code'     => $r['suggested_code'],
        'picked_from_bin_id' => $r['picked_from_bin_id'] === null ? null : (int)$r['picked_from_bin_id'],
        'picked_full'        => $r['picked_full'],
        'picked_at'          => $r['picked_at'],
    ];
}

json_ok([
    'pick_list' => [
        'id'             => (int)$pl['id'],
        'pick_no'        => $pl['pick_no'],
        'status'         => $pl['status'],
        'warehouse_id'   => (int)$pl['warehouse_id'],
        'warehouse_code' => $pl['warehouse_code'],
        'warehouse_name' => $pl['warehouse_name'],
        'so_no'          => $pl['so_no'],
        'customer_code'  => $pl['customer_code'],
        'customer_name'  => $pl['customer_name'],
    ],
    'items' => $lines,
]);
