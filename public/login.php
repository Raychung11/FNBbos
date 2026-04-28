<?php
require __DIR__ . '/../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Csrf;

if (Auth::check()) redirect('pages/dashboard.php');

if (requestMethod() === 'POST') {
    Csrf::requireValid();
    $email    = (string)input('email', '');
    $password = (string)input('password', '');
    $remember = (bool)input('remember', false);

    if ($email === '' || $password === '') {
        flash('error', 'Email and password are required.');
        rememberOld(['email' => $email]);
    } elseif (Auth::attempt($email, $password)) {
        clearOld();
        if ($remember) {
            $_SESSION['auth']['remember'] = true;
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                session_id(),
                time() + 60 * 60 * 24 * 30,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']
            );
        }
        redirect('pages/dashboard.php');
    } else {
        flash('error', 'Invalid credentials. Please try again.');
        rememberOld(['email' => $email]);
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Sign in — <?= e(config('app.name')) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= e(url('assets/css/site.css')) ?>">
</head>
<body>
<main class="auth">
  <!-- LEFT: brand panel -->
  <aside class="auth__brand">
    <a href="<?= e(url('index.php')) ?>" class="auth__brand-mark" style="text-decoration:none;">
      <span class="logo"></span>
      <span><?= e(config('app.name')) ?></span>
    </a>
    <div class="auth__pitch">
      <h2>Welcome back to your F&amp;B operating system.</h2>
      <p>One place to track sales, fees, SST, settlements and claims across every brand and outlet.</p>
      <ul>
        <li>Centralised sales from every delivery platform</li>
        <li>Configurable SST &amp; platform fee engines</li>
        <li>AI-style claim risk scoring with explanations</li>
        <li>Bank reconciliation &amp; append-only audit trail</li>
      </ul>
    </div>
    <div class="auth__brand-foot">
      <strong>Built for Malaysian F&amp;B groups.</strong> PHP 8.2 · MySQL 8 · No platform lock-in.
    </div>
  </aside>

  <!-- RIGHT: login form -->
  <section class="auth__form">
    <div class="auth__card">
      <a href="<?= e(url('index.php')) ?>" class="auth__back">← Back to home</a>
      <h1>Sign in</h1>
      <p class="muted">Enter your credentials to access the dashboard.</p>

      <?php if ($msg = flash('error')): ?>
        <div class="auth-alert auth-alert--error"><?= e($msg) ?></div>
      <?php endif; ?>
      <?php if ($msg = flash('ok')): ?>
        <div class="auth-alert auth-alert--ok"><?= e($msg) ?></div>
      <?php endif; ?>

      <form method="post" action="<?= e(url('login.php')) ?>" autocomplete="on">
        <?= Csrf::field() ?>

        <div class="auth-row">
          <label for="email">Work email</label>
          <div class="auth-input">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <rect x="3" y="5" width="18" height="14" rx="2"/>
              <path d="m3 7 9 6 9-6"/>
            </svg>
            <input id="email" type="email" name="email" autocomplete="email" required
                   value="<?= e((string)old('email')) ?>" placeholder="you@company.com">
          </div>
        </div>

        <div class="auth-row">
          <label for="password">Password</label>
          <div class="auth-input">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <rect x="4" y="11" width="16" height="10" rx="2"/>
              <path d="M8 11V7a4 4 0 1 1 8 0v4"/>
            </svg>
            <input id="password" type="password" name="password" autocomplete="current-password" required placeholder="••••••••">
            <button type="button" class="toggle" aria-label="Show password"
                    onclick="(function(b){var i=document.getElementById('password');var show=i.type==='password';i.type=show?'text':'password';b.textContent=show?'Hide':'Show';b.setAttribute('aria-label',show?'Hide password':'Show password');})(this)">Show</button>
          </div>
        </div>

        <div class="auth-meta">
          <label><input type="checkbox" name="remember" value="1"> Remember me for 30 days</label>
        </div>

        <button class="btn btn--primary auth-submit" type="submit">Sign in</button>
      </form>

      <div class="auth-divider">New to the platform?</div>
      <p class="auth-cta-row">
        Want to see it in action? <a href="<?= e(url('index.php#demo')) ?>">Request a demo →</a>
      </p>
    </div>
  </section>
</main>
</body>
</html>
