<?php
declare(strict_types=1);

namespace FNBBOS;

/**
 * Resolves the next required approver role for a claim, based on the
 * configurable approval_rules table. The first matching active rule (in
 * priority order) wins.
 */
final class ApprovalRouter
{
    public static function nextApproverRole(int $companyId, string $claimType, float $amount, string $riskLevel): ?array
    {
        $sql = '
            SELECT *
            FROM approval_rules
            WHERE company_id = ?
              AND is_active = 1
              AND (claim_type = ? OR claim_type = "*")
              AND amount_min <= ?
              AND (amount_max IS NULL OR amount_max >= ?)
              AND (risk_threshold IS NULL OR FIND_IN_SET(?, risk_threshold))
            ORDER BY priority ASC, amount_min DESC
            LIMIT 1';
        $stmt = \db()->prepare($sql);
        $stmt->execute([$companyId, $claimType, $amount, $amount, $riskLevel]);
        $rule = $stmt->fetch();
        return $rule ?: null;
    }
}
