<?php
declare(strict_types=1);

namespace FNBBOS;

/**
 * Role-based access control. Roles map to a set of permission slugs; pages call
 * Rbac::require('claims.approve') etc. Super Admin gets all permissions implicitly.
 */
final class Rbac
{
    public const ROLE_SUPER_ADMIN    = 'super_admin';
    public const ROLE_COMPANY_ADMIN  = 'company_admin';
    public const ROLE_FINANCE_ADMIN  = 'finance_admin';
    public const ROLE_AREA_MANAGER   = 'area_manager';
    public const ROLE_OUTLET_MANAGER = 'outlet_manager';
    public const ROLE_STAFF          = 'staff';
    public const ROLE_AUDITOR        = 'auditor';

    public static function hydrate(int $roleId): void
    {
        $stmt = \db()->prepare('SELECT slug, name FROM roles WHERE id = ?');
        $stmt->execute([$roleId]);
        $role = $stmt->fetch();
        if (!$role) {
            $_SESSION['auth']['role_slug']   = '';
            $_SESSION['auth']['permissions'] = [];
            return;
        }
        $perms = \db()->prepare('
            SELECT p.slug
            FROM role_permissions rp
            JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.role_id = ?
        ');
        $perms->execute([$roleId]);
        $_SESSION['auth']['role_slug']   = $role['slug'];
        $_SESSION['auth']['role_name']   = $role['name'];
        $_SESSION['auth']['permissions'] = array_column($perms->fetchAll(), 'slug');
    }

    public static function role(): string
    {
        return (string)($_SESSION['auth']['role_slug'] ?? '');
    }

    public static function isSuperAdmin(): bool
    {
        return self::role() === self::ROLE_SUPER_ADMIN;
    }

    public static function can(string $permission): bool
    {
        if (!Auth::check()) return false;
        if (self::isSuperAdmin()) return true;
        $perms = $_SESSION['auth']['permissions'] ?? [];
        return in_array($permission, $perms, true);
    }

    public static function require(string $permission): void
    {
        Auth::requireLogin();
        if (!self::can($permission)) {
            http_response_code(403);
            echo '<h1>403 — Forbidden</h1><p>You do not have permission to access this page.</p>';
            echo '<p><a href="' . \e(\url('pages/dashboard.php')) . '">Back to dashboard</a></p>';
            exit;
        }
    }

    /** Restrict a query to the user's company scope (and outlet for outlet-bound roles). */
    public static function scopeCompany(string $tableAlias = ''): array
    {
        $col = $tableAlias ? $tableAlias . '.company_id' : 'company_id';
        return [' AND ' . $col . ' = ?', [Auth::companyId()]];
    }
}
