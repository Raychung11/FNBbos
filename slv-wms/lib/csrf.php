<?php
// SLV WMS — lib/csrf.php
// Purpose: Per-session CSRF token. Use csrf_field() in every form,
//          verify_csrf() at the top of every state-changing handler.
// Roles allowed: n/a
// Last updated: 2026-04-29

declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e_(csrf_token()) . '">';
}

/**
 * Verify the submitted CSRF token. Halts the request on failure.
 * Reads _csrf from POST body or X-CSRF-Token header (for JSON API calls).
 */
function verify_csrf(): void
{
    $submitted = $_POST['_csrf']
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = $_SESSION['_csrf'] ?? '';
    if (!is_string($submitted) || $submitted === '' || !hash_equals($expected, $submitted)) {
        http_response_code(419);
        header('Content-Type: text/plain; charset=utf-8');
        echo "CSRF token mismatch. Please reload the page and try again.";
        exit;
    }
}
