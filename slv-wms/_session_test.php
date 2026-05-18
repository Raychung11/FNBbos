<?php
// SLV WMS — _session_test.php   (DELETE AFTER DIAGNOSING)
// Reload this page 2-3 times in the same tab and share the third reload's
// output. The counter must increment.

declare(strict_types=1);

// Capture session_start errors silently.
$startErr = '';
set_error_handler(function ($n, $msg) use (&$startErr) { $startErr = $msg; return true; });

require __DIR__ . '/lib/bootstrap.php';

$counter = (int)($_SESSION['_test_counter'] ?? 0) + 1;
$_SESSION['_test_counter']  = $counter;
$_SESSION['_test_last_seen'] = date('c');
restore_error_handler();

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
$cookieFromCookieArr = $_COOKIE[$cookieName] ?? '(not in $_COOKIE)';
$sid          = session_id();
$sidMatchesCookie = ($cookieFromCookieArr !== '(not in $_COOKIE)') && ($sid === $cookieFromCookieArr);
$cookieParams = session_get_cookie_params();

// Look for our session file on disk.
$sessFile      = rtrim($savePath, '/') . '/sess_' . $sid;
$sessFileExists = is_file($sessFile);
$sessFileSize   = $sessFileExists ? (int)filesize($sessFile) : 0;
$sessDirCount   = is_dir($savePath) ? (count(@scandir($savePath) ?: []) - 2) : 0;

// Independent persistence test: write a counter to a flat file in storage/.
$flatPath = __DIR__ . '/storage/imports/_session_test_counter.txt';
$flatBefore = is_file($flatPath) ? (int)trim((string)@file_get_contents($flatPath)) : 0;
$flatAfter  = $flatBefore + 1;
$flatWriteOk = @file_put_contents($flatPath, (string)$flatAfter, LOCK_EX) !== false;

// Independent persistence test 2: write a sentinel into the session save path
// directly — bypasses PHP's session handler entirely.
$sentinelFile = rtrim($savePath, '/') . '/slv_sentinel.txt';
$sentinelBefore = is_file($sentinelFile) ? (int)trim((string)@file_get_contents($sentinelFile)) : 0;
$sentinelAfter  = $sentinelBefore + 1;
$sentinelWriteOk = @file_put_contents($sentinelFile, (string)$sentinelAfter, LOCK_EX) !== false;

header('Content-Type: text/plain; charset=utf-8');
?>
SLV WMS — Session round-trip test (v2)
========================================

Counter (reload this page; should go 1, 2, 3, ...): <?= $counter ?>


Cross-check counters (independent of PHP sessions)
--------------------------------------------------
  Flat file in /storage/imports/  (was -> now): <?= $flatBefore ?> -> <?= $flatAfter ?>  (write ok? <?= $flatWriteOk ? 'yes' : 'NO' ?>)
  Sentinel in session save path   (was -> now): <?= $sentinelBefore ?> -> <?= $sentinelAfter ?>  (write ok? <?= $sentinelWriteOk ? 'yes' : 'NO' ?>)


Request
-------
  Method:                 <?= $_SERVER['REQUEST_METHOD'] ?? '' ?>

  Host:                   <?= $_SERVER['HTTP_HOST'] ?? '' ?>

  Server port:            <?= $_SERVER['SERVER_PORT'] ?? '' ?>

  Scheme detected:        <?= $isHttps ? 'HTTPS' : 'HTTP' ?>

  HTTPS env var:          <?= $_SERVER['HTTPS'] ?? '(unset)' ?>

  X-Forwarded-Proto:      <?= $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '(unset)' ?>


Session cookie inspection
-------------------------
  Cookie name:                            <?= $cookieName ?>

  Browser sent slvwms_sess (parsed):      <?= e_($cookieFromCookieArr) ?>

  PHP's session_id() this request:        <?= $sid ?>

  Browser cookie matches PHP session id?  <?= $sidMatchesCookie ? 'YES' : 'NO  <-- this is the bug if NO' ?>


Session file on disk
--------------------
  Save path:                              <?= $savePath ?>

  Save path writable?                     <?= $savePathWritable ? 'YES' : 'NO' ?>

  Expected file:                          <?= $sessFile ?>

  Expected file exists?                   <?= $sessFileExists ? 'YES' : 'NO' ?>

  Expected file size (bytes):             <?= $sessFileSize ?>

  Files in save path (visible to us):     <?= $sessDirCount ?>


session_start() error captured
------------------------------
  <?= $startErr === '' ? '(none)' : $startErr ?>


Cookie params being set on next response
----------------------------------------
  lifetime: <?= var_export($cookieParams['lifetime'] ?? null, true) ?>

  path:     <?= var_export($cookieParams['path']     ?? null, true) ?>

  domain:   <?= var_export($cookieParams['domain']   ?? null, true) ?>

  secure:   <?= var_export($cookieParams['secure']   ?? null, true) ?>

  httponly: <?= var_export($cookieParams['httponly'] ?? null, true) ?>

  samesite: <?= var_export($cookieParams['samesite'] ?? null, true) ?>


Session data (this render, after we just wrote to it)
-----------------------------------------------------
<?php foreach ($_SESSION as $k => $v):
    $line = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_SLASHES);
    if (mb_strlen($line) > 120) $line = mb_substr($line, 0, 120) . '…';
?>
  <?= $k ?> = <?= $line ?>

<?php endforeach; ?>

PHP / server
------------
  PHP version:                  <?= PHP_VERSION ?>

  session.use_cookies:          <?= ini_get('session.use_cookies') ?>

  session.use_only_cookies:     <?= ini_get('session.use_only_cookies') ?>

  session.use_strict_mode:      <?= ini_get('session.use_strict_mode') ?>

  session.cookie_lifetime:      <?= ini_get('session.cookie_lifetime') ?>

  session.gc_maxlifetime:       <?= ini_get('session.gc_maxlifetime') ?>

  session.gc_probability:       <?= ini_get('session.gc_probability') ?>

  session.gc_divisor:           <?= ini_get('session.gc_divisor') ?>

  session.save_handler:         <?= ini_get('session.save_handler') ?>

  session.cache_limiter:        <?= ini_get('session.cache_limiter') ?>


Reading
=======
Reload this page 3 times. Pay attention to:

  * Counter — must be 1, 2, 3.
  * Flat-file counter — proves disk writes work generally.
  * Sentinel counter — proves writes specifically to the session save path work.
  * "Browser cookie matches PHP session id?" — must be YES on reload #2 onwards.
  * "Expected file exists?" — must be YES on reload #2 onwards.

Common patterns:

  * Flat=ok, sentinel=ok, but session counter stuck at 1
       → PHP's session_write_close() is silently failing. Check open_basedir
         restrictions, CageFS, or a corrupted session.save_handler setting.
  * Flat=ok, sentinel write FAILS
       → Save path looks writable to is_writable() but file_put_contents fails.
         Likely an SELinux/CageFS quirk. Move sessions to a project-local dir.
  * Counter increments but cookie/id mismatch shows NO
       → Browser is dropping the response cookie (privacy mode, extension).

DELETE this file when done.
