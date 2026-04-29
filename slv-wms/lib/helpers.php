<?php
// SLV WMS — lib/helpers.php
// Purpose: Procedural helpers used across every page (escaping, money/qty
//          formatting, redirects, flash messages, settings cache).
// Roles allowed: n/a
// Last updated: 2026-04-29

declare(strict_types=1);

// -----------------------------------------------------------------------------
// Output escaping
// -----------------------------------------------------------------------------

/**
 * Escape a value for HTML output. Always use this around any echoed variable.
 */
function e_($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Escape for HTML attribute (alias of e_ for now; kept for readability).
 */
function attr_($v): string
{
    return e_($v);
}

// -----------------------------------------------------------------------------
// Money / quantity formatting (display only)
// -----------------------------------------------------------------------------

/**
 * Format a money value for display, e.g. money(1234.5) → "RM 1,234.50".
 * Currency symbol is read from app_settings.app.currency, default "RM".
 */
function money($n, ?string $symbol = null): string
{
    $symbol = $symbol ?? setting('app.currency', 'RM');
    $n = (float)$n;
    return $symbol . ' ' . number_format($n, 2, '.', ',');
}

/**
 * Format a quantity. Integer values display without decimals; fractional
 * quantities preserve up to 4dp with trailing zeros stripped.
 */
function qty($n): string
{
    $n = (float)$n;
    if (abs($n - round($n)) < 0.00005) {
        return number_format($n, 0, '.', ',');
    }
    $s = number_format($n, 4, '.', ',');
    return rtrim(rtrim($s, '0'), '.');
}

// -----------------------------------------------------------------------------
// HTTP helpers
// -----------------------------------------------------------------------------

function redirect(string $url, int $code = 302): void
{
    header('Location: ' . $url, true, $code);
    exit;
}

function base_url(string $path = ''): string
{
    global $CONFIG;
    $base = rtrim($CONFIG['app']['base_url'] ?? '', '/');
    if ($path === '') {
        return $base;
    }
    return $base . '/' . ltrim($path, '/');
}

function current_url_path(): string
{
    return strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
}

// -----------------------------------------------------------------------------
// Flash messages (one-shot, session-backed)
// -----------------------------------------------------------------------------

function flash(string $type, string $message): void
{
    if (!isset($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
        $_SESSION['_flash'] = [];
    }
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Drain & return all flash messages. Call once per request, in the layout.
 *
 * @return array<int,array{type:string,message:string}>
 */
function flash_drain(): array
{
    $out = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($out) ? $out : [];
}

// -----------------------------------------------------------------------------
// Settings cache  (per-request)
// -----------------------------------------------------------------------------

/**
 * Read an app_settings value, falling back to $default if the key is unset.
 * Cached per-request. Pass force-reload by calling setting_reload().
 */
function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = setting_reload();
    }
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    return $default;
}

/**
 * Force-reload the settings cache from DB. Returns the populated map.
 *
 * @return array<string, mixed>
 */
function setting_reload(): array
{
    global $CONFIG;
    $companyId = (int)($CONFIG['app']['company_id'] ?? 1);

    $stmt = db()->prepare(
        'SELECT key_name, value_text, value_type FROM app_settings WHERE company_id = ?'
    );
    $stmt->execute([$companyId]);

    $out = [];
    while ($row = $stmt->fetch()) {
        $out[$row['key_name']] = setting_cast($row['value_text'], $row['value_type']);
    }
    return $out;
}

function setting_cast(?string $raw, string $type)
{
    if ($raw === null) {
        return null;
    }
    switch ($type) {
        case 'int':     return (int)$raw;
        case 'decimal': return (float)$raw;
        case 'bool':    return in_array(strtolower($raw), ['1','true','yes','on'], true);
        case 'json':
            $j = json_decode($raw, true);
            return is_array($j) ? $j : null;
        default:
            return $raw; // string/color/path
    }
}

/**
 * Resolve the active company id from config. Centralised so v2 multi-company
 * can swap this for a session-scoped value.
 */
function company_id(): int
{
    global $CONFIG;
    return (int)($CONFIG['app']['company_id'] ?? 1);
}

// -----------------------------------------------------------------------------
// Audit log
// -----------------------------------------------------------------------------

function audit_log(string $action, string $entity_type, ?int $entity_id = null, $payload = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_logs
               (company_id, user_id, action, entity_type, entity_id, payload_json, ip, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            company_id(),
            $_SESSION['user']['id'] ?? null,
            $action,
            $entity_type,
            $entity_id,
            $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[SLV WMS] audit_log failed: ' . $e->getMessage());
    }
}

// -----------------------------------------------------------------------------
// Misc
// -----------------------------------------------------------------------------

function uuid_v4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
