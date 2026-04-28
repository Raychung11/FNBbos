<?php
/**
 * Root entry point.
 *
 * The real document root is /public — this file exists for shared-hosting
 * setups where the document root is fixed at the repo root. It just forwards
 * to /public/. For best security, point your web server's document root at
 * /public directly so this file is never served.
 */

declare(strict_types=1);

$publicIndex = __DIR__ . '/public/index.php';

if (PHP_SAPI === 'cli-server') {
    // `php -S host:port` from the repo root: serve /public/* directly.
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $candidate = __DIR__ . '/public' . $uri;
    if ($uri !== '/' && is_file($candidate)) {
        return false; // let the built-in server stream the static asset
    }
    require $publicIndex;
    return;
}

// Apache / Nginx: 302 to /public/.
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
header('Location: ' . $base . '/public/', true, 302);
exit;
