<?php
// SLV WMS — pages/suppliers/index.php
// Purpose: List suppliers.
// Roles allowed: super_admin, warehouse_manager (read others read-only)
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','receiver']);
$canEdit = in_array(current_user()['role'] ?? '', ['super_admin','warehouse_manager'], true);

$q = trim((string)($_GET['q'] ?? ''));
$where = ['company_id = ?']; $params = [company_id()];
if ($q !== '') {
    $where[] = '(code LIKE ? OR name LIKE ?)';
    $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%';
}
$stmt = db()->prepare(
    "SELECT * FROM suppliers WHERE " . implode(' AND ', $where) .
    " ORDER BY code LIMIT 500"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$PAGE_TITLE = 'Suppliers';
require __DIR__ . '/../../partials/header.php';
?>
<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-semibold text-gray-900">Suppliers</h1>
  <?php if ($canEdit): ?>
    <a href="/pages/suppliers/create.php" class="slv-bg-primary text-white px-3 py-2 rounded text-sm font-medium">+ Add supplier</a>
  <?php endif; ?>
</div>

<form method="get" class="bg-white border border-gray-200 rounded-lg p-3 mb-4 flex items-end gap-3 text-sm">
  <div>
    <label class="block text-xs font-medium text-gray-500">Search</label>
    <input type="text" name="q" value="<?= e_($q) ?>" placeholder="Code or name" class="mt-1 rounded border-gray-300 shadow-sm">
  </div>
  <button class="slv-bg-primary text-white px-3 py-2 rounded">Filter</button>
  <a href="/pages/suppliers/index.php" class="px-3 py-2 text-gray-600 hover:text-gray-900">Reset</a>
</form>

<div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 text-gray-600">
      <tr>
        <th class="text-left px-4 py-2 font-medium">Code</th>
        <th class="text-left px-4 py-2 font-medium">Name</th>
        <th class="text-left px-4 py-2 font-medium">Contact</th>
        <th class="text-left px-4 py-2 font-medium">Phone</th>
        <th class="text-left px-4 py-2 font-medium">Email</th>
        <th class="text-left px-4 py-2 font-medium">Status</th>
        <?php if ($canEdit): ?><th class="px-4 py-2"></th><?php endif; ?>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$rows): ?>
        <tr><td colspan="<?= $canEdit ? 7 : 6 ?>" class="px-4 py-6 text-center text-gray-400">No suppliers.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $s): ?>
        <tr>
          <td class="px-4 py-2 font-mono"><?= e_($s['code']) ?></td>
          <td class="px-4 py-2"><?= e_($s['name']) ?></td>
          <td class="px-4 py-2 text-gray-500"><?= e_($s['contact_person'] ?: '—') ?></td>
          <td class="px-4 py-2 text-gray-500"><?= e_($s['phone'] ?: '—') ?></td>
          <td class="px-4 py-2 text-gray-500"><?= e_($s['email'] ?: '—') ?></td>
          <td class="px-4 py-2">
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs <?= $s['status'] === 'ACTIVE' ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
              <?= e_($s['status']) ?>
            </span>
          </td>
          <?php if ($canEdit): ?>
            <td class="px-4 py-2 text-right whitespace-nowrap">
              <a href="/pages/suppliers/edit.php?id=<?= e_($s['id']) ?>" class="text-indigo-700 hover:underline">Edit</a>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
