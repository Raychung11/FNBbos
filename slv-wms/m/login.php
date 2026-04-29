<?php
// SLV WMS — m/login.php
// Purpose: Mobile (PWA) login. Same auth logic as desktop, single-column UI.
// Roles allowed: anonymous
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

if (is_logged_in()) {
    redirect('/m/home.php');
}

$error = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    if ($email === '' || $pass === '') {
        $error = 'Email and password are required.';
    } elseif (!attempt_login($email, $pass)) {
        $error = 'Invalid credentials.';
    } else {
        redirect('/m/home.php');
    }
}

$company = company_record()['name'] ?? setting('brand.company_name', 'SLV Group Sdn. Bhd.');
$primary = setting('brand.primary_color', '#6D28D9');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e_($primary) ?>">
<title>Sign in · <?= e_($company) ?></title>
<link rel="manifest" href="/manifest.json">
<script src="https://cdn.tailwindcss.com"></script>
<style>:root{--slv-primary:<?= e_($primary) ?>;}.slv-bg-primary{background:var(--slv-primary);}</style>
</head>
<body class="min-h-screen bg-gray-100 flex items-center justify-center p-4">
  <div class="w-full max-w-sm">
    <div class="text-center mb-6">
      <div class="inline-flex items-center justify-center w-14 h-14 rounded-xl slv-bg-primary text-white font-bold text-xl">SLV</div>
      <h1 class="mt-3 text-base font-semibold text-gray-900"><?= e_($company) ?></h1>
      <p class="text-xs text-gray-500">Mobile scanner</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
      <?php if ($error): ?>
        <div class="mb-4 border border-red-200 bg-red-50 text-red-800 text-sm rounded px-3 py-2">
          <?= e_($error) ?>
        </div>
      <?php endif; ?>
      <form method="post" novalidate class="space-y-4">
        <?= csrf_field() ?>
        <div>
          <label class="block text-sm font-medium text-gray-700">Email</label>
          <input type="email" name="email" value="<?= e_($email) ?>" required autofocus inputmode="email"
                 autocomplete="email"
                 class="mt-1 w-full rounded border-gray-300 shadow-sm">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Password</label>
          <input type="password" name="password" required autocomplete="current-password"
                 class="mt-1 w-full rounded border-gray-300 shadow-sm">
        </div>
        <button type="submit" class="w-full slv-bg-primary text-white rounded-lg py-3 text-base font-medium">
          Sign in
        </button>
      </form>
    </div>
  </div>
  <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', () => {
        navigator.serviceWorker.register('/service-worker.js').catch(() => {});
      });
    }
  </script>
</body>
</html>
