<?php
declare(strict_types=1);

namespace FNBBOS\Engine;

/**
 * Configurable tax engine. Tax rates are NEVER hard-coded; they are read from
 * the tax_profiles / tax_rules tables. Supports inclusive vs exclusive modes
 * and per-outlet / per-platform overrides with effective dates.
 */
final class TaxEngine
{
    /**
     * Resolve the tax rule that applies to a given (outlet, platform, date).
     * Returns the most specific active rule, falling back to the outlet's default profile.
     */
    public static function resolveRule(?int $outletId, ?int $platformId, string $date): ?array
    {
        $sql = '
            SELECT tr.*, tp.is_inclusive, tp.name AS profile_name
            FROM tax_rules tr
            JOIN tax_profiles tp ON tp.id = tr.tax_profile_id
            WHERE tr.is_active = 1
              AND tr.effective_from <= ?
              AND (tr.effective_to IS NULL OR tr.effective_to >= ?)
              AND (tr.outlet_id IS NULL OR tr.outlet_id = ?)
              AND (tr.platform_id IS NULL OR tr.platform_id = ?)
            ORDER BY (tr.outlet_id IS NOT NULL) DESC,
                     (tr.platform_id IS NOT NULL) DESC,
                     tr.effective_from DESC
            LIMIT 1
        ';
        $stmt = \db()->prepare($sql);
        $stmt->execute([$date, $date, $outletId, $platformId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Calculate tax given a gross amount and the resolved rule.
     * Returns: ['taxable' => x, 'tax' => y, 'rate' => r, 'inclusive' => bool].
     */
    public static function calculate(float $gross, array $rule): array
    {
        $rate = (float)$rule['rate'];
        $inclusive = (int)$rule['is_inclusive'] === 1;
        if ($rate <= 0.0) {
            return ['taxable' => $gross, 'tax' => 0.0, 'rate' => 0.0, 'inclusive' => $inclusive];
        }
        if ($inclusive) {
            $taxable = $gross / (1 + $rate);
            $tax     = $gross - $taxable;
        } else {
            $taxable = $gross;
            $tax     = $gross * $rate;
        }
        return [
            'taxable'   => round($taxable, 4),
            'tax'       => round($tax, 4),
            'rate'      => $rate,
            'inclusive' => $inclusive,
        ];
    }
}
