<?php
// SLV WMS — pages/warehouses/index.php
// Purpose: List warehouses. super_admin sees all, warehouse_manager sees
//          only their accessible ones (read-only at that role).
// Roles allowed: super_admin (RW), warehouse_manager (RO)
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager']);

$canEdit = (current_user()['role'] ?? '') === 'super_admin';

$where  = 'WHERE w.company_id = ?';
$params = [company_id()];

if (!$canEdit) {
    $ids = user_warehouse_ids();
    if (!$ids) {
        $where  .= ' AND 1 = 0';
    } else {
        $where  .= ' AND w.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params  = array_merge($params, $ids);
    }
}

$stmt = db()->prepare(
    "SELECT w.id, w.code, w.name, w.address, w.contact, w.status, w.updated_at,
            (SELECT COUNT(*) FROM user_warehouse_access uwa WHERE uwa.warehouse_id = w.id) AS user_count
       FROM warehouses w
     $where
   ORDER BY w.code"
);
$stmt->execute($params);
$warehouses = $stmt->fetchAll();

$PAGE_TITLE = 'Warehouses';
require __DIR__ . '/../../partials/header.php';
?>
<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-semibold text-gray-900">Warehouses</h1>
  <?php if ($canEdit): ?>
    <a href="/pages/warehouses/create.php" class="slv-bg-primary text-white px-3 py-2 rounded text-sm font-medium">+ Add warehouse</a>
  <?php endif; ?>
</div>

<div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 text-gray-600">
      <tr>
        <th class="text-left px-4 py-2 font-medium">Code</th>
        <th class="text-left px-4 py-2 font-medium">Name</th>
        <th class="text-left px-4 py-2 font-medium">Address</th>
        <th class="text-left px-4 py-2 font-medium">Contact</th>
        <th class="text-left px-4 py-2 font-medium">Users</th>
        <th class="text-left px-4 py-2 font-medium">Status</th>
        <?php if ($canEdit): ?><th class="px-4 py-2"></th><?php endif; ?>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$warehouses): ?>
        <tr><td colspan="<?= $canEdit ? 7 : 6 ?>" class="px-4 py-6 text-center text-gray-400">No warehouses.</td></tr>
      <?php endif; ?>
      <?php foreach ($warehouses as $w): ?>
        <tr>
          <td class="px-4 py-2 font-mono"><?= e_($w['code']) ?></td>
          <td class="px-4 py-2"><?= e_($w['name']) ?></td>
          <td class="px-4 py-2 text-gray-500 text-xs"><?= e_($w['address'] ?: '—') ?></td>
          <td class="px-4 py-2 text-gray-500 text-xs"><?= e_($w['contact'] ?: '—') ?></td>
          <td class="px-4 py-2 text-gray-500"><?= e_((string)$w['user_count']) ?></td>
          <td class="px-4 py-2">
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs <?= $w['status'] === 'ACTIVE' ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
              <?= e_($w['status']) ?>
            </span>
          </td>
          <?php if ($canEdit): ?>
            <td class="px-4 py-2 text-right whitespace-nowrap">
              <a href="/pages/warehouses/edit.php?id=<?= e_($w['id']) ?>" class="text-indigo-700 hover:underline">Edit</a>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
