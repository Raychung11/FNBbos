<?php
// SLV WMS — pages/delivery_orders/index.php
// Purpose: List delivery orders with status / warehouse / driver filters.
// Roles allowed: super_admin, warehouse_manager, packer, driver (own only)
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','packer','driver','viewer']);

$role = current_user()['role'] ?? '';
$uid  = (int)(current_user()['id'] ?? 0);
$accessibleIds = user_warehouse_ids();

$status = (string)($_GET['status'] ?? '');
$wid    = isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '' ? (int)$_GET['warehouse_id'] : null;
$mine   = $role === 'driver' || !empty($_GET['mine']);

$where  = ['d.company_id = ?'];
$params = [company_id()];
if ($role !== 'super_admin') {
    if (!$accessibleIds) {
        $where[] = '1 = 0';
    } else {
        $where[]  = 'd.warehouse_id IN (' . implode(',', array_fill(0, count($accessibleIds), '?')) . ')';
        $params   = array_merge($params, $accessibleIds);
    }
}
$validStatus = ['READY','IN_TRANSIT','DELIVERED','RETURNED','CANCELLED'];
if ($status !== '' && in_array($status, $validStatus, true)) {
    $where[] = 'd.status = ?'; $params[] = $status;
}
if ($wid !== null) { $where[] = 'd.warehouse_id = ?'; $params[] = $wid; }
if ($mine && $uid)  { $where[] = 'd.driver_user_id = ?'; $params[] = $uid; }

$sql = "SELECT d.*, w.code AS warehouse_code,
               c.code AS customer_code, c.name AS customer_name,
               inv.invoice_no, inv.grand_total,
               u.name AS driver_name
          FROM delivery_orders d
          JOIN warehouses w   ON w.id = d.warehouse_id
          JOIN customers   c  ON c.id = d.customer_id
          JOIN invoices   inv ON inv.id = d.invoice_id
     LEFT JOIN users       u  ON u.id = d.driver_user_id
         WHERE " . implode(' AND ', $where) . "
      ORDER BY d.id DESC LIMIT 200";
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

$PAGE_TITLE = 'Delivery orders';
require __DIR__ . '/../../partials/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
  <h1 class="text-2xl font-semibold text-gray-900">Delivery orders</h1>
  <div class="text-sm text-gray-500">Generated from invoices.</div>
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
    <a href="/pages/delivery_orders/index.php" class="px-3 py-2 text-gray-600 hover:text-gray-900">Reset</a>
    <?php if ($role !== 'driver' && $uid): ?>
      <label class="ml-auto inline-flex items-center gap-1 text-xs text-gray-500">
        <input type="checkbox" name="mine" value="1" onchange="this.form.submit()" <?= $mine ? 'checked' : '' ?>>
        Only mine
      </label>
    <?php endif; ?>
  </div>
</form>

<div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 text-gray-600">
      <tr>
        <th class="text-left px-4 py-2 font-medium">DO #</th>
        <th class="text-left px-4 py-2 font-medium">Invoice</th>
        <th class="text-left px-4 py-2 font-medium">WH</th>
        <th class="text-left px-4 py-2 font-medium">Customer</th>
        <th class="text-right px-4 py-2 font-medium">Total</th>
        <th class="text-left px-4 py-2 font-medium">Driver / vehicle</th>
        <th class="text-left px-4 py-2 font-medium">Status</th>
        <th class="text-left px-4 py-2 font-medium">Dispatched</th>
        <th class="px-4 py-2"></th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="px-4 py-6 text-center text-gray-400">No delivery orders match.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r):
        $statusClass = match ($r['status']) {
            'READY'      => 'bg-gray-100  text-gray-700',
            'IN_TRANSIT' => 'bg-amber-50  text-amber-800',
            'DELIVERED'  => 'bg-green-50  text-green-700',
            'RETURNED'   => 'bg-orange-50 text-orange-700',
            'CANCELLED'  => 'bg-red-50    text-red-700',
            default      => 'bg-gray-100  text-gray-500',
        };
      ?>
        <tr>
          <td class="px-4 py-2 font-mono"><?= e_($r['do_no']) ?></td>
          <td class="px-4 py-2 font-mono text-xs">
            <a href="/pages/invoices/view.php?id=<?= e_($r['invoice_id']) ?>" class="text-indigo-700 hover:underline"><?= e_($r['invoice_no']) ?></a>
          </td>
          <td class="px-4 py-2 font-mono text-xs"><?= e_($r['warehouse_code']) ?></td>
          <td class="px-4 py-2">
            <span class="font-mono text-xs"><?= e_($r['customer_code']) ?></span>
            <span class="text-xs text-gray-500 ml-1"><?= e_($r['customer_name']) ?></span>
          </td>
          <td class="px-4 py-2 text-right font-mono"><?= e_(money($r['grand_total'])) ?></td>
          <td class="px-4 py-2 text-xs text-gray-600">
            <?= e_($r['driver_name'] ?? '—') ?>
            <?php if ($r['vehicle']): ?><div class="opacity-70"><?= e_($r['vehicle']) ?></div><?php endif; ?>
          </td>
          <td class="px-4 py-2"><span class="inline-flex items-center px-2 py-0.5 rounded text-xs <?= $statusClass ?>"><?= e_($r['status']) ?></span></td>
          <td class="px-4 py-2 text-xs text-gray-500"><?= e_($r['dispatched_at'] ?: '—') ?></td>
          <td class="px-4 py-2 text-right whitespace-nowrap">
            <a href="/pages/delivery_orders/view.php?id=<?= e_($r['id']) ?>" class="text-indigo-700 hover:underline">Open</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
