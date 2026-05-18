<?php
// SLV WMS — /api/v1/scan/grn_list.php
// GET — list GRNs the user can act on, optionally filtered to one mode.
// Query params:
//   warehouse_id   optional; defaults to all warehouses the user has access to.
//   mode           one of 'receive' (DRAFT/RECEIVING/RECEIVED) or
//                  'putaway' (RECEIVED/PUTAWAY). Default: receive.
// Roles allowed: super_admin, warehouse_manager, receiver
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/_common.php';
api_scan_boot(['super_admin','warehouse_manager','receiver']);

$mode = (string)($_GET['mode'] ?? 'receive');
$wid  = isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '' ? (int)$_GET['warehouse_id'] : null;

$statuses = $mode === 'putaway'
    ? ['RECEIVED', 'PUTAWAY']
    : ['DRAFT', 'RECEIVING', 'RECEIVED'];

$accessible = user_warehouse_ids();
$role       = current_user()['role'] ?? '';

$where  = ['g.company_id = ?'];
$params = [company_id()];
$where[] = 'g.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
$params = array_merge($params, $statuses);

if ($wid !== null) {
    api_scan_require_warehouse($wid);
    $where[] = 'g.warehouse_id = ?';
    $params[] = $wid;
} elseif ($role !== 'super_admin') {
    if (!$accessible) json_ok(['grns' => []]);
    $where[]  = 'g.warehouse_id IN (' . implode(',', array_fill(0, count($accessible), '?')) . ')';
    $params   = array_merge($params, $accessible);
}

$sql = "SELECT g.id, g.grn_no, g.status, g.warehouse_id,
               w.code AS warehouse_code,
               s.code AS supplier_code, s.name AS supplier_name,
               (SELECT COUNT(*) FROM grn_items gi WHERE gi.grn_id = g.id) AS line_count,
               (SELECT COALESCE(SUM(gi.qty_expected), 0)
                  FROM grn_items gi WHERE gi.grn_id = g.id) AS qty_expected,
               (SELECT COALESCE(SUM(gi.qty_received), 0)
                  FROM grn_items gi WHERE gi.grn_id = g.id) AS qty_received,
               (SELECT COALESCE(SUM(gi.qty_putaway), 0)
                  FROM grn_items gi WHERE gi.grn_id = g.id) AS qty_putaway
          FROM grn g
          JOIN warehouses w ON w.id = g.warehouse_id
     LEFT JOIN suppliers s  ON s.id = g.supplier_id
         WHERE " . implode(' AND ', $where) . "
      ORDER BY g.id DESC
         LIMIT 50";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id'              => (int)$r['id'],
        'grn_no'          => $r['grn_no'],
        'status'          => $r['status'],
        'warehouse_id'    => (int)$r['warehouse_id'],
        'warehouse_code'  => $r['warehouse_code'],
        'supplier_code'   => $r['supplier_code'],
        'supplier_name'   => $r['supplier_name'],
        'line_count'      => (int)$r['line_count'],
        'qty_expected'    => (float)$r['qty_expected'],
        'qty_received'    => (float)$r['qty_received'],
        'qty_putaway'     => (float)$r['qty_putaway'],
    ];
}
json_ok(['grns' => $out]);
