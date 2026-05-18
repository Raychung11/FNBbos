<?php
// SLV WMS — pages/users/index.php
// Purpose: List user accounts with role + accessible warehouses.
// Roles allowed: super_admin
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role('super_admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = (string)($_POST['_action'] ?? '');
    $uid = (int)($_POST['id'] ?? 0);
    $self = (int)(current_user()['id'] ?? 0);

    if ($do === 'toggle_status' && $uid > 0 && $uid !== $self) {
        $row = db()->prepare('SELECT status FROM users WHERE id = ? AND company_id = ?');
        $row->execute([$uid, company_id()]);
        $cur = (string)$row->fetchColumn();
        if ($cur !== '') {
            $new = $cur === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
            db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$new, $uid]);
            audit_log('user_status', 'users', $uid, ['status' => $new]);
            flash('success', "User status set to $new.");
        }
    }
    redirect('/pages/users/index.php');
}

$stmt = db()->prepare(
    "SELECT u.id, u.name, u.email, u.role, u.status, u.last_login_at,
            COALESCE(GROUP_CONCAT(w.code ORDER BY w.code SEPARATOR ', '), '') AS warehouse_codes
       FROM users u
  LEFT JOIN user_warehouse_access uwa ON uwa.user_id = u.id
  LEFT JOIN warehouses w              ON w.id = uwa.warehouse_id
      WHERE u.company_id = ?
   GROUP BY u.id
   ORDER BY u.name"
);
$stmt->execute([company_id()]);
$users = $stmt->fetchAll();

$selfId = (int)(current_user()['id'] ?? 0);

$PAGE_TITLE = 'Users';
require __DIR__ . '/../../partials/header.php';
?>
<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-semibold text-gray-900">Users</h1>
  <a href="/pages/users/create.php" class="slv-bg-primary text-white px-3 py-2 rounded text-sm font-medium">+ Add user</a>
</div>

<div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 text-gray-600">
      <tr>
        <th class="text-left px-4 py-2 font-medium">Name</th>
        <th class="text-left px-4 py-2 font-medium">Email</th>
        <th class="text-left px-4 py-2 font-medium">Role</th>
        <th class="text-left px-4 py-2 font-medium">Warehouses</th>
        <th class="text-left px-4 py-2 font-medium">Status</th>
        <th class="text-left px-4 py-2 font-medium">Last login</th>
        <th class="px-4 py-2"></th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php foreach ($users as $u):
        $isSelf  = (int)$u['id'] === $selfId;
        $allWhs  = $u['role'] === 'super_admin';
      ?>
        <tr>
          <td class="px-4 py-2"><?= e_($u['name']) ?> <?= $isSelf ? '<span class="text-xs text-gray-400">(you)</span>' : '' ?></td>
          <td class="px-4 py-2 text-gray-600"><?= e_($u['email']) ?></td>
          <td class="px-4 py-2 font-mono text-xs"><?= e_($u['role']) ?></td>
          <td class="px-4 py-2 text-gray-600 text-xs">
            <?= $allWhs ? '<span class="italic">all</span>' : e_($u['warehouse_codes'] ?: '—') ?>
          </td>
          <td class="px-4 py-2">
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs <?= $u['status'] === 'ACTIVE' ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
              <?= e_($u['status']) ?>
            </span>
          </td>
          <td class="px-4 py-2 text-gray-500 text-xs"><?= e_($u['last_login_at'] ?: '—') ?></td>
          <td class="px-4 py-2 text-right whitespace-nowrap">
            <a href="/pages/users/edit.php?id=<?= e_($u['id']) ?>" class="text-indigo-700 hover:underline">Edit</a>
            <?php if (!$isSelf): ?>
              <form method="post" class="inline" onsubmit="return confirm('Toggle status for <?= e_($u['email']) ?>?');">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="toggle_status">
                <input type="hidden" name="id" value="<?= e_($u['id']) ?>">
                <button class="ml-3 text-amber-700 hover:underline">
                  <?= $u['status'] === 'ACTIVE' ? 'Deactivate' : 'Activate' ?>
                </button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
