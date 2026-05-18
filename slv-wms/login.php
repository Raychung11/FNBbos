<?php
// SLV WMS — login.php
// Purpose: Primary sign-in page. Shares the split-screen layout with
//          forgot.php / reset.php. The "Demo accounts" panel auto-shows
//          for any user whose password_hash still matches the seeded
//          ChangeMe!2026 hash, and disappears once those accounts are
//          rotated.
// Roles allowed: anonymous
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

// Note: we deliberately do NOT auto-redirect when the visitor is already
// signed in. Otherwise the operator can't switch user — they'd be sent
// straight to the existing user's dashboard, never seeing the login form
// or the Demo accounts panel. The page renders normally and a banner at
// the top offers Continue / Sign out so they can switch deliberately.
$activeUser = current_user();

$error = null;
$email = (string)($_GET['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');

    if ($email === '' || $pass === '') {
        $error = 'Email and password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // If a different user is already signed in, log them out first so
        // attempt_login() starts from a clean session. (attempt_login() also
        // resets $_SESSION internally, but explicit logout() also kills the
        // CSRF token from the previous user's tabs.)
        if ($activeUser && strcasecmp((string)$activeUser['email'], $email) !== 0) {
            logout();
            session_boot();
        }
        if (!attempt_login($email, $pass)) {
            $error = 'Invalid credentials.';
        } else {
            $next = (string)($_GET['next'] ?? '');
            if ($next === '' || !preg_match('#^/[^/\\\\]#', $next)) {
                $next = default_landing_url(current_user()['role'] ?? 'viewer');
            }
            redirect($next);
        }
    }
}

// Re-read after a possible logout/login cycle.
$activeUser = current_user();

// Detect demo accounts that still have the seeded password.
const DEMO_SEED_HASH = '$2y$12$qH2aqyG5I3UIbWWZpvhR/O10WQ/kSsBpqhY9NvGDOSE5wxp8jBism'; // ChangeMe!2026
$demoAccounts = [];
try {
    $stmt = db()->prepare(
        "SELECT email, name, role
           FROM users
          WHERE company_id = ? AND status = 'ACTIVE' AND password_hash = ?
       ORDER BY FIELD(role,'super_admin','warehouse_manager','sales','picker','packer','driver','viewer','receiver'), email"
    );
    $stmt->execute([company_id(), DEMO_SEED_HASH]);
    $demoAccounts = $stmt->fetchAll();
} catch (Throwable $e) {
    $demoAccounts = []; // safe fallback if DB hiccups
}

$PAGE_TITLE   = 'Sign in';
$AUTH_HEADING = $activeUser ? 'Switch user' : 'Sign in';
$AUTH_SUB     = $activeUser
    ? 'You are already signed in. Submit a different email + password to switch, or continue to the dashboard.'
    : 'Use your work email and password to access the warehouse.';
require __DIR__ . '/partials/auth_layout.php';
?>

<?php if ($activeUser): ?>
  <div class="mb-4 border border-blue-200 bg-blue-50 rounded p-3 text-sm flex items-start justify-between gap-3">
    <div class="text-blue-900">
      Currently signed in as <strong><?= e_($activeUser['name']) ?></strong>
      <span class="font-mono text-xs">(<?= e_($activeUser['role']) ?>)</span><br>
      <span class="text-xs text-blue-800/80"><?= e_($activeUser['email']) ?></span>
    </div>
    <div class="flex flex-col gap-1 text-right">
      <a href="/index.php" class="text-xs text-indigo-700 hover:underline">Continue →</a>
      <a href="/logout.php?next=/login.php" class="text-xs text-red-700 hover:underline">Sign out</a>
    </div>
  </div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="mb-4 border border-red-200 bg-red-50 text-red-800 text-sm rounded px-3 py-2">
    <?= e_($error) ?>
  </div>
<?php endif; ?>

<form method="post" novalidate class="space-y-4" x-data="{show:false}">
  <?= csrf_field() ?>
  <div>
    <label class="block text-sm font-medium text-gray-700">Email</label>
    <input type="email" name="email" value="<?= e_($email) ?>" required autofocus
           autocomplete="email" id="login-email"
           class="mt-1 w-full rounded border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
  </div>
  <div>
    <div class="flex items-center justify-between">
      <label class="block text-sm font-medium text-gray-700">Password</label>
      <a href="/forgot.php" class="text-xs text-indigo-700 hover:underline">Forgot password?</a>
    </div>
    <div class="mt-1 relative">
      <input :type="show ? 'text' : 'password'" name="password" required autocomplete="current-password" id="login-password"
             class="w-full rounded border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 pr-16">
      <button type="button" @click="show = !show"
              class="absolute right-2 inset-y-0 px-2 text-xs text-gray-500 hover:text-gray-800">
        <span x-show="!show">Show</span><span x-show="show" x-cloak>Hide</span>
      </button>
    </div>
  </div>
  <button type="submit"
          class="w-full slv-bg-primary text-white rounded py-2 font-medium hover:opacity-90">
    Sign in
  </button>
</form>

<?php if ($demoAccounts): ?>
  <section class="mt-8" x-data="{
      open: true,
      fill(email, pass){
        document.getElementById('login-email').value = email;
        document.getElementById('login-password').value = pass;
        document.getElementById('login-email').focus();
      }
    }">
    <div class="flex items-center justify-between mb-2">
      <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Demo accounts</h3>
      <button type="button" @click="open = !open" class="text-xs text-gray-500 hover:text-gray-800">
        <span x-show="open">Hide</span><span x-show="!open" x-cloak>Show</span>
      </button>
    </div>
    <div x-show="open" x-cloak class="bg-amber-50 border border-amber-200 rounded p-3 space-y-2">
      <p class="text-xs text-amber-800">
        These accounts still have the seeded password <code class="bg-white/60 px-1 rounded">ChangeMe!2026</code>.
        This panel disappears as soon as you rotate them.
      </p>
      <div class="divide-y divide-amber-200">
        <?php foreach ($demoAccounts as $a): ?>
          <button type="button" @click="fill('<?= e_($a['email']) ?>','ChangeMe!2026')"
                  class="w-full flex items-center justify-between gap-3 py-2 text-left hover:bg-amber-100 rounded px-2">
            <div>
              <div class="text-sm font-medium text-gray-900"><?= e_($a['name']) ?></div>
              <div class="text-xs text-gray-600 font-mono"><?= e_($a['email']) ?></div>
            </div>
            <div class="text-right">
              <div class="text-xs font-mono text-gray-700"><?= e_($a['role']) ?></div>
              <div class="text-xs text-indigo-700">Use →</div>
            </div>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<div class="mt-8 pt-6 border-t border-gray-200 flex items-center justify-between text-sm">
  <a href="/m/login.php" class="inline-flex items-center gap-2 text-indigo-700 hover:underline">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
    </svg>
    Open mobile scanner
  </a>
  <span class="text-xs text-gray-400">v0.1 · Phase 2</span>
</div>

<?php require __DIR__ . '/partials/auth_layout_close.php'; ?>
