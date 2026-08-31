<?php
declare(strict_types=1);

namespace FNBBOS;

/**
 * Session-based authentication. Stores the user id, role and active company/outlet
 * scope in the session. Passwords are hashed with PASSWORD_BCRYPT.
 */
final class Auth
{
    public static function attempt(string $email, string $password): bool
    {
        $stmt = \db()->prepare('SELECT id, name, email, password_hash, role_id, company_id, default_outlet_id, is_active FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || (int)$user['is_active'] !== 1) {
            return false;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }
        if (password_needs_rehash($user['password_hash'], \config('security.password_algo'), ['cost' => \config('security.password_cost')])) {
            $newHash = password_hash($password, \config('security.password_algo'), ['cost' => \config('security.password_cost')]);
            $u = \db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $u->execute([$newHash, $user['id']]);
        }
        self::login($user);
        AuditLog::record('user.login', 'user', (int)$user['id'], ['email' => $email]);
        return true;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['auth'] = [
            'id'         => (int)$user['id'],
            'name'       => $user['name'],
            'email'      => $user['email'],
            'role_id'    => (int)$user['role_id'],
            'company_id' => (int)$user['company_id'],
            'outlet_id'  => $user['default_outlet_id'] !== null ? (int)$user['default_outlet_id'] : null,
            'last_seen'  => time(),
        ];
        // Hydrate role + permissions
        Rbac::hydrate((int)$user['role_id']);
    }

    public static function logout(): void
    {
        if (self::check()) {
            AuditLog::record('user.logout', 'user', self::id());
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        return isset($_SESSION['auth']['id']);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            \flash('error', 'Please log in to continue.');
            \redirect('login.php');
        }
        $lifetime = (int)\config('security.session_lifetime', 3600);
        if (!empty($_SESSION['auth']['remember'])) {
            // "Remember me" extends the idle window to 30 days. The session
            // cookie itself is reissued at login time so it survives browser
            // restarts; in production back this with a server-side persistent
            // token if you need true cross-device durability.
            $lifetime = 60 * 60 * 24 * 30;
        }
        if (time() - (int)($_SESSION['auth']['last_seen'] ?? 0) > $lifetime) {
            self::logout();
            \flash('error', 'Session expired.');
            \redirect('login.php');
        }
        $_SESSION['auth']['last_seen'] = time();
    }

    public static function user(): ?array
    {
        return $_SESSION['auth'] ?? null;
    }

    public static function id(): int
    {
        return (int)($_SESSION['auth']['id'] ?? 0);
    }

    /**
     * Effective company scope. A Super Admin operating the SaaS can switch
     * the active company via the topbar; that override is honoured here so
     * every scoped query follows the selection. Non-super-admins are always
     * pinned to their own company.
     */
    public static function companyId(): int
    {
        if (Rbac::isSuperAdmin() && !empty($_SESSION['auth']['active_company_id'])) {
            return (int)$_SESSION['auth']['active_company_id'];
        }
        return (int)($_SESSION['auth']['company_id'] ?? 0);
    }

    /** The user's home company (ignores any Super Admin switch). */
    public static function homeCompanyId(): int
    {
        return (int)($_SESSION['auth']['company_id'] ?? 0);
    }

    /** Super Admin only: switch the active company scope. */
    public static function setActiveCompany(?int $companyId): void
    {
        if (!Rbac::isSuperAdmin()) return;
        if ($companyId === null) {
            unset($_SESSION['auth']['active_company_id']);
            return;
        }
        $stmt = \db()->prepare('SELECT 1 FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        if ($stmt->fetchColumn()) {
            $_SESSION['auth']['active_company_id'] = $companyId;
        }
    }

    public static function outletId(): ?int
    {
        $v = $_SESSION['auth']['outlet_id'] ?? null;
        return $v === null ? null : (int)$v;
    }

    public static function roleId(): int
    {
        return (int)($_SESSION['auth']['role_id'] ?? 0);
    }
}
