<?php
// SLV WMS — lib/tax.php
// Purpose: Multi-tax line + invoice computation. Supports compound taxes
//          (e.g. service tax stacked on top of SST) and produces a
//          per-tax-code breakdown that the Phase 8 invoice header stores
//          in invoice_taxes for SST filing.
//
// Convention: rates are stored as decimal fractions (0.0600 = 6%). Money
// totals are rounded to 2dp at the line level so SUM(line_total) matches
// the displayed grand total down to the sen.
// Last updated: 2026-04-30

declare(strict_types=1);

/**
 * Per-request cache of (group_id => [code rows]). Saves a query per line
 * when computing a multi-line invoice/SO.
 */
function tax_group_codes(int $group_id): array
{
    static $cache = [];
    if (isset($cache[$group_id])) {
        return $cache[$group_id];
    }
    $stmt = db()->prepare(
        "SELECT tc.id, tc.code, tc.name, tc.rate, tc.is_compound, tgc.sort_order
           FROM tax_group_codes tgc
           JOIN tax_codes       tc ON tc.id = tgc.tax_code_id
          WHERE tgc.group_id = ? AND tc.is_active = 1
       ORDER BY tgc.sort_order, tc.id"
    );
    $stmt->execute([$group_id]);
    return $cache[$group_id] = $stmt->fetchAll();
}

/**
 * Compute the tax breakdown for one line at the given subtotal.
 *
 * Compound stacking: a non-compound code is applied to the bare subtotal;
 * a compound code is applied to the running total (subtotal + previously-
 * applied taxes), modelling regimes where one tax sits on top of another.
 *
 * @return array{
 *   subtotal:float,
 *   tax_total:float,
 *   line_total:float,
 *   breakdown: array<int,array{
 *     tax_code_id:int, code:string, name:string, rate:float,
 *     is_compound:int, taxable_amount:float, tax_amount:float
 *   }>
 * }
 */
function tax_compute_line(float $subtotal, ?int $tax_group_id): array
{
    $subtotal = round($subtotal, 2);
    $out = [
        'subtotal'   => $subtotal,
        'tax_total'  => 0.0,
        'line_total' => $subtotal,
        'breakdown'  => [],
    ];
    if (!$tax_group_id) {
        return $out;
    }
    $codes = tax_group_codes($tax_group_id);
    if (!$codes) {
        return $out;
    }
    $running = $subtotal;
    foreach ($codes as $c) {
        $base = ((int)$c['is_compound'] === 1) ? $running : $subtotal;
        $tax  = round($base * (float)$c['rate'], 2);
        $out['breakdown'][] = [
            'tax_code_id'    => (int)$c['id'],
            'code'           => $c['code'],
            'name'           => $c['name'],
            'rate'           => (float)$c['rate'],
            'is_compound'    => (int)$c['is_compound'],
            'taxable_amount' => round($base, 2),
            'tax_amount'     => $tax,
        ];
        $running += $tax;
    }
    $out['tax_total']  = round($running - $subtotal, 2);
    $out['line_total'] = round($running, 2);
    return $out;
}

/**
 * Aggregate per-line breakdowns into a per-tax-code map for the order/
 * invoice header. Returns the grand totals plus a breakdown the Phase 8
 * invoice generator can persist verbatim into invoice_taxes.
 *
 * @param array<int,array> $line_results  array of tax_compute_line() outputs
 * @return array{
 *   subtotal:float,
 *   tax_total:float,
 *   grand_total:float,
 *   per_code: array<int,array{
 *     tax_code_id:int, code:string, name:string, rate:float,
 *     taxable_amount:float, tax_amount:float
 *   }>
 * }
 */
function tax_aggregate(array $line_results): array
{
    $sub = 0.0; $tax = 0.0; $tot = 0.0;
    $perCode = []; // tax_code_id => row
    foreach ($line_results as $line) {
        $sub += (float)$line['subtotal'];
        $tax += (float)$line['tax_total'];
        $tot += (float)$line['line_total'];
        foreach (($line['breakdown'] ?? []) as $b) {
            $cid = (int)$b['tax_code_id'];
            if (!isset($perCode[$cid])) {
                $perCode[$cid] = [
                    'tax_code_id'    => $cid,
                    'code'           => $b['code'],
                    'name'           => $b['name'],
                    'rate'           => (float)$b['rate'],
                    'taxable_amount' => 0.0,
                    'tax_amount'     => 0.0,
                ];
            }
            $perCode[$cid]['taxable_amount'] += (float)$b['taxable_amount'];
            $perCode[$cid]['tax_amount']     += (float)$b['tax_amount'];
        }
    }
    foreach ($perCode as &$row) {
        $row['taxable_amount'] = round($row['taxable_amount'], 2);
        $row['tax_amount']     = round($row['tax_amount'],     2);
    }
    unset($row);
    return [
        'subtotal'    => round($sub, 2),
        'tax_total'   => round($tax, 2),
        'grand_total' => round($tot, 2),
        'per_code'    => array_values($perCode),
    ];
}
