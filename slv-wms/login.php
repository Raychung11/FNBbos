<?php
// SLV WMS — login.php
// Purpose: Email + password login for desktop and mobile entry points.
// Roles allowed: anonymous (sets up session)
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

if (is_logged_in()) {
    redirect('/index.php');
}

$error = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');

    if ($email === '' || $pass === '') {
        $error = 'Email and password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!attempt_login($email, $pass)) {
        $error = 'Invalid credentials.';
    } else {
        $next = (string)($_GET['next'] ?? '/index.php');
        // Only allow same-origin relative paths.
        if (!preg_match('#^/[^/\\\\]#', $next)) {
            $next = '/index.php';
        }
        redirect($next);
    }
}

$company = company_record()['name'] ?? setting('brand.company_name', 'SLV Group Sdn. Bhd.');
$primary = setting('brand.primary_color', '#6D28D9');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?= e_($primary) ?>">
<title>Sign in · <?= e_($company) ?> WMS</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<style>:root{--slv-primary: <?= e_($primary) ?>;}.slv-bg-primary{background:var(--slv-primary);}</style>
</head>
<body class="min-h-screen bg-gray-100 flex items-center justify-center px-4">
  <div class="w-full max-w-sm">
    <div class="text-center mb-6">
      <div class="inline-flex items-center justify-center w-12 h-12 rounded-lg slv-bg-primary text-white font-bold">SLV</div>
      <h1 class="mt-3 text-lg font-semibold text-gray-900"><?= e_($company) ?></h1>
      <p class="text-sm text-gray-500">Warehouse Management System</p>
    </div>

    <div class="bg-white shadow-sm rounded-lg p-6 border border-gray-200">
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
        <div>
          <label class="block text-sm font-medium text-gray-700">Password</label>
          <input type="password" name="password" required autocomplete="current-password"
                 class="mt-1 w-full rounded border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        </div>
        <button type="submit"
                class="w-full slv-bg-primary text-white rounded py-2 font-medium hover:opacity-90">
          Sign in
        </button>
      </form>
    </div>
    <p class="mt-4 text-center text-xs text-gray-500">
      Use a phone? <a href="/m/login.php" class="underline">Open mobile scanner</a>
    </p>
  </div>
</body>
</html>
