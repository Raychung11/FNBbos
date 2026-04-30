<?php
// SLV WMS — _session_test.php
// Purpose: TEMPORARY diagnostic. Reload this page 3 times in the same browser
//          tab. The counter should go 1, 2, 3. If it stays at 1 the browser
//          isn't returning the session cookie — see the hints at the bottom.
// Roles allowed: anyone (no auth gate; deletes after you're done diagnosing)
// SECURITY: shows session id and current session contents. DELETE THIS FILE
//          immediately after you finish diagnosing.

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$counter = (int)($_SESSION['_test_counter'] ?? 0) + 1;
$_SESSION['_test_counter']  = $counter;
$_SESSION['_test_last_seen'] = date('c');

$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

$savePath = session_save_path();
if ($savePath === '') {
    $savePath = (string)ini_get('session.save_path');
}
if ($savePath === '') {
    $savePath = sys_get_temp_dir();
}
$savePathWritable = is_writable($savePath);

$cookieIn   = $_SERVER['HTTP_COOKIE'] ?? '';
$cookieName = session_name();
$cookieSawIt = strpos($cookieIn, $cookieName . '=') !== false;

$cookieParams = session_get_cookie_params();

header('Content-Type: text/plain; charset=utf-8');
?>
SLV WMS — Session round-trip test
=================================

Counter (reload this page; should go 1, 2, 3, ...): <?= $counter ?>


Request
-------
  Method:                <?= $_SERVER['REQUEST_METHOD'] ?? '' ?>

  Host:                  <?= $_SERVER['HTTP_HOST'] ?? '' ?>

  Server port:           <?= $_SERVER['SERVER_PORT'] ?? '' ?>

  Scheme detected:       <?= $isHttps ? 'HTTPS' : 'HTTP' ?>

  HTTPS env var:         <?= $_SERVER['HTTPS'] ?? '(unset)' ?>

  X-Forwarded-Proto:     <?= $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '(unset)' ?>

  Cookie header in:      <?= $cookieIn === '' ? '(none)' : substr($cookieIn, 0, 200) ?>

  Cookie '<?= $cookieName ?>' present in request? <?= $cookieSawIt ? 'YES' : 'NO' ?>


Session
-------
  Cookie name:           <?= $cookieName ?>

  Session id:            <?= session_id() ?>

  Save path:             <?= $savePath ?>

  Save path writable?    <?= $savePathWritable ? 'YES' : 'NO  ← this is the problem if NO' ?>

  Cookie params being set on next response:
    lifetime: <?= var_export($cookieParams['lifetime'] ?? null, true) ?>

    path:     <?= var_export($cookieParams['path']     ?? null, true) ?>

    domain:   <?= var_export($cookieParams['domain']   ?? null, true) ?>

    secure:   <?= var_export($cookieParams['secure']   ?? null, true) ?>

    httponly: <?= var_export($cookieParams['httponly'] ?? null, true) ?>

    samesite: <?= var_export($cookieParams['samesite'] ?? null, true) ?>


Session data
------------
<?php
$dump = $_SESSION;
foreach ($dump as $k => $v) {
    $line = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_SLASHES);
    if (mb_strlen($line) > 120) $line = mb_substr($line, 0, 120) . '…';
    echo "  $k = $line\n";
}
?>

PHP / server
------------
  PHP version:           <?= PHP_VERSION ?>

  session.use_cookies:   <?= ini_get('session.use_cookies') ?>

  session.use_only_cookies: <?= ini_get('session.use_only_cookies') ?>

  session.use_strict_mode:  <?= ini_get('session.use_strict_mode') ?>

  session.gc_maxlifetime:   <?= ini_get('session.gc_maxlifetime') ?>


How to read this
================
1. Reload the page 2-3 times. The Counter should increment.
   - If counter stays at 1: the browser isn't returning the session cookie.
     · "Cookie params being set" shows secure=true on an HTTP request → fix:
        run over HTTPS, or set cookie_secure=false in config/app.php.
     · The cookie may be blocked (private browsing, third-party-cookie-blocking).
     · An ad blocker or extension may be stripping it.
2. If "Save path writable?" is NO: PHP can't persist the session at all.
   Set session.save_path in php.ini to a writable directory, or ask Hostinger.
3. If the counter increments here but /login.php still shows CSRF mismatch,
   something else is going on — share this whole page output with the dev.

When done diagnosing: DELETE /_session_test.php from your hosting account.
