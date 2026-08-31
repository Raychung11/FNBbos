<?php
/**
 * Procedural helpers used throughout the app.
 */

declare(strict_types=1);

function config(?string $key = null, $default = null)
{
    $cfg = $GLOBALS['fnbbos_config'] ?? [];
    if ($key === null) return $cfg;
    $segments = explode('.', $key);
    $value = $cfg;
    foreach ($segments as $seg) {
        if (is_array($value) && array_key_exists($seg, $value)) {
            $value = $value[$seg];
        } else {
            return $default;
        }
    }
    return $value;
}

function db(): PDO
{
    if ($GLOBALS['fnbbos_pdo'] instanceof PDO) {
        return $GLOBALS['fnbbos_pdo'];
    }
    $c = config('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'], $c['port'], $c['name'], $c['charset']);
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $GLOBALS['fnbbos_pdo'] = $pdo;
    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    $base = rtrim((string)config('app.base_url'), '/');
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function flash(string $key, ?string $message = null)
{
    if ($message === null) {
        $val = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $val;
    }
    $_SESSION['_flash'][$key] = $message;
}

function old(string $key, $default = '')
{
    $val = $_SESSION['_old'][$key] ?? $default;
    return $val;
}

function rememberOld(array $input): void
{
    $_SESSION['_old'] = $input;
}

function clearOld(): void
{
    unset($_SESSION['_old']);
}

function money(float $amount, string $currency = ''): string
{
    $currency = $currency !== '' ? $currency : (string)config('app.currency', 'RM');
    return $currency . ' ' . number_format($amount, 2, '.', ',');
}

function pct(float $value, int $decimals = 2): string
{
    return number_format($value * 100, $decimals) . '%';
}

function nowDb(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function requestMethod(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function input(string $key, $default = null)
{
    $bag = $_POST + $_GET;
    if (!array_key_exists($key, $bag)) return $default;
    $v = $bag[$key];
    if (is_string($v)) {
        $v = trim($v);
    }
    return $v;
}

function asFloat($v, float $default = 0.0): float
{
    if ($v === null || $v === '') return $default;
    if (is_numeric($v)) return (float)$v;
    $clean = preg_replace('/[^0-9.\-]/', '', (string)$v);
    return $clean === '' ? $default : (float)$clean;
}

function asInt($v, int $default = 0): int
{
    if ($v === null || $v === '') return $default;
    if (is_numeric($v)) return (int)$v;
    return $default;
}

function jsonResponse($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function riskLevelFromScore(int $score): string
{
    $t = config('risk.thresholds');
    if ($score <= $t['low'])      return 'low';
    if ($score <= $t['medium'])   return 'medium';
    if ($score <= $t['high'])     return 'high';
    return 'critical';
}

function riskColor(string $level): string
{
    return [
        'low'      => '#16a34a',
        'medium'   => '#eab308',
        'high'     => '#f97316',
        'critical' => '#dc2626',
    ][$level] ?? '#6b7280';
}

function statusBadgeClass(string $status): string
{
    $map = [
        'matched'           => 'badge--ok',
        'received'          => 'badge--ok',
        'approved'          => 'badge--ok',
        'paid'              => 'badge--ok',
        'partially_matched' => 'badge--warn',
        'pending'           => 'badge--warn',
        'submitted'         => 'badge--warn',
        'delayed'           => 'badge--warn',
        'underpaid'         => 'badge--warn',
        'overpaid'          => 'badge--warn',
        'unmatched'         => 'badge--err',
        'fee_discrepancy'   => 'badge--err',
        'tax_discrepancy'   => 'badge--err',
        'missing_settlement'=> 'badge--err',
        'over_deducted'     => 'badge--err',
        'under_deducted'    => 'badge--err',
        'rejected'          => 'badge--err',
        'draft'             => 'badge--muted',
        'inactive'          => 'badge--muted',
    ];
    return $map[$status] ?? 'badge--muted';
}
