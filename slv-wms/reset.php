<?php
// SLV WMS — reset.php
// Purpose: Consume a password-reset token and set a new password. Tokens
//          are single-use and expire in 60 minutes. Storing only sha256
//          of the plaintext means a DB leak doesn't disclose live tokens.
// Roles allowed: anonymous
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

if (is_logged_in()) {
    redirect('/index.php');
}

$token   = (string)($_REQUEST['token'] ?? '');
$errors  = [];
$showForm = false;
$user    = null;

if ($token === '' || strlen($token) < 32) {
    $errors[] = 'Reset link is missing or malformed.';
} else {
    $hash = hash('sha256', $token);
    $stmt = db()->prepare(
        'SELECT pr.id AS pr_id, pr.expires_at, pr.used_at,
                u.id, u.name, u.email, u.status
           FROM password_resets pr
           JOIN users u ON u.id = pr.user_id
          WHERE pr.token_hash = ?
          LIMIT 1'
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    if (!$row) {
        $errors[] = 'Reset link is invalid.';
    } elseif ($row['used_at'] !== null) {
        $errors[] = 'This reset link has already been used.';
    } elseif (strtotime((string)$row['expires_at']) < time()) {
        $errors[] = 'Reset link has expired. Request a new one.';
    } elseif ($row['status'] !== 'ACTIVE') {
        $errors[] = 'Account is not active. Contact your administrator.';
    } else {
        $showForm = true;
        $user = $row;
    }
}

if ($showForm && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pw1 = (string)($_POST['password']         ?? '');
    $pw2 = (string)($_POST['password_confirm'] ?? '');

    if (mb_strlen($pw1) < 8)        $errors[] = 'Password must be at least 8 characters.';
    if ($pw1 !== $pw2)              $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $newHash = password_hash($pw1, PASSWORD_BCRYPT);
        db_tx(function () use ($user, $newHash) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([$newHash, (int)$user['id']]);
            db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')
                ->execute([(int)$user['pr_id']]);
            // Invalidate every other outstanding reset for this account.
            db()->prepare(
                'UPDATE password_resets SET used_at = NOW()
                  WHERE user_id = ? AND used_at IS NULL AND id <> ?'
            )->execute([(int)$user['id'], (int)$user['pr_id']]);
            audit_log('password_reset_complete', 'users', (int)$user['id']);
        });
        flash('success', 'Password updated. Sign in with your new password.');
        redirect('/login.php');
    }
}

$PAGE_TITLE   = 'Choose a new password';
$AUTH_HEADING = $showForm ? 'Choose a new password' : 'Reset link unavailable';
$AUTH_SUB     = $showForm
    ? 'Pick something at least 8 characters. Reset links are single-use and expire after 60 minutes.'
    : '';
require __DIR__ . '/partials/auth_layout.php';
?>
<?php if (!$showForm): ?>
  <?php foreach ($errors as $err): ?>
    <div class="mb-3 border border-red-200 bg-red-50 text-red-800 text-sm rounded px-3 py-2">
      <?= e_($err) ?>
    </div>
  <?php endforeach; ?>
  <div class="mt-6 flex flex-col gap-2">
    <a href="/forgot.php" class="text-sm text-indigo-700 hover:underline">Request a new reset link</a>
    <a href="/login.php"  class="text-sm text-gray-500 hover:underline">Back to sign in</a>
  </div>
<?php else: ?>
  <?php if ($errors): ?>
    <div class="mb-4 border border-red-200 bg-red-50 text-red-800 rounded p-3 text-sm">
      <ul class="list-disc list-inside">
        <?php foreach ($errors as $err): ?><li><?= e_($err) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  <p class="mb-3 text-sm text-gray-600">
    Resetting password for <strong><?= e_($user['email']) ?></strong>.
  </p>
  <form method="post" novalidate class="space-y-4" x-data="{show:false}">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e_($token) ?>">
    <div>
      <label class="block text-sm font-medium text-gray-700">New password</label>
      <div class="mt-1 relative">
        <input :type="show ? 'text' : 'password'" name="password" required minlength="8" autofocus
               autocomplete="new-password"
               class="w-full rounded border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 pr-16">
        <button type="button" @click="show = !show"
                class="absolute right-2 inset-y-0 px-2 text-xs text-gray-500 hover:text-gray-800">
          <span x-show="!show">Show</span><span x-show="show" x-cloak>Hide</span>
        </button>
      </div>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Confirm new password</label>
      <input :type="show ? 'text' : 'password'" name="password_confirm" required minlength="8"
             autocomplete="new-password"
             class="mt-1 w-full rounded border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
    </div>
    <button type="submit"
            class="w-full slv-bg-primary text-white rounded py-2 font-medium hover:opacity-90">
      Update password
    </button>
  </form>
  <p class="mt-6 text-sm">
    <a href="/login.php" class="text-indigo-700 hover:underline">&larr; Back to sign in</a>
  </p>
<?php endif; ?>
<?php require __DIR__ . '/partials/auth_layout_close.php'; ?>
