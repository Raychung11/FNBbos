<?php
// SLV WMS — pages/reports/stock_movements.php
// Purpose: Append-only ledger view, filterable by warehouse / SKU /
//          movement type / date range / user.
// Roles allowed: super_admin, warehouse_manager, sales (RO), viewer (RO)
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','sales','viewer']);

$role          = current_user()['role'] ?? '';
$accessibleIds = user_warehouse_ids();

// Filters.
$wid       = isset($_GET['warehouse_id'])  && $_GET['warehouse_id']  !== '' ? (int)$_GET['warehouse_id'] : null;
$pid       = isset($_GET['product_id'])    && $_GET['product_id']    !== '' ? (int)$_GET['product_id']   : null;
$type      = (string)($_GET['movement_type']  ?? '');
$dateFrom  = (string)($_GET['date_from']      ?? '');
$dateTo    = (string)($_GET['date_to']        ?? '');

if ($wid !== null && $role !== 'super_admin' && !in_array($wid, $accessibleIds, true)) {
    flash('error', 'No access to that warehouse.');
    redirect('/pages/reports/stock_movements.php');
}

$where  = ['m.company_id = ?'];
$params = [company_id()];

// Constrain to the user's accessible warehouses (super_admin sees all).
if ($role !== 'super_admin') {
    if (!$accessibleIds) {
        $where[] = '1 = 0';
    } else {
        $where[]  = 'm.warehouse_id IN (' . implode(',', array_fill(0, count($accessibleIds), '?')) . ')';
        $params   = array_merge($params, $accessibleIds);
    }
}

if ($wid !== null) { $where[] = 'm.warehouse_id = ?'; $params[] = $wid; }
if ($pid !== null) { $where[] = 'm.product_id   = ?'; $params[] = $pid; }
$validTypes = ['PUTAWAY','PICK','TRANSFER_OUT','TRANSFER_IN','IW_DISPATCH',
               'IW_RECEIVE','ADJUST_PLUS','ADJUST_MINUS','COUNT_PLUS','COUNT_MINUS',
               'RETURN_IN','OPENING'];
if ($type !== '' && in_array($type, $validTypes, true)) {
    $where[] = 'm.movement_type = ?';
    $params[] = $type;
}
if ($dateFrom !== '') {
    $ts = strtotime($dateFrom);
    if ($ts !== false) { $where[] = 'm.created_at >= ?'; $params[] = date('Y-m-d 00:00:00', $ts); }
}
if ($dateTo !== '') {
    $ts = strtotime($dateTo);
    if ($ts !== false) { $where[] = 'm.created_at <= ?'; $params[] = date('Y-m-d 23:59:59', $ts); }
}

$sql = "SELECT m.id, m.created_at, m.movement_type, m.qty_delta,
               m.ref_type, m.ref_id, m.notes,
               p.sku_code, p.name AS product_name,
               w.code AS warehouse_code,
               b.full_code AS bin_code,
               u.name AS user_name
          FROM stock_movements m
          JOIN products   p ON p.id = m.product_id
          JOIN warehouses w ON w.id = m.warehouse_id
          JOIN bins       b ON b.id = m.bin_id
     LEFT JOIN users      u ON u.id = m.user_id
         WHERE " . implode(' AND ', $where) . "
      ORDER BY m.id DESC
         LIMIT 500";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Dropdowns.
$prodStmt = db()->prepare(
    "SELECT id, sku_code, name FROM products
      WHERE company_id = ? ORDER BY sku_code"
);
$prodStmt->execute([company_id()]);
$products = $prodStmt->fetchAll();

$whWhere  = ['company_id = ?'];
$whParams = [company_id()];
if ($role !== 'super_admin' && $accessibleIds) {
    $whWhere[]  = 'id IN (' . implode(',', array_fill(0, count($accessibleIds), '?')) . ')';
    $whParams   = array_merge($whParams, $accessibleIds);
}
$whStmt = db()->prepare('SELECT id, code FROM warehouses WHERE ' . implode(' AND ', $whWhere) . ' ORDER BY code');
$whStmt->execute($whParams);
$warehouses = $whStmt->fetchAll();

$PAGE_TITLE = 'Stock movements';
require __DIR__ . '/../../partials/header.php';
?>
<div class="mb-6">
  <a href="/pages/reports/index.php" class="text-sm text-gray-500 hover:text-gray-900">&larr; Reports</a>
  <h1 class="text-2xl font-semibold text-gray-900 mt-1">Stock movements ledger</h1>
  <p class="text-sm text-gray-500 mt-1">
    Append-only. Each row is a single movement; PICK / ADJUST_MINUS / IW_DISPATCH
    rows have one or more <code>stock_layer_movements</code> children that
    record exactly which FIFO layers were consumed.
  </p>
</div>

<form method="get" class="bg-white border border-gray-200 rounded-lg p-3 mb-4 grid grid-cols-2 sm:grid-cols-6 gap-3 text-sm">
  <div>
    <label class="block text-xs font-medium text-gray-500">Warehouse</label>
    <select name="warehouse_id" class="mt-1 w-full rounded border-gray-300 shadow-sm">
      <option value="">All</option>
      <?php foreach ($warehouses as $w): ?>
        <option value="<?= e_($w['id']) ?>" <?= $wid === (int)$w['id'] ? 'selected' : '' ?>><?= e_($w['code']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-gray-500">SKU</label>
    <select name="product_id" class="mt-1 w-full rounded border-gray-300 shadow-sm">
      <option value="">All</option>
      <?php foreach ($products as $p): ?>
        <option value="<?= e_($p['id']) ?>" <?= $pid === (int)$p['id'] ? 'selected' : '' ?>><?= e_($p['sku_code']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-gray-500">Type</label>
    <select name="movement_type" class="mt-1 w-full rounded border-gray-300 shadow-sm">
      <option value="">All</option>
      <?php foreach ($validTypes as $t): ?>
        <option value="<?= e_($t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= e_($t) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-gray-500">From</label>
    <input type="date" name="date_from" value="<?= e_($dateFrom) ?>" class="mt-1 w-full rounded border-gray-300 shadow-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-gray-500">To</label>
    <input type="date" name="date_to"   value="<?= e_($dateTo) ?>"   class="mt-1 w-full rounded border-gray-300 shadow-sm">
  </div>
  <div class="flex items-end gap-2">
    <button class="slv-bg-primary text-white px-3 py-2 rounded">Filter</button>
    <a href="/pages/reports/stock_movements.php" class="px-3 py-2 text-gray-600 hover:text-gray-900">Reset</a>
  </div>
</form>

<div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 text-gray-600">
      <tr>
        <th class="text-left px-4 py-2 font-medium">When</th>
        <th class="text-left px-4 py-2 font-medium">Type</th>
        <th class="text-left px-4 py-2 font-medium">SKU</th>
        <th class="text-left px-4 py-2 font-medium">WH</th>
        <th class="text-left px-4 py-2 font-medium">Bin</th>
        <th class="text-right px-4 py-2 font-medium">Δqty</th>
        <th class="text-left px-4 py-2 font-medium">Ref</th>
        <th class="text-left px-4 py-2 font-medium">User</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No movements match your filters.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r):
        $isPos  = (float)$r['qty_delta'] >= 0;
      ?>
        <tr>
          <td class="px-4 py-2 text-xs text-gray-600 whitespace-nowrap"><?= e_($r['created_at']) ?></td>
          <td class="px-4 py-2 font-mono text-xs"><?= e_($r['movement_type']) ?></td>
          <td class="px-4 py-2"><span class="font-mono"><?= e_($r['sku_code']) ?></span> <span class="text-xs text-gray-400"><?= e_($r['product_name']) ?></span></td>
          <td class="px-4 py-2 font-mono text-xs"><?= e_($r['warehouse_code']) ?></td>
          <td class="px-4 py-2 font-mono text-xs"><?= e_($r['bin_code']) ?></td>
          <td class="px-4 py-2 text-right font-semibold <?= $isPos ? 'text-green-700' : 'text-red-600' ?>">
            <?= ($isPos ? '+' : '') . e_(qty($r['qty_delta'])) ?>
          </td>
          <td class="px-4 py-2 text-xs text-gray-600">
            <?= $r['ref_type'] ? e_($r['ref_type']) : '—' ?><?php if ($r['ref_id']): ?>#<?= e_((string)$r['ref_id']) ?><?php endif; ?>
          </td>
          <td class="px-4 py-2 text-xs text-gray-500"><?= e_($r['user_name'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (count($rows) >= 500): ?>
    <p class="px-4 py-2 text-xs text-gray-500 bg-gray-50 border-t">Showing the most recent 500. Tighten the date or SKU filter to see older movements.</p>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
