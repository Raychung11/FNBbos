<?php
// SLV WMS — lib/auth.php
// Purpose: Session bootstrapping + login/logout + role & warehouse-access
//          middleware. Every page/api endpoint calls require_login() first.
// Roles allowed: n/a (auth itself)
// Last updated: 2026-04-29

declare(strict_types=1);

/**
 * Start the PHP session with secure defaults. Idempotent.
 *
 * Secure-cookie behaviour: the config flag `app.cookie_secure` only takes
 * effect when the current request is actually over HTTPS. Setting it on a
 * plain-HTTP request would cause the browser to silently drop the cookie,
 * breaking sessions and producing a "CSRF token mismatch" loop on first
 * deploy. We honour the user's intent by upgrading to Secure as soon as
 * HTTPS is detected (including via `X-Forwarded-Proto: https` from a
 * reverse proxy).
 */
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    global $CONFIG;
    $name        = $CONFIG['app']['session_name']  ?? 'slvwms_sess';
    $wantsSecure = !empty($CONFIG['app']['cookie_secure']);

    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $secure = $wantsSecure && $isHttps;

    session_name($name);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Attempt login. On success, stores user payload in session and returns true.
 */
function attempt_login(string $email, string $password): bool
{
    $stmt = db()->prepare(
        "SELECT id, company_id, name, email, password_hash, role, status
           FROM users
          WHERE email = ?
          LIMIT 1"
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row || $row['status'] !== 'ACTIVE') {
        return false;
    }
    if (!password_verify($password, $row['password_hash'])) {
        return false;
    }

    if (password_needs_rehash($row['password_hash'], PASSWORD_BCRYPT)) {
        $new = password_hash($password, PASSWORD_BCRYPT);
        $upd = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upd->execute([$new, $row['id']]);
    }

    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
        ->execute([$row['id']]);

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'         => (int)$row['id'],
        'company_id' => (int)$row['company_id'],
        'name'       => $row['name'],
        'email'      => $row['email'],
        'role'       => $row['role'],
        'logged_in_at' => time(),
    ];
    $_SESSION['_warehouse_ids'] = load_user_warehouse_ids((int)$row['id'], $row['role']);

    audit_log('login', 'user', (int)$row['id']);
    return true;
}

function logout(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $uid = $_SESSION['user']['id'] ?? null;
        if ($uid) {
            audit_log('logout', 'user', (int)$uid);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user']['id']);
}

/**
 * Block the request if the user is not logged in.
 *  - HTML pages    → 302 to /login.php
 *  - API endpoints → 401 JSON
 */
function require_login(): void
{
    if (is_logged_in()) {
        return;
    }
    if (is_api_request()) {
        json_error(401, 'Not authenticated');
    }
    redirect('/login.php');
}

/**
 * Allow only listed roles. Pass either a single role or a list.
 */
function require_role($roles): void
{
    require_login();
    $u = current_user();
    if (!$u) {
        return; // require_login already exited
    }
    $list = is_array($roles) ? $roles : [$roles];
    if (in_array($u['role'], $list, true)) {
        return;
    }
    if (is_api_request()) {
        json_error(403, 'Forbidden');
    }
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

/**
 * Confirm the current user has access to a specific warehouse.
 * super_admin bypasses this check.
 */
function require_warehouse_access(int $warehouse_id): void
{
    require_login();
    $u = current_user();
    if (!$u) {
        return;
    }
    if ($u['role'] === 'super_admin') {
        return;
    }
    $ids = $_SESSION['_warehouse_ids'] ?? [];
    if (in_array($warehouse_id, $ids, true)) {
        return;
    }
    if (is_api_request()) {
        json_error(403, 'No access to this warehouse');
    }
    http_response_code(403);
    echo 'You do not have access to this warehouse.';
    exit;
}

/**
 * Return list of warehouse ids the current user can operate in.
 * super_admin → all warehouses for the company.
 */
function user_warehouse_ids(): array
{
    $u = current_user();
    if (!$u) {
        return [];
    }
    if ($u['role'] === 'super_admin') {
        $stmt = db()->prepare('SELECT id FROM warehouses WHERE company_id = ? ORDER BY code');
        $stmt->execute([$u['company_id']]);
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }
    return $_SESSION['_warehouse_ids'] ?? [];
}

function load_user_warehouse_ids(int $user_id, string $role): array
{
    if ($role === 'super_admin') {
        // Resolved on demand by user_warehouse_ids(); session list left empty.
        return [];
    }
    $stmt = db()->prepare(
        'SELECT warehouse_id FROM user_warehouse_access WHERE user_id = ?'
    );
    $stmt->execute([$user_id]);
    return array_map('intval', array_column($stmt->fetchAll(), 'warehouse_id'));
}

// -----------------------------------------------------------------------------
// Selected-warehouse helpers (dashboard filter; persists in session)
// -----------------------------------------------------------------------------

function selected_warehouse_id(): ?int
{
    $v = $_SESSION['_selected_warehouse_id'] ?? null;
    return $v === null ? null : (int)$v;
}

function set_selected_warehouse_id(?int $id): void
{
    if ($id === null) {
        unset($_SESSION['_selected_warehouse_id']);
        return;
    }
    require_warehouse_access($id);
    $_SESSION['_selected_warehouse_id'] = $id;
}

// -----------------------------------------------------------------------------
// Request-shape helpers
// -----------------------------------------------------------------------------

function is_api_request(): bool
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, '/api/') !== false) {
        return true;
    }
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return stripos($accept, 'application/json') !== false;
}

function json_error(int $code, string $message, array $extra = []): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_ok(array $data = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $data),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
