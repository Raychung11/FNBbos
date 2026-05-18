<?php
// SLV WMS — forgot.php
// Purpose: Anonymous flow that emails a password-reset link if the address
//          matches an active user. Always responds with the same generic
//          message so we don't leak whether an account exists.
// Roles allowed: anonymous
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

if (is_logged_in()) {
    redirect('/index.php');
}

$sent  = false;
$email = '';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim((string)($_POST['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Look up the user; respond identically whether or not they exist.
        $stmt = db()->prepare(
            "SELECT id, name, email, status FROM users
              WHERE email = ? AND company_id = ?"
        );
        $stmt->execute([$email, company_id()]);
        $user = $stmt->fetch();

        if ($user && $user['status'] === 'ACTIVE') {
            // Generate a 32-byte random token; store SHA-256 of it; mail
            // the plaintext in a one-time link valid for 60 minutes.
            $plain  = bin2hex(random_bytes(32));
            $hash   = hash('sha256', $plain);
            $expire = date('Y-m-d H:i:s', time() + 3600);

            db()->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at, ip)
                 VALUES (?, ?, ?, ?)'
            )->execute([
                (int)$user['id'], $hash, $expire,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            $url = base_url('/reset.php?token=' . $plain);
            $body = "Hi {$user['name']},\n\n"
                  . "We received a request to reset your password for SLV WMS.\n"
                  . "Use the link below within 60 minutes:\n\n"
                  . "$url\n\n"
                  . "If you didn't request this, ignore this email.\n";

            send_mail($user['email'], 'Reset your SLV WMS password', $body);
            audit_log('password_reset_request', 'users', (int)$user['id']);
        }

        $sent = true;
    }
}

$PAGE_TITLE   = 'Forgot password';
$AUTH_HEADING = 'Reset your password';
$AUTH_SUB     = "Enter your email and we'll send you a one-time link.";
require __DIR__ . '/partials/auth_layout.php';
?>
<?php if ($sent): ?>
  <div class="border border-green-200 bg-green-50 text-green-800 rounded p-4 text-sm">
    If an account is registered with that email, a reset link has been sent.
    Check your inbox (and your spam folder) within the next minute.
  </div>
  <p class="mt-4 text-sm text-gray-500">
    Email not arriving? It will after PHPMailer is dropped into <code>vendor_local/</code>.
    Until then, an admin can pull the link from <code>storage/logs/outbound-mail.log</code>.
  </p>
  <div class="mt-6">
    <a href="/login.php" class="text-sm text-indigo-700 hover:underline">&larr; Back to sign in</a>
  </div>
<?php else: ?>
  <?php if ($error): ?>
    <div class="mb-4 border border-red-200 bg-red-50 text-red-800 text-sm rounded px-3 py-2">
      <?= e_($error) ?>
    </div>
  <?php endif; ?>
  <form method="post" novalidate class="space-y-4">
    <?= csrf_field() ?>
    <div>
      <label class="block text-sm font-medium text-gray-700">Email</label>
      <input type="email" name="email" value="<?= e_($email) ?>" required autofocus
             autocomplete="email"
             class="mt-1 w-full rounded border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
    </div>
    <button type="submit"
            class="w-full slv-bg-primary text-white rounded py-2 font-medium hover:opacity-90">
      Send reset link
    </button>
  </form>
  <p class="mt-6 text-sm">
    <a href="/login.php" class="text-indigo-700 hover:underline">&larr; Back to sign in</a>
  </p>
<?php endif; ?>
<?php require __DIR__ . '/partials/auth_layout_close.php'; ?>
