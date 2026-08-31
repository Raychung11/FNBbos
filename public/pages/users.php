<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;
use FNBBOS\Csrf;
use FNBBOS\AuditLog;

Auth::requireLogin();
Rbac::require('users.manage');

if (requestMethod() === 'POST') {
    Csrf::requireValid();
    $id        = asInt(input('id'));
    $name      = (string)input('name');
    $email     = (string)input('email');
    $roleId    = asInt(input('role_id'));
    $outletId  = asInt(input('default_outlet_id')) ?: null;
    $isActive  = asInt(input('is_active'));
    $password  = (string)input('password');

    if ($name === '' || $email === '' || !$roleId) {
        flash('error', 'Name, email and role are required.');
        redirect('pages/users.php');
    }
    $pdo = db();
    if ($id) {
        if ($password !== '') {
            $hash = password_hash($password, config('security.password_algo'), ['cost' => config('security.password_cost')]);
            $pdo->prepare('UPDATE users SET name=?, email=?, role_id=?, default_outlet_id=?, is_active=?, password_hash=? WHERE id=?')
                ->execute([$name, $email, $roleId, $outletId, $isActive, $hash, $id]);
        } else {
            $pdo->prepare('UPDATE users SET name=?, email=?, role_id=?, default_outlet_id=?, is_active=? WHERE id=?')
                ->execute([$name, $email, $roleId, $outletId, $isActive, $id]);
        }
        AuditLog::record('user.update', 'user', $id, ['email' => $email]);
        flash('ok', 'User updated.');
    } else {
        if ($password === '') { flash('error', 'Password is required for new users.'); redirect('pages/users.php'); }
        $hash = password_hash($password, config('security.password_algo'), ['cost' => config('security.password_cost')]);
        $pdo->prepare('INSERT INTO users (company_id, role_id, default_outlet_id, name, email, password_hash, is_active, created_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([Auth::companyId(), $roleId, $outletId, $name, $email, $hash, $isActive, nowDb()]);
        AuditLog::record('user.create', 'user', (int)$pdo->lastInsertId(), ['email' => $email]);
        flash('ok', 'User created.');
    }
    redirect('pages/users.php');
}

$companyId = Auth::companyId();
$users = db()->prepare('
    SELECT u.*, r.name AS role_name, o.name AS outlet_name
    FROM users u
    LEFT JOIN roles r   ON r.id = u.role_id
    LEFT JOIN outlets o ON o.id = u.default_outlet_id
    WHERE u.company_id = ?
    ORDER BY u.is_active DESC, u.name');
$users->execute([$companyId]);
$users = $users->fetchAll();

$roles    = db()->query('SELECT id, name, slug FROM roles ORDER BY name')->fetchAll();
$outlets  = db()->prepare('SELECT id, name FROM outlets WHERE company_id = ? ORDER BY name'); $outlets->execute([$companyId]); $outlets = $outlets->fetchAll();

$pageTitle = 'Users & Roles';
$active    = 'users';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header"><div><h2>Users &amp; roles</h2><p>Role-based access. Permissions per role are managed in the <code>permissions</code> and <code>role_permissions</code> tables.</p></div></div>

<div class="card">
  <h3>Create / update user</h3>
  <form method="post" class="form-grid">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="">
    <div class="form-row"><label>Name</label><input type="text" name="name" required></div>
    <div class="form-row"><label>Email</label><input type="email" name="email" required></div>
    <div class="form-row"><label>Role</label>
      <select name="role_id" required>
        <?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-row"><label>Default outlet</label>
      <select name="default_outlet_id"><option value="">— none —</option>
        <?php foreach ($outlets as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-row"><label>Password (leave blank to keep)</label><input type="password" name="password" autocomplete="new-password"></div>
    <div class="form-row"><label>Status</label>
      <select name="is_active"><option value="1">Active</option><option value="0">Inactive</option></select>
    </div>
    <div class="form-row" style="align-self:end;"><button class="btn btn--primary" type="submit">Save user</button></div>
  </form>
</div>

<div class="card">
  <h3>Existing users</h3>
  <?php if (!$users): ?><div class="empty">No users yet.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Default outlet</th><th>Status</th></tr></thead>
      <tbody><?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['name']) ?></td>
          <td><?= e($u['email']) ?></td>
          <td><?= e($u['role_name'] ?? '') ?></td>
          <td><?= e($u['outlet_name'] ?? '—') ?></td>
          <td><span class="badge <?= ((int)$u['is_active']===1)?'badge--ok':'badge--muted' ?>"><?= ((int)$u['is_active']===1)?'Active':'Inactive' ?></span></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
