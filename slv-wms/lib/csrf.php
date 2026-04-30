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
 *
 * On failure we render a small HTML page with actionable hints. The most
 * common cause on a fresh deploy is an HTTP-only host with cookie_secure=1
 * in config; session_boot() now auto-disables Secure on plain HTTP, but
 * other causes (multi-tab, expired session, third-party cookie blocking)
 * still produce this error.
 */
function verify_csrf(): void
{
    $submitted = $_POST['_csrf']
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = $_SESSION['_csrf'] ?? '';
    if (is_string($submitted) && $submitted !== '' && $expected !== '' && hash_equals($expected, $submitted)) {
        return;
    }

    http_response_code(419);

    $isApi = (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false)
          || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => false,
            'error' => 'csrf_token_mismatch',
            'hint'  => 'Reload the page and resubmit. If this persists, your browser may not be returning the session cookie (common on plain HTTP with cookie_secure=true).',
        ]);
        exit;
    }

    $back   = $_SERVER['HTTP_REFERER'] ?? '/index.php';
    $hasSession = $expected !== '';
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Session security check failed</title>
<script src="https://cdn.tailwindcss.com"></script>
</head><body class="min-h-screen bg-gray-50 flex items-center justify-center p-4">
  <div class="max-w-lg w-full bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
    <h1 class="text-lg font-semibold text-gray-900 mb-2">Session security check failed</h1>
    <p class="text-sm text-gray-600 mb-4">
      Your form's CSRF token didn't match what's in your session, so the request was blocked.
    </p>
    <p class="text-sm text-gray-700 font-medium mb-1">Most common causes:</p>
    <ul class="list-disc list-inside text-sm text-gray-600 space-y-1 mb-4">
      <li>You logged out or the session expired in another tab — <strong>reload</strong> the form.</li>
      <li>You have the form open in two tabs — submit the most recently loaded one.</li>
      <?php if (!$hasSession): ?>
      <li class="text-amber-700"><strong>Your browser isn't returning a session cookie.</strong> If your site is on plain HTTP, set <code class="bg-amber-50 px-1 rounded">cookie_secure</code> to <code class="bg-amber-50 px-1 rounded">false</code> in <code class="bg-amber-50 px-1 rounded">config/app.php</code>, or move the site behind HTTPS.</li>
      <?php endif; ?>
    </ul>
    <div class="flex gap-2">
      <a href="<?= htmlspecialchars($back, ENT_QUOTES, 'UTF-8') ?>"
         class="inline-block px-4 py-2 bg-indigo-600 text-white rounded text-sm">Reload the page</a>
      <a href="/index.php" class="inline-block px-4 py-2 border border-gray-300 rounded text-sm text-gray-700">Dashboard</a>
    </div>
  </div>
</body></html>
    <?php
    exit;
}
