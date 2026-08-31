<?php
declare(strict_types=1);

namespace FNBBOS\Engine;

/**
 * Fee calculation engine. Applies platform fee rules (commission %, fixed fee,
 * payment gateway %, delivery / voucher / refund treatment) to a sales order
 * and produces a system-calculated net settlement that can be compared with
 * the platform's reported net settlement to flag discrepancies.
 *
 * No fee rate is ever hard-coded. All values come from platform_fee_rules.
 */
final class FeeEngine
{
    /**
     * Resolve the active fee rule for a platform on a given date.
     */
    public static function resolveRule(int $platformId, string $date): ?array
    {
        $sql = '
            SELECT * FROM platform_fee_rules
            WHERE platform_id = ?
              AND is_active = 1
              AND effective_from <= ?
              AND (effective_to IS NULL OR effective_to >= ?)
            ORDER BY effective_from DESC
            LIMIT 1
        ';
        $stmt = \db()->prepare($sql);
        $stmt->execute([$platformId, $date, $date]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Compute system values for a sales order.
     *
     * Formula:
     *   Gross
     *   - Platform Commission (gross * commission_rate)
     *   - Payment Gateway Fee (gross * payment_fee_rate)
     *   - Platform Service Fee (fixed_fee)
     *   - Voucher Cost (per treatment)
     *   - Promotion Cost
     *   - Refund
     *   - Delivery Subsidy (per treatment)
     *   - Adjustment
     *   - SST / Tax payable (per tax profile)
     *   = Net Settlement
     */
    public static function calculate(array $order, ?array $rule, ?array $taxResult): array
    {
        $gross         = (float)($order['gross_sales']       ?? 0);
        $voucher       = (float)($order['voucher']           ?? 0);
        $promotion     = (float)($order['discount']          ?? 0);
        $refund        = (float)($order['refund']            ?? 0);
        $delivery      = (float)($order['delivery_fee']      ?? 0);
        $adjustment    = (float)($order['adjustment']        ?? 0);

        $commissionRate     = $rule ? (float)$rule['commission_rate']     : 0.0;
        $paymentFeeRate     = $rule ? (float)$rule['payment_fee_rate']    : 0.0;
        $fixedFee           = $rule ? (float)$rule['fixed_fee']           : 0.0;
        $voucherTreatment   = $rule['voucher_treatment']   ?? 'merchant_bears';
        $deliveryTreatment  = $rule['delivery_treatment']  ?? 'platform_bears';
        $refundTreatment    = $rule['refund_treatment']    ?? 'merchant_bears';

        $commission = round($gross * $commissionRate, 4);
        $paymentFee = round($gross * $paymentFeeRate, 4);

        // Voucher cost: merchant_bears means deduct from settlement; platform_bears means do not.
        $voucherCost   = ($voucherTreatment   === 'merchant_bears') ? $voucher   : 0.0;
        $deliverySub   = ($deliveryTreatment  === 'merchant_bears') ? $delivery  : 0.0;
        $refundCost    = ($refundTreatment    === 'merchant_bears') ? $refund    : 0.0;
        $promotionCost = $promotion; // always merchant cost in our model

        $tax = $taxResult['tax'] ?? (float)($order['sst_amount'] ?? 0);

        $net = $gross
            - $commission
            - $paymentFee
            - $fixedFee
            - $voucherCost
            - $promotionCost
            - $refundCost
            - $deliverySub
            - $adjustment
            - $tax;

        return [
            'gross_sales'             => round($gross, 4),
            'commission_amount'       => $commission,
            'payment_fee_amount'      => $paymentFee,
            'service_fee_amount'      => round($fixedFee, 4),
            'voucher_cost'            => round($voucherCost, 4),
            'promotion_cost'          => round($promotionCost, 4),
            'refund_amount'           => round($refundCost, 4),
            'delivery_subsidy'        => round($deliverySub, 4),
            'adjustment_amount'       => round($adjustment, 4),
            'tax_amount'              => round((float)$tax, 4),
            'net_settlement'          => round($net, 4),
            'commission_rate'         => $commissionRate,
            'payment_fee_rate'        => $paymentFeeRate,
            'voucher_treatment'       => $voucherTreatment,
            'delivery_treatment'      => $deliveryTreatment,
            'refund_treatment'        => $refundTreatment,
        ];
    }

    /**
     * Compare imported settlement vs system-calculated and produce a status.
     */
    public static function reconcile(float $imported, float $system, float $importedTax, float $systemTax): array
    {
        $diff       = round($imported - $system, 4);
        $absDiff    = abs($diff);
        $variance   = $system != 0.0 ? $diff / $system : 0.0;
        $taxDiff    = round($importedTax - $systemTax, 4);

        $status = 'matched';
        if ($absDiff < 0.01 && abs($taxDiff) < 0.01) {
            $status = 'matched';
        } elseif ($absDiff < 1.00 && abs($variance) < 0.01) {
            $status = 'partially_matched';
        } elseif (abs($taxDiff) >= 0.01) {
            $status = 'tax_discrepancy';
        } elseif ($diff < 0) {
            $status = 'over_deducted';
        } elseif ($diff > 0) {
            $status = 'under_deducted';
        } else {
            $status = 'fee_discrepancy';
        }

        return [
            'status'       => $status,
            'difference'   => $diff,
            'variance_pct' => round($variance * 100, 4),
            'tax_diff'     => $taxDiff,
        ];
    }
}
