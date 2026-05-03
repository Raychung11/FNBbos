<?php
// SLV WMS — _auth_test.php   (DELETE AFTER DIAGNOSING)
// Purpose: dump the current authentication / session state so the operator
//          can verify *which* user the browser session is bound to. Hit this
//          page right after a login attempt — the "Logged-in user" line is
//          the ground truth. If you think you logged in as sales but this
//          says super_admin, the cookie still belongs to admin.
//
// SECURITY: shows the session id, current user info and warehouse access.
//           DELETE THIS FILE from your hosting account once you finish
//           diagnosing.

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$user = current_user();

$cookieName  = session_name();
$cookieIn    = $_SERVER['HTTP_COOKIE'] ?? '';
$cookieValue = $_COOKIE[$cookieName] ?? '(not in $_COOKIE)';

$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

$savePath = session_save_path() ?: (string)ini_get('session.save_path') ?: sys_get_temp_dir();

header('Content-Type: text/plain; charset=utf-8');
?>
SLV WMS — Auth / session debug
==============================

This is the GROUND TRUTH for who the browser is currently signed in as.

Logged-in user
--------------
<?php if ($user): ?>
  Name:        <?= $user['name'] ?? '' ?>

  Email:       <?= $user['email'] ?? '' ?>

  Role:        <?= $user['role'] ?? '' ?>

  User id:     <?= $user['id'] ?? '' ?>

  Company id:  <?= $user['company_id'] ?? '' ?>

  Logged in:   <?= isset($user['logged_in_at']) ? date('c', (int)$user['logged_in_at']) : '(unknown)' ?>


  Where /index.php would send this user:
    <?= default_landing_url((string)($user['role'] ?? 'viewer')) ?>


  Warehouse access (ANDed with role; super_admin = all):
<?php
    $ids = user_warehouse_ids();
    if (!$ids) {
        echo "    (none assigned)\n";
    } else {
        $stmt = db()->prepare(
            "SELECT id, code, name FROM warehouses
              WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
              ORDER BY code"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $w) {
            echo "    - " . str_pad((string)$w['code'], 8) . $w['name'] . " (id={$w['id']})\n";
        }
    }
?>
<?php else: ?>
  (NOT signed in — $_SESSION['user'] is empty)
<?php endif; ?>


Session
-------
  Session name:      <?= $cookieName ?>

  Session id:        <?= session_id() ?>

  Save path:         <?= $savePath ?>

  Save path writable? <?= is_writable($savePath) ? 'YES' : 'NO' ?>

  Cookie present in this request? <?= strpos($cookieIn, $cookieName . '=') !== false ? 'YES' : 'NO' ?>

  Browser sent <?= $cookieName ?> = <?= e_($cookieValue) ?>

  PHP's session_id matches cookie? <?= $cookieValue === session_id() ? 'YES' : 'NO  <-- if NO, browser is sending a stale id PHP rejected' ?>


All session keys
----------------
<?php
$dump = $_SESSION;
if (!$dump) {
    echo "  (empty)\n";
} else {
    foreach ($dump as $k => $v) {
        if (is_scalar($v)) {
            $line = (string)$v;
        } else {
            $line = json_encode($v, JSON_UNESCAPED_SLASHES);
        }
        if (mb_strlen($line) > 200) $line = mb_substr($line, 0, 200) . '…';
        echo "  $k = $line\n";
    }
}
?>

Request
-------
  Scheme:                <?= $isHttps ? 'HTTPS' : 'HTTP' ?>

  Host:                  <?= $_SERVER['HTTP_HOST'] ?? '' ?>

  Path:                  <?= $_SERVER['REQUEST_URI'] ?? '' ?>

  Method:                <?= $_SERVER['REQUEST_METHOD'] ?? '' ?>

  Referer:               <?= $_SERVER['HTTP_REFERER'] ?? '(none)' ?>


How to use
==========
1. Sign out (top-right "Sign out") so you start clean.
2. Open /login.php in a fresh tab, type a non-admin email + ChangeMe!2026,
   click Sign in.
3. You should land on either /m/home.php (mobile-first roles) or
   /index.php (others). The role-coloured banner on the dashboard tells
   you who you are.
4. Now visit /_auth_test.php. The "Email" + "Role" lines above should
   match the user you just signed in as.
5. If they do, the auth chain works correctly.
6. If they DON'T match, share this page output with the dev so the
   discrepancy can be diagnosed.

When done diagnosing: DELETE /_auth_test.php from your hosting account.
