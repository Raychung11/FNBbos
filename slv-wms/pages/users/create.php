<?php
// SLV WMS — pages/users/create.php
// Purpose: Create a user account, assign role + warehouse access.
// Roles allowed: super_admin
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role('super_admin');
require __DIR__ . '/_form_data.php';

$errors = [];
$values = [
    'name' => '', 'email' => '', 'phone' => '', 'role' => 'viewer',
    'status' => 'ACTIVE',
];
$selectedWh = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $values = [
        'name'   => trim((string)($_POST['name']   ?? '')),
        'email'  => trim((string)($_POST['email']  ?? '')),
        'phone'  => trim((string)($_POST['phone']  ?? '')),
        'role'   => (string)($_POST['role']   ?? 'viewer'),
        'status' => (string)($_POST['status'] ?? 'ACTIVE'),
    ];
    $password = (string)($_POST['password'] ?? '');
    $selectedWh = isset($_POST['warehouse_ids']) && is_array($_POST['warehouse_ids'])
        ? array_map('intval', $_POST['warehouse_ids']) : [];

    if ($values['name'] === '' || mb_strlen($values['name']) > 191)        $errors[] = 'Name is required (max 191).';
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL))               $errors[] = 'A valid email is required.';
    if (!isset($ROLES[$values['role']]))                                    $errors[] = 'Invalid role.';
    if (mb_strlen($password) < 8)                                           $errors[] = 'Password must be at least 8 characters.';
    if (!in_array($values['status'], ['ACTIVE','INACTIVE'], true))          $errors[] = 'Invalid status.';

    if (!$errors) {
        try {
            db_tx(function () use ($values, $password, $selectedWh, $ALL_WAREHOUSES) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = db()->prepare(
                    'INSERT INTO users (company_id, name, email, phone, password_hash, role, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    company_id(), $values['name'], $values['email'],
                    $values['phone'] ?: null, $hash, $values['role'], $values['status'],
                ]);
                $newId = (int)db()->lastInsertId();

                // Assign warehouse access (skipped for super_admin — implicit all).
                if ($values['role'] !== 'super_admin' && $selectedWh) {
                    $validIds = array_map(fn($w) => (int)$w['id'], $ALL_WAREHOUSES);
                    $bind = db()->prepare(
                        'INSERT IGNORE INTO user_warehouse_access (user_id, warehouse_id, granted_by)
                         VALUES (?, ?, ?)'
                    );
                    $by = (int)(current_user()['id'] ?? 0);
                    foreach ($selectedWh as $wid) {
                        if (in_array($wid, $validIds, true)) {
                            $bind->execute([$newId, $wid, $by]);
                        }
                    }
                }
                audit_log('user_create', 'users', $newId, [
                    'email' => $values['email'], 'role' => $values['role'],
                    'warehouses' => $selectedWh,
                ]);
            });
            flash('success', "User {$values['email']} created.");
            redirect('/pages/users/index.php');
        } catch (PDOException $e) {
            $errors[] = (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062)
                ? 'Email already exists.' : 'Database error.';
        }
    }
}

$PAGE_TITLE = 'New user';
require __DIR__ . '/../../partials/header.php';
?>
<div class="mb-6">
  <a href="/pages/users/index.php" class="text-sm text-gray-500 hover:text-gray-900">&larr; Users</a>
  <h1 class="text-2xl font-semibold text-gray-900 mt-1">New user</h1>
</div>

<?php if ($errors): ?>
  <div class="mb-4 border border-red-200 bg-red-50 text-red-800 rounded p-3 text-sm max-w-2xl">
    <ul class="list-disc list-inside">
      <?php foreach ($errors as $err): ?><li><?= e_($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" class="bg-white border border-gray-200 rounded-lg p-5 max-w-2xl space-y-4"
      x-data="{ role: '<?= e_($values['role']) ?>' }">
  <?= csrf_field() ?>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
      <label class="block text-sm font-medium text-gray-700">Full name</label>
      <input type="text" name="name" value="<?= e_($values['name']) ?>" required maxlength="191"
             class="mt-1 w-full rounded border-gray-300 shadow-sm">
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Email</label>
      <input type="email" name="email" value="<?= e_($values['email']) ?>" required maxlength="191"
             class="mt-1 w-full rounded border-gray-300 shadow-sm">
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Phone</label>
      <input type="text" name="phone" value="<?= e_($values['phone']) ?>" maxlength="64"
             class="mt-1 w-full rounded border-gray-300 shadow-sm">
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Role</label>
      <select name="role" x-model="role" class="mt-1 w-full rounded border-gray-300 shadow-sm">
        <?php foreach ($ROLES as $r => $label): ?>
          <option value="<?= e_($r) ?>" <?= $values['role'] === $r ? 'selected' : '' ?>><?= e_($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Password</label>
      <input type="password" name="password" required minlength="8" autocomplete="new-password"
             class="mt-1 w-full rounded border-gray-300 shadow-sm">
      <p class="mt-1 text-xs text-gray-500">Minimum 8 characters. User can change it at any time.</p>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Status</label>
      <select name="status" class="mt-1 w-full rounded border-gray-300 shadow-sm">
        <?php foreach (['ACTIVE','INACTIVE'] as $s): ?>
          <option value="<?= e_($s) ?>" <?= $values['status'] === $s ? 'selected' : '' ?>><?= e_($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div x-show="role !== 'super_admin'" x-cloak>
    <label class="block text-sm font-medium text-gray-700 mb-2">Warehouse access</label>
    <?php if (!$ALL_WAREHOUSES): ?>
      <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded p-3">
        No warehouses defined. Add one in <a href="/pages/warehouses/index.php" class="underline">Warehouses</a> first.
      </p>
    <?php else: ?>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        <?php foreach ($ALL_WAREHOUSES as $w): ?>
          <label class="inline-flex items-center gap-2 border border-gray-200 rounded px-3 py-2">
            <input type="checkbox" name="warehouse_ids[]" value="<?= e_($w['id']) ?>"
                   <?= in_array((int)$w['id'], $selectedWh, true) ? 'checked' : '' ?>
                   class="rounded border-gray-300">
            <span class="font-mono text-sm"><?= e_($w['code']) ?></span>
            <span class="text-xs text-gray-500"><?= e_($w['name']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <div x-show="role === 'super_admin'" class="text-xs text-gray-500" x-cloak>
    Super admin has implicit access to all warehouses; explicit grants are not required.
  </div>

  <div class="flex justify-end gap-3 pt-2">
    <a href="/pages/users/index.php" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    <button type="submit" class="slv-bg-primary text-white px-4 py-2 rounded text-sm font-medium">Create user</button>
  </div>
</form>

<style>[x-cloak]{display:none !important;}</style>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
