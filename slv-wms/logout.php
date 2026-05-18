<?php
// SLV WMS — logout.php
// Purpose: Destroy session and redirect to login.
// Roles allowed: any
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

logout();

// Honour ?next=<same-origin path> so callers (e.g. the "Sign out" link
// on /login.php's already-signed-in banner) can route back where they
// want. Defaults to /login.php; rejects external URLs.
$next = (string)($_GET['next'] ?? '/login.php');
if (!preg_match('#^/[^/\\\\]#', $next)) {
    $next = '/login.php';
}
redirect($next);
