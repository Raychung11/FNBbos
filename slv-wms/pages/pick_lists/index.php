<?php
// SLV WMS — pages/pick_lists/index.php
// Purpose: List pick lists with status / warehouse / SO filters.
// Roles allowed: super_admin, warehouse_manager, picker, packer (RO desktop)
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','picker','packer']);

$role          = current_user()['role'] ?? '';
$accessibleIds = user_warehouse_ids();

$status = (string)($_GET['status'] ?? '');
$wid    = isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '' ? (int)$_GET['warehouse_id'] : null;

$where  = ['pl.company_id = ?'];
$params = [company_id()];
if ($role !== 'super_admin') {
    if (!$accessibleIds) {
        $where[] = '1 = 0';
    } else {
        $where[]  = 'pl.warehouse_id IN (' . implode(',', array_fill(0, count($accessibleIds), '?')) . ')';
        $params   = array_merge($params, $accessibleIds);
    }
}
$validStatus = ['DRAFT','ASSIGNED','IN_PROGRESS','COMPLETED','CANCELLED'];
if ($status !== '' && in_array($status, $validStatus, true)) {
    $where[] = 'pl.status = ?';
    $params[] = $status;
}
if ($wid !== null) { $where[] = 'pl.warehouse_id = ?'; $params[] = $wid; }

$sql = "SELECT pl.*, w.code AS warehouse_code,
               so.so_no, so.customer_id,
               c.code AS customer_code, c.name AS customer_name,
               u.name AS assigned_name,
               (SELECT COUNT(*) FROM pick_items pi WHERE pi.pick_list_id = pl.id) AS line_count,
               (SELECT COALESCE(SUM(pi.qty_to_pick),0) FROM pick_items pi WHERE pi.pick_list_id = pl.id) AS qty_total,
               (SELECT COALESCE(SUM(pi.qty_picked),0)  FROM pick_items pi WHERE pi.pick_list_id = pl.id) AS qty_done
          FROM pick_lists pl
          JOIN warehouses w   ON w.id = pl.warehouse_id
          JOIN sales_orders so ON so.id = pl.so_id
          JOIN customers   c  ON c.id = so.customer_id
     LEFT JOIN users       u  ON u.id = pl.assigned_user_id
         WHERE " . implode(' AND ', $where) . "
      ORDER BY pl.id DESC LIMIT 200";
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

$PAGE_TITLE = 'Pick lists';
require __DIR__ . '/../../partials/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
  <h1 class="text-2xl font-semibold text-gray-900">Pick lists</h1>
  <div class="text-sm text-gray-500">Pick lists are generated from confirmed sales orders.</div>
</div>

<form method="get" class="bg-white border border-gray-200 rounded-lg p-3 mb-4 grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
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
  <div class="flex items-end gap-2 sm:col-span-2">
    <button class="slv-bg-primary text-white px-3 py-2 rounded">Filter</button>
    <a href="/pages/pick_lists/index.php" class="px-3 py-2 text-gray-600 hover:text-gray-900">Reset</a>
  </div>
</form>

<div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 text-gray-600">
      <tr>
        <th class="text-left px-4 py-2 font-medium">Pick #</th>
        <th class="text-left px-4 py-2 font-medium">SO</th>
        <th class="text-left px-4 py-2 font-medium">WH</th>
        <th class="text-left px-4 py-2 font-medium">Customer</th>
        <th class="text-right px-4 py-2 font-medium">Lines</th>
        <th class="text-left px-4 py-2 font-medium">Progress</th>
        <th class="text-left px-4 py-2 font-medium">Status</th>
        <th class="text-left px-4 py-2 font-medium">Assigned</th>
        <th class="px-4 py-2"></th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="px-4 py-6 text-center text-gray-400">No pick lists. Confirm a sales order then generate one.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r):
        $statusClass = match ($r['status']) {
            'DRAFT'       => 'bg-gray-100 text-gray-700',
            'ASSIGNED'    => 'bg-blue-50  text-blue-700',
            'IN_PROGRESS' => 'bg-amber-50 text-amber-800',
            'COMPLETED'   => 'bg-green-50 text-green-700',
            'CANCELLED'   => 'bg-red-50   text-red-700',
            default       => 'bg-gray-100 text-gray-500',
        };
        $pct = (float)$r['qty_total'] > 0
             ? min(100, ((float)$r['qty_done'] / (float)$r['qty_total']) * 100)
             : 0;
      ?>
        <tr>
          <td class="px-4 py-2 font-mono"><?= e_($r['pick_no']) ?></td>
          <td class="px-4 py-2 font-mono text-xs">
            <a href="/pages/sales_orders/view.php?id=<?= e_($r['so_id']) ?>" class="text-indigo-700 hover:underline">
              <?= e_($r['so_no']) ?>
            </a>
          </td>
          <td class="px-4 py-2 font-mono text-xs"><?= e_($r['warehouse_code']) ?></td>
          <td class="px-4 py-2">
            <span class="font-mono text-xs"><?= e_($r['customer_code']) ?></span>
            <span class="text-xs text-gray-500 ml-1"><?= e_($r['customer_name']) ?></span>
          </td>
          <td class="px-4 py-2 text-right"><?= e_((string)$r['line_count']) ?></td>
          <td class="px-4 py-2">
            <div class="h-1 w-32 bg-gray-100 rounded overflow-hidden">
              <div class="h-full slv-bg-primary" style="width: <?= e_(number_format($pct, 1)) ?>%"></div>
            </div>
            <div class="text-[11px] text-gray-500 mt-1"><?= e_(qty($r['qty_done'])) ?> / <?= e_(qty($r['qty_total'])) ?></div>
          </td>
          <td class="px-4 py-2"><span class="inline-flex items-center px-2 py-0.5 rounded text-xs <?= $statusClass ?>"><?= e_($r['status']) ?></span></td>
          <td class="px-4 py-2 text-xs text-gray-500"><?= e_($r['assigned_name'] ?: '—') ?></td>
          <td class="px-4 py-2 text-right whitespace-nowrap">
            <a href="/pages/pick_lists/view.php?id=<?= e_($r['id']) ?>" class="text-indigo-700 hover:underline">Open</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
