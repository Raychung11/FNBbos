<?php
declare(strict_types=1);

namespace FNBBOS\Engine;

/**
 * Sales / claim forecasting. Lightweight time-series forecast that combines:
 *   - day-of-week seasonality (mean for Mon..Sun from the lookback window)
 *   - linear trend (last 28d slope vs first 28d of the lookback window)
 *   - a confidence band sized to the residual std-dev
 *
 * Output is written to the sales_forecasts table so the UI can display it
 * without recomputing on every page load. Re-running generate() replaces the
 * previous forecast for the same scope.
 *
 * This is deliberately not an ML library — it produces sane projections from
 * 60+ days of history without any extra dependencies. Plug in Prophet / a
 * dedicated forecasting service later if needed.
 */
final class Forecaster
{
    public const LOOKBACK_DAYS = 90;

    /**
     * Generate a sales forecast for the next $daysAhead days. Scope is
     * (company_id, optional outlet_id, optional platform_id).
     *
     * @return array<int,array{date:string,low:float,mid:float,high:float}>
     */
    public static function salesForecast(int $companyId, ?int $outletId, ?int $platformId, int $daysAhead = 30): array
    {
        $history = self::loadDailySales($companyId, $outletId, $platformId, self::LOOKBACK_DAYS);
        if (count($history) < 14) return []; // not enough history

        // 1. Day-of-week mean.
        $dow = array_fill(0, 7, ['sum' => 0.0, 'cnt' => 0]);
        foreach ($history as $row) {
            $idx = (int)date('w', strtotime($row['date'])); // 0=Sun..6=Sat
            $dow[$idx]['sum'] += (float)$row['gross'];
            $dow[$idx]['cnt']++;
        }
        $dowMean = [];
        foreach ($dow as $i => $b) $dowMean[$i] = $b['cnt'] > 0 ? $b['sum'] / $b['cnt'] : 0.0;

        // 2. Linear trend: compare mean of last 28d vs first 28d.
        $values  = array_map(fn($r) => (float)$r['gross'], $history);
        $n       = count($values);
        $window  = min(28, intdiv($n, 2));
        $tailAvg = array_sum(array_slice($values, -$window)) / $window;
        $headAvg = array_sum(array_slice($values, 0, $window)) / $window;
        $dailyTrend = ($tailAvg - $headAvg) / max($n - $window, 1);

        // 3. Residual std-dev for a confidence band.
        $overallMean = array_sum($values) / $n;
        $sumSq = 0.0;
        foreach ($values as $v) $sumSq += ($v - $overallMean) ** 2;
        $stdDev = sqrt($sumSq / $n);

        // 4. Project forward.
        $forecasts = [];
        $startDate = (new \DateTimeImmutable('tomorrow'))->setTime(0, 0);
        for ($i = 0; $i < $daysAhead; $i++) {
            $date = $startDate->modify("+{$i} days");
            $idx  = (int)$date->format('w');
            $base = $dowMean[$idx] ?? $overallMean;
            $mid  = max(0.0, $base + ($dailyTrend * ($i + 1)));
            $forecasts[] = [
                'date' => $date->format('Y-m-d'),
                'low'  => round(max(0.0, $mid - $stdDev * 0.8), 2),
                'mid'  => round($mid, 2),
                'high' => round($mid + $stdDev * 0.8, 2),
            ];
        }
        return $forecasts;
    }

    /**
     * Project a claim category's monthly spend by extrapolating the current
     * month-to-date pace. Used by the Leakage Predictor.
     */
    public static function claimMonthlyProjection(int $companyId, ?int $outletId = null): array
    {
        $sql = '
            SELECT claim_type, SUM(amount) AS mtd
            FROM claims
            WHERE company_id = ?
              AND claim_date BETWEEN ? AND ?
              AND approval_status IN ("approved","paid","submitted")';
        $args = [$companyId, date('Y-m-01'), date('Y-m-d')];
        if ($outletId) { $sql .= ' AND outlet_id = ?'; $args[] = $outletId; }
        $sql .= ' GROUP BY claim_type';

        $stmt = \db()->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();

        $today    = (int)date('j');
        $lastDay  = (int)date('t');
        $factor   = $today > 0 ? $lastDay / $today : 1.0;

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'claim_type'   => $r['claim_type'],
                'mtd'          => (float)$r['mtd'],
                'projected_eom'=> round((float)$r['mtd'] * $factor, 2),
            ];
        }
        return $out;
    }

    /**
     * Persist the forecast (replacing any previous forecast for the same scope).
     * Returns the forecasts that were written.
     */
    public static function generateAndStore(int $companyId, ?int $outletId, ?int $platformId, int $daysAhead = 30): array
    {
        $forecasts = self::salesForecast($companyId, $outletId, $platformId, $daysAhead);
        $pdo = \db();
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM sales_forecasts WHERE company_id = ? AND
                ((? IS NULL AND outlet_id IS NULL) OR outlet_id = ?) AND
                ((? IS NULL AND platform_id IS NULL) OR platform_id = ?)');
            $del->execute([$companyId, $outletId, $outletId, $platformId, $platformId]);

            $ins = $pdo->prepare('
                INSERT INTO sales_forecasts
                    (company_id, outlet_id, platform_id, forecast_date,
                     forecast_low, forecast_mid, forecast_high, generated_at)
                VALUES (?,?,?,?,?,?,?,?)');
            $now = \nowDb();
            foreach ($forecasts as $f) {
                $ins->execute([
                    $companyId, $outletId, $platformId, $f['date'],
                    $f['low'], $f['mid'], $f['high'], $now,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $forecasts;
    }

    private static function loadDailySales(int $companyId, ?int $outletId, ?int $platformId, int $lookbackDays): array
    {
        $sql = '
            SELECT DATE(order_date) AS date, SUM(gross_sales) AS gross
            FROM sales_orders
            WHERE company_id = ?
              AND order_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              AND order_date < CURDATE()';
        $args = [$companyId, $lookbackDays];
        if ($outletId)   { $sql .= ' AND outlet_id = ?';   $args[] = $outletId; }
        if ($platformId) { $sql .= ' AND platform_id = ?'; $args[] = $platformId; }
        $sql .= ' GROUP BY DATE(order_date) ORDER BY date';

        $stmt = \db()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }
}
