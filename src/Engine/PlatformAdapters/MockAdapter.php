<?php
declare(strict_types=1);

namespace FNBBOS\Engine\PlatformAdapters;

/**
 * Mock platform adapter. Generates plausible orders for any outlet code
 * the credentials reference, so a tenant can demo the API sync flow end-to-end
 * without real partner integrations.
 *
 * Replace with a real adapter (GrabFood / Foodpanda / ShopeeFood) once partner
 * credentials are available.
 */
final class MockAdapter implements AdapterInterface
{
    public function __construct(
        private array $credentials,
        private string $platformCode = 'mock'
    ) {}

    public function name(): string { return $this->platformCode; }

    public function fetchOrders(string $from, string $to): array
    {
        $outletCodes = (array)($this->credentials['outlet_codes'] ?? ['MAIN-01']);
        $start = strtotime($from);
        $end   = strtotime($to);
        if ($start === false || $end === false || $start > $end) return [];

        $out  = [];
        $rng  = $this->seededRng($from . $to . $this->platformCode);
        for ($t = $start; $t <= $end; $t += 86400) {
            $date = date('Y-m-d', $t);
            $dayOfWeek = (int)date('w', $t);
            $ordersToday = 6 + ($dayOfWeek >= 5 ? 4 : 0); // busier on weekends
            for ($i = 0; $i < $ordersToday; $i++) {
                $outlet = $outletCodes[$rng() % count($outletCodes)];
                $gross  = round(20 + ($rng() % 8000) / 100, 2); // RM 20–100
                $sst    = round($gross / 1.08 * 0.08, 2);
                $commission = round($gross * 0.25, 2);
                $payFee     = round($gross * 0.015, 2);
                $voucher    = ($rng() % 5 === 0) ? round(($rng() % 500) / 100, 2) : 0.0;
                $net    = round($gross - $commission - $payFee - $voucher, 2);
                $out[] = [
                    'order_id'             => sprintf('%s-%s-%05d', strtoupper($this->platformCode), date('Ymd', $t), 1000 + $i),
                    'order_date'           => $date,
                    'gross_sales'          => $gross,
                    'item_subtotal'        => round($gross - $sst, 2),
                    'service_charge'       => 0.0,
                    'sst_amount'           => $sst,
                    'discount'             => 0.0,
                    'voucher'              => $voucher,
                    'refund'               => 0.0,
                    'platform_commission'  => $commission,
                    'payment_fee'          => $payFee,
                    'delivery_fee'         => 0.0,
                    'adjustment'           => 0.0,
                    'net_settlement'       => $net,
                    'settlement_date'      => date('Y-m-d', $t + 86400 * 7),
                    'bank_reference'       => 'MOCK-' . date('Ymd', $t),
                    'outlet_code'          => $outlet,
                ];
            }
        }
        return $out;
    }

    /** Deterministic PRNG so re-running for the same window yields the same orders. */
    private function seededRng(string $seed): \Closure
    {
        $state = crc32($seed);
        return function () use (&$state) {
            $state = ($state * 1103515245 + 12345) & 0x7fffffff;
            return $state;
        };
    }
}
