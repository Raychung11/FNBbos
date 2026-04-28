<?php
/**
 * Application bootstrap.
 * Loads config, sets timezone, registers autoloader, opens session, and exposes
 * helpers used across the application.
 */

declare(strict_types=1);

if (defined('FNBBOS_BOOTED')) {
    return;
}
define('FNBBOS_BOOTED', true);

define('FNBBOS_ROOT', dirname(__DIR__));
define('FNBBOS_SRC', FNBBOS_ROOT . '/src');
define('FNBBOS_CONFIG', FNBBOS_ROOT . '/config');
define('FNBBOS_PUBLIC', FNBBOS_ROOT . '/public');
define('FNBBOS_STORAGE', FNBBOS_ROOT . '/storage');

// Load config (fall back to example for first-run friendliness)
$configFile = FNBBOS_CONFIG . '/config.php';
if (!file_exists($configFile)) {
    $configFile = FNBBOS_CONFIG . '/config.example.php';
}
$config = require $configFile;
$GLOBALS['fnbbos_config'] = $config;

date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Kuala_Lumpur');

if ($config['app']['debug'] ?? false) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}

// Simple PSR-4-ish autoloader for FNBBOS namespace
spl_autoload_register(function (string $class): void {
    $prefix = 'FNBBOS\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = FNBBOS_SRC . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

// Helpers (procedural)
require FNBBOS_SRC . '/Support/helpers.php';

// Start session with hardened settings
$sec = $config['security'];
session_name($sec['session_name']);
session_set_cookie_params([
    'lifetime' => (int)$sec['session_lifetime'],
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Lazy-init DB available via db()
$GLOBALS['fnbbos_pdo'] = null;
