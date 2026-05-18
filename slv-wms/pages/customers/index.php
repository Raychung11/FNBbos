<?php
// SLV WMS — pages/customers/index.php
// Purpose: List customers.
// Roles allowed: super_admin, warehouse_manager, sales (RW), viewer (RO)
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','sales','viewer']);
$canEdit = in_array(current_user()['role'] ?? '', ['super_admin','warehouse_manager','sales'], true);

$q = trim((string)($_GET['q'] ?? ''));
$where = ['company_id = ?']; $params = [company_id()];
if ($q !== '') {
    $where[] = '(code LIKE ? OR name LIKE ?)';
    $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%';
}
$stmt = db()->prepare(
    "SELECT c.*, tg.code AS tg_code FROM customers c
       LEFT JOIN tax_groups tg ON tg.id = c.default_tax_group_id
      WHERE " . implode(' AND ', array_map(fn($w) => 'c.' . $w, $where)) .
    " ORDER BY c.code LIMIT 500"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$PAGE_TITLE = 'Customers';
require __DIR__ . '/../../partials/header.php';
?>
<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-semibold text-gray-900">Customers</h1>
  <?php if ($canEdit): ?>
    <a href="/pages/customers/create.php" class="slv-bg-primary text-white px-3 py-2 rounded text-sm font-medium">+ Add customer</a>
  <?php endif; ?>
</div>

<form method="get" class="bg-white border border-gray-200 rounded-lg p-3 mb-4 flex items-end gap-3 text-sm">
  <div>
    <label class="block text-xs font-medium text-gray-500">Search</label>
    <input type="text" name="q" value="<?= e_($q) ?>" placeholder="Code or name" class="mt-1 rounded border-gray-300 shadow-sm">
  </div>
  <button class="slv-bg-primary text-white px-3 py-2 rounded">Filter</button>
  <a href="/pages/customers/index.php" class="px-3 py-2 text-gray-600 hover:text-gray-900">Reset</a>
</form>

<div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 text-gray-600">
      <tr>
        <th class="text-left px-4 py-2 font-medium">Code</th>
        <th class="text-left px-4 py-2 font-medium">Name</th>
        <th class="text-left px-4 py-2 font-medium">Phone</th>
        <th class="text-left px-4 py-2 font-medium">Email</th>
        <th class="text-left px-4 py-2 font-medium">Tax group</th>
        <th class="text-right px-4 py-2 font-medium">Credit limit</th>
        <th class="text-left px-4 py-2 font-medium">Status</th>
        <?php if ($canEdit): ?><th class="px-4 py-2"></th><?php endif; ?>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$rows): ?>
        <tr><td colspan="<?= $canEdit ? 8 : 7 ?>" class="px-4 py-6 text-center text-gray-400">No customers.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $c): ?>
        <tr>
          <td class="px-4 py-2 font-mono"><?= e_($c['code']) ?></td>
          <td class="px-4 py-2"><?= e_($c['name']) ?></td>
          <td class="px-4 py-2 text-gray-500"><?= e_($c['phone'] ?: '—') ?></td>
          <td class="px-4 py-2 text-gray-500"><?= e_($c['email'] ?: '—') ?></td>
          <td class="px-4 py-2 font-mono text-xs"><?= e_($c['tg_code'] ?: '—') ?></td>
          <td class="px-4 py-2 text-right font-mono"><?= e_(money($c['credit_limit'])) ?></td>
          <td class="px-4 py-2">
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs <?= $c['status'] === 'ACTIVE' ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
              <?= e_($c['status']) ?>
            </span>
          </td>
          <?php if ($canEdit): ?>
            <td class="px-4 py-2 text-right whitespace-nowrap">
              <a href="/pages/customers/edit.php?id=<?= e_($c['id']) ?>" class="text-indigo-700 hover:underline">Edit</a>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
