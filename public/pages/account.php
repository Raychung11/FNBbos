<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Csrf;
use FNBBOS\AuditLog;

Auth::requireLogin(); // every authenticated user can manage their own account

if (requestMethod() === 'POST') {
    Csrf::requireValid();
    $action = (string)input('action');

    if ($action === 'password') {
        $current = (string)input('current_password');
        $new     = (string)input('new_password');
        $confirm = (string)input('confirm_password');

        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([Auth::id()]);
        $hash = (string)$stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            flash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 8) {
            flash('error', 'New password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            flash('error', 'New password and confirmation do not match.');
        } else {
            $newHash = password_hash($new, config('security.password_algo'), ['cost' => config('security.password_cost')]);
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$newHash, Auth::id()]);
            AuditLog::record('user.password_change', 'user', Auth::id());
            flash('ok', 'Password updated.');
        }
    } elseif ($action === 'profile') {
        $name  = trim((string)input('name'));
        $phone = trim((string)input('phone'));
        if ($name === '') {
            flash('error', 'Name is required.');
        } else {
            db()->prepare('UPDATE users SET name = ?, phone = ? WHERE id = ?')
                ->execute([$name, $phone ?: null, Auth::id()]);
            $_SESSION['auth']['name'] = $name;
            AuditLog::record('user.profile_update', 'user', Auth::id());
            flash('ok', 'Profile updated.');
        }
    }
    redirect('pages/account.php');
}

$me = db()->prepare('
    SELECT u.*, r.name AS role_name, o.name AS outlet_name, c.name AS company_name
    FROM users u
    LEFT JOIN roles r   ON r.id = u.role_id
    LEFT JOIN outlets o ON o.id = u.default_outlet_id
    LEFT JOIN companies c ON c.id = u.company_id
    WHERE u.id = ?');
$me->execute([Auth::id()]);
$me = $me->fetch();

$pageTitle = 'My Account';
$active    = 'account';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header"><div><h2>My account</h2><p>Manage your profile and password. Changes are recorded in the audit log.</p></div></div>

<div class="card">
  <h3>Profile</h3>
  <table class="data" style="margin-bottom:16px;">
    <tbody>
      <tr><th>Email</th><td><?= e($me['email']) ?></td><th>Role</th><td><?= e($me['role_name'] ?? '') ?></td></tr>
      <tr><th>Company</th><td><?= e($me['company_name'] ?? '') ?></td><th>Default outlet</th><td><?= e($me['outlet_name'] ?? '—') ?></td></tr>
    </tbody>
  </table>
  <form method="post" class="form-grid">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="profile">
    <div class="form-row"><label>Name</label><input type="text" name="name" required value="<?= e($me['name']) ?>"></div>
    <div class="form-row"><label>Phone</label><input type="text" name="phone" value="<?= e($me['phone'] ?? '') ?>"></div>
    <div class="form-row" style="align-self:end;"><button class="btn btn--primary" type="submit">Save profile</button></div>
  </form>
</div>

<div class="card">
  <h3>Change password</h3>
  <form method="post" class="form-grid">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="password">
    <div class="form-row"><label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required></div>
    <div class="form-row"><label>New password (min 8)</label><input type="password" name="new_password" autocomplete="new-password" required></div>
    <div class="form-row"><label>Confirm new password</label><input type="password" name="confirm_password" autocomplete="new-password" required></div>
    <div class="form-row" style="align-self:end;"><button class="btn btn--primary" type="submit">Update password</button></div>
  </form>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
