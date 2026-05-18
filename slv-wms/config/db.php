<?php
// SLV WMS — config/db.php
// Purpose: Bootstrap loader. Reads config/app.php and exposes $CONFIG globally.
//          Every page/api endpoint requires this exactly once.
// Roles allowed: n/a (loaded by every entry point)
// Last updated: 2026-04-29

declare(strict_types=1);

$__cfg_path = __DIR__ . '/app.php';
if (!is_file($__cfg_path)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "SLV WMS: config/app.php is missing.\n"
       . "Copy config/app.example.php to config/app.php and edit credentials.\n";
    exit;
}

/** @var array $CONFIG */
$CONFIG = require $__cfg_path;

if (!is_array($CONFIG) || empty($CONFIG['db']) || empty($CONFIG['app'])) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "SLV WMS: config/app.php is malformed.\n";
    exit;
}

date_default_timezone_set($CONFIG['app']['timezone'] ?? 'Asia/Kuala_Lumpur');

if (!empty($CONFIG['app']['production'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', dirname(__DIR__) . '/storage/logs/php-error.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
