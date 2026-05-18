<?php
declare(strict_types=1);

namespace FNBBOS;

/**
 * Append-only audit trail. Every financial mutation, claim state change, login,
 * import, and reconciliation should call AuditLog::record(). Reads happen via
 * the Audit Logs page.
 */
final class AuditLog
{
    public static function record(string $action, string $entity, ?int $entityId = null, array $context = []): void
    {
        try {
            $stmt = \db()->prepare('
                INSERT INTO audit_logs (company_id, user_id, action, entity_type, entity_id, context_json, ip_address, user_agent, created_at)
                VALUES (?,?,?,?,?,?,?,?,?)
            ');
            $stmt->execute([
                Auth::check() ? Auth::companyId() : null,
                Auth::check() ? Auth::id() : null,
                $action,
                $entity,
                $entityId,
                json_encode($context, JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR']     ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                \nowDb(),
            ]);
        } catch (\Throwable $e) {
            // Never let audit failure break the app — but surface it in error log.
            error_log('AuditLog failed: ' . $e->getMessage());
        }
    }

    public static function recordFinancial(string $action, string $entity, int $entityId, array $before, array $after): void
    {
        self::record($action, $entity, $entityId, ['before' => $before, 'after' => $after]);
    }
}
