<?php
// SLV WMS — pages/sales_orders/index.php
// Purpose: List sales orders with status / warehouse / customer filters.
// Roles allowed: super_admin, warehouse_manager, sales, viewer (RO)
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','sales','viewer']);

$role          = current_user()['role'] ?? '';
$accessibleIds = user_warehouse_ids();
$canCreate     = in_array($role, ['super_admin','warehouse_manager','sales'], true);

$status = (string)($_GET['status']  ?? '');
$wid    = isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '' ? (int)$_GET['warehouse_id'] : null;
$cid    = isset($_GET['customer_id'])  && $_GET['customer_id']  !== '' ? (int)$_GET['customer_id']  : null;
$q      = trim((string)($_GET['q'] ?? ''));

if ($wid !== null && $role !== 'super_admin' && !in_array($wid, $accessibleIds, true)) {
    flash('error', 'No access to that warehouse.');
    redirect('/pages/sales_orders/index.php');
}

$where  = ['so.company_id = ?'];
$params = [company_id()];
if ($role !== 'super_admin') {
    if (!$accessibleIds) {
        $where[] = '1 = 0';
    } else {
        $where[]  = 'so.warehouse_id IN (' . implode(',', array_fill(0, count($accessibleIds), '?')) . ')';
        $params   = array_merge($params, $accessibleIds);
    }
}
$validStatus = ['DRAFT','CONFIRMED','PICKING','PICKED','INVOICED','DELIVERED','CANCELLED'];
if ($status !== '' && in_array($status, $validStatus, true)) {
    $where[] = 'so.status = ?';
    $params[] = $status;
}
if ($wid !== null) { $where[] = 'so.warehouse_id = ?'; $params[] = $wid; }
if ($cid !== null) { $where[] = 'so.customer_id  = ?'; $params[] = $cid; }
if ($q !== '')     { $where[] = 'so.so_no LIKE ?'; $params[] = "%$q%"; }

$sql = "SELECT so.*, w.code AS warehouse_code,
               c.code AS customer_code, c.name AS customer_name,
               (SELECT COUNT(*) FROM so_items si WHERE si.so_id = so.id) AS line_count
          FROM sales_orders so
          JOIN warehouses w ON w.id = so.warehouse_id
          JOIN customers  c ON c.id = so.customer_id
         WHERE " . implode(' AND ', $where) . "
      ORDER BY so.id DESC LIMIT 200";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$whWhere  = ['company_id = ?']; $whParams = [company_id()];
if ($role !== 'super_admin' && $accessibleIds) {
    $whWhere[]  = 'id IN (' . implode(',', array_fill(0, count($accessibleIds), '?')) . ')';
    $whParams   = array_merge($whParams, $accessibleIds);
}
$whStmt = db()->prepare('SELECT id, code FROM warehouses WHERE ' . implode(' AND ', $whWhere) . ' ORDER BY code');
$whStmt->execute($whParams);
$warehouses = $whStmt->fetchAll();

$cStmt = db()->prepare("SELECT id, code, name FROM customers WHERE company_id = ? AND status='ACTIVE' ORDER BY code");
$cStmt->execute([company_id()]);
$customers = $cStmt->fetchAll();

$PAGE_TITLE = 'Sales orders';
require __DIR__ . '/../../partials/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
  <h1 class="text-2xl font-semibold text-gray-900">Sales orders</h1>
  <?php if ($canCreate): ?>
    <a href="/pages/sales_orders/create.php" class="slv-bg-primary text-white px-3 py-2 rounded text-sm font-medium">+ New SO</a>
  <?php endif; ?>
</div>

<form method="get" class="bg-white border border-gray-200 rounded-lg p-3 mb-4 grid grid-cols-2 sm:grid-cols-5 gap-3 text-sm">
  <div>
    <label class="block text-xs font-medium text-gray-500">Status</label>
    <select name="status" class="mt-1 w-full rounded border-gray-300 shadow-sm">
      <option value="">All</option>
      <?php foreach ($validStatus as $s): ?>
        <option value="<?= e_($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e_($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
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
    <label class="block text-xs font-medium text-gray-500">Customer</label>
    <select name="customer_id" class="mt-1 w-full rounded border-gray-300 shadow-sm">
      <option value="">All</option>
      <?php foreach ($customers as $c): ?>
        <option value="<?= e_($c['id']) ?>" <?= $cid === (int)$c['id'] ? 'selected' : '' ?>><?= e_($c['code']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-gray-500">Search SO #</label>
    <input type="text" name="q" value="<?= e_($q) ?>" class="mt-1 w-full rounded border-gray-300 shadow-sm">
  </div>
  <div class="flex items-end gap-2">
    <button class="slv-bg-primary text-white px-3 py-2 rounded">Filter</button>
    <a href="/pages/sales_orders/index.php" class="px-3 py-2 text-gray-600 hover:text-gray-900">Reset</a>
  </div>
</form>

<div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 text-gray-600">
      <tr>
        <th class="text-left px-4 py-2 font-medium">SO #</th>
        <th class="text-left px-4 py-2 font-medium">WH</th>
        <th class="text-left px-4 py-2 font-medium">Customer</th>
        <th class="text-right px-4 py-2 font-medium">Lines</th>
        <th class="text-right px-4 py-2 font-medium">Total</th>
        <th class="text-left px-4 py-2 font-medium">Status</th>
        <th class="text-left px-4 py-2 font-medium">Order date</th>
        <th class="px-4 py-2"></th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No sales orders match.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r):
        $statusClass = match ($r['status']) {
            'DRAFT'      => 'bg-gray-100  text-gray-700',
            'CONFIRMED'  => 'bg-blue-50   text-blue-700',
            'PICKING'    => 'bg-amber-50  text-amber-800',
            'PICKED'     => 'bg-cyan-50   text-cyan-700',
            'INVOICED'   => 'bg-violet-50 text-violet-700',
            'DELIVERED'  => 'bg-green-50  text-green-700',
            'CANCELLED'  => 'bg-red-50    text-red-700',
            default      => 'bg-gray-100  text-gray-500',
        };
      ?>
        <tr>
          <td class="px-4 py-2 font-mono"><?= e_($r['so_no']) ?></td>
          <td class="px-4 py-2 font-mono text-xs"><?= e_($r['warehouse_code']) ?></td>
          <td class="px-4 py-2">
            <span class="font-mono text-xs"><?= e_($r['customer_code']) ?></span>
            <span class="text-xs text-gray-500 ml-1"><?= e_($r['customer_name']) ?></span>
          </td>
          <td class="px-4 py-2 text-right"><?= e_((string)$r['line_count']) ?></td>
          <td class="px-4 py-2 text-right font-mono"><?= e_(money($r['grand_total'])) ?></td>
          <td class="px-4 py-2"><span class="inline-flex items-center px-2 py-0.5 rounded text-xs <?= $statusClass ?>"><?= e_($r['status']) ?></span></td>
          <td class="px-4 py-2 text-xs text-gray-500"><?= e_($r['order_date']) ?></td>
          <td class="px-4 py-2 text-right whitespace-nowrap">
            <a href="/pages/sales_orders/view.php?id=<?= e_($r['id']) ?>" class="text-indigo-700 hover:underline">Open</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
