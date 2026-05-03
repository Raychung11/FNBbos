<?php
// SLV WMS — /api/v1/scan/grn_get.php
// GET — full state of one GRN with its lines, plus a putaway suggestion
//        per line that still has remaining qty.
// Query params: id (required)
// Roles allowed: super_admin, warehouse_manager, receiver
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/_common.php';
api_scan_boot(['super_admin','warehouse_manager','receiver']);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) json_error(400, 'id is required.');

$stmt = db()->prepare(
    'SELECT g.*, w.code AS warehouse_code, w.name AS warehouse_name,
            s.code AS supplier_code, s.name AS supplier_name
       FROM grn g
       JOIN warehouses w ON w.id = g.warehouse_id
  LEFT JOIN suppliers  s ON s.id = g.supplier_id
      WHERE g.id = ? AND g.company_id = ?'
);
$stmt->execute([$id, company_id()]);
$grn = $stmt->fetch();
if (!$grn) json_error(404, 'GRN not found.');
require_warehouse_access((int)$grn['warehouse_id']);

$lstmt = db()->prepare(
    "SELECT gi.id, gi.product_id, gi.qty_expected, gi.qty_received,
            gi.qty_putaway, gi.unit_cost, gi.notes,
            p.sku_code, p.name AS product_name, p.uom,
            (SELECT pb.barcode FROM product_barcodes pb
              WHERE pb.product_id = gi.product_id AND pb.is_primary = 1
              LIMIT 1) AS primary_barcode
       FROM grn_items gi
       JOIN products  p ON p.id = gi.product_id
      WHERE gi.grn_id = ?
   ORDER BY gi.id"
);
$lstmt->execute([$id]);
$lines = [];
foreach ($lstmt->fetchAll() as $l) {
    $remainingPutaway = (float)$l['qty_received'] - (float)$l['qty_putaway'];
    $suggested = $remainingPutaway > 0
        ? grn_suggest_bin((int)$l['product_id'], (int)$grn['warehouse_id'], $remainingPutaway)
        : null;
    $lines[] = [
        'id'              => (int)$l['id'],
        'product_id'      => (int)$l['product_id'],
        'sku_code'        => $l['sku_code'],
        'product_name'    => $l['product_name'],
        'uom'             => $l['uom'],
        'primary_barcode' => $l['primary_barcode'],
        'qty_expected'    => (float)$l['qty_expected'],
        'qty_received'    => (float)$l['qty_received'],
        'qty_putaway'     => (float)$l['qty_putaway'],
        'remaining_putaway' => round($remainingPutaway, 4),
        'unit_cost'       => (float)$l['unit_cost'],
        'notes'           => $l['notes'],
        'suggested_bin'   => $suggested,
    ];
}

json_ok([
    'grn' => [
        'id'             => (int)$grn['id'],
        'grn_no'         => $grn['grn_no'],
        'status'         => $grn['status'],
        'warehouse_id'   => (int)$grn['warehouse_id'],
        'warehouse_code' => $grn['warehouse_code'],
        'warehouse_name' => $grn['warehouse_name'],
        'supplier_code'  => $grn['supplier_code'],
        'supplier_name'  => $grn['supplier_name'],
        'ref_po'         => $grn['ref_po'],
        'total_value'    => (float)$grn['total_value'],
    ],
    'lines' => $lines,
]);
