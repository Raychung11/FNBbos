<?php
// SLV WMS — /api/v1/scan/pick_list_list.php
// GET — list pick lists the user can act on (DRAFT, ASSIGNED, IN_PROGRESS).
// Query params: warehouse_id (optional)
// Roles allowed: super_admin, warehouse_manager, picker, packer
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/_common.php';
api_scan_boot(['super_admin','warehouse_manager','picker','packer']);

$wid = isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '' ? (int)$_GET['warehouse_id'] : null;
$accessible = user_warehouse_ids();
$role       = current_user()['role'] ?? '';

$where  = ['pl.company_id = ?', "pl.status IN ('DRAFT','ASSIGNED','IN_PROGRESS')"];
$params = [company_id()];

if ($wid !== null) {
    api_scan_require_warehouse($wid);
    $where[]  = 'pl.warehouse_id = ?';
    $params[] = $wid;
} elseif ($role !== 'super_admin') {
    if (!$accessible) json_ok(['pick_lists' => []]);
    $where[]  = 'pl.warehouse_id IN (' . implode(',', array_fill(0, count($accessible), '?')) . ')';
    $params   = array_merge($params, $accessible);
}

$sql = "SELECT pl.id, pl.pick_no, pl.status, pl.warehouse_id,
               w.code AS warehouse_code,
               so.so_no,
               c.code AS customer_code, c.name AS customer_name,
               (SELECT COUNT(*)                    FROM pick_items pi WHERE pi.pick_list_id = pl.id) AS line_count,
               (SELECT COALESCE(SUM(qty_to_pick),0) FROM pick_items pi WHERE pi.pick_list_id = pl.id) AS qty_total,
               (SELECT COALESCE(SUM(qty_picked),0)  FROM pick_items pi WHERE pi.pick_list_id = pl.id) AS qty_done
          FROM pick_lists pl
          JOIN warehouses w   ON w.id = pl.warehouse_id
          JOIN sales_orders so ON so.id = pl.so_id
          JOIN customers   c  ON c.id = so.customer_id
         WHERE " . implode(' AND ', $where) . "
      ORDER BY pl.id DESC LIMIT 50";
$stmt = db()->prepare($sql);
$stmt->execute($params);

$out = [];
foreach ($stmt->fetchAll() as $r) {
    $out[] = [
        'id'             => (int)$r['id'],
        'pick_no'        => $r['pick_no'],
        'status'         => $r['status'],
        'warehouse_id'   => (int)$r['warehouse_id'],
        'warehouse_code' => $r['warehouse_code'],
        'so_no'          => $r['so_no'],
        'customer_code'  => $r['customer_code'],
        'customer_name'  => $r['customer_name'],
        'line_count'     => (int)$r['line_count'],
        'qty_total'      => (float)$r['qty_total'],
        'qty_done'       => (float)$r['qty_done'],
    ];
}
json_ok(['pick_lists' => $out]);
