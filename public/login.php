<?php
require __DIR__ . '/../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Csrf;

if (Auth::check()) redirect('index.php');

if (requestMethod() === 'POST') {
    Csrf::requireValid();
    $email    = (string)input('email', '');
    $password = (string)input('password', '');
    if ($email === '' || $password === '') {
        flash('error', 'Email and password are required.');
    } elseif (Auth::attempt($email, $password)) {
        clearOld();
        redirect('pages/dashboard.php');
    } else {
        flash('error', 'Invalid credentials.');
        rememberOld(['email' => $email]);
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Sign in — <?= e(config('app.name')) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body>
<div class="login-shell">
  <form class="login-card" method="post" action="<?= e(url('login.php')) ?>">
    <h1><?= e(config('app.name')) ?></h1>
    <p class="muted">Sign in to manage sales, claims and finance.</p>
    <?php if ($msg = flash('error')): ?><div class="alert alert--error"><?= e($msg) ?></div><?php endif; ?>
    <?= Csrf::field() ?>
    <div class="form-row">
      <label>Email</label>
      <input type="email" name="email" autocomplete="email" required value="<?= e((string)old('email')) ?>">
    </div>
    <div class="form-row" style="margin-top:10px;">
      <label>Password</label>
      <input type="password" name="password" autocomplete="current-password" required>
    </div>
    <div style="margin-top:18px;">
      <button class="btn btn--primary" type="submit" style="width:100%;">Sign in</button>
    </div>
  </form>
</div>
</body>
</html>
