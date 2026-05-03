<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;
use FNBBOS\Csrf;
use FNBBOS\AuditLog;
use FNBBOS\Engine\Forecaster;

Auth::requireLogin();
Rbac::require('forecast.view');

$companyId = Auth::companyId();
$outletId  = asInt(input('outlet_id'))   ?: null;
$platformId= asInt(input('platform_id')) ?: null;
$days      = max(7, min(60, asInt(input('days')) ?: 30));

if (requestMethod() === 'POST') {
    Csrf::requireValid();
    try {
        Forecaster::generateAndStore($companyId, $outletId, $platformId, $days);
        AuditLog::record('forecast.generate', 'sales_forecast', null, [
            'outlet_id' => $outletId, 'platform_id' => $platformId, 'days' => $days,
        ]);
        flash('ok', 'Forecast regenerated.');
    } catch (Throwable $e) {
        flash('error', 'Forecast failed: ' . $e->getMessage());
    }
    redirect('pages/forecast.php?days=' . $days
        . ($outletId ? '&outlet_id=' . $outletId : '')
        . ($platformId ? '&platform_id=' . $platformId : ''));
}

// Live forecast (don't require regenerate to view).
$forecast = Forecaster::salesForecast($companyId, $outletId, $platformId, $days);

// History for the chart context.
$history = db()->prepare('
    SELECT DATE(order_date) AS d, SUM(gross_sales) AS gross
    FROM sales_orders
    WHERE company_id = ?
      AND order_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
      ' . ($outletId   ? 'AND outlet_id = '   . (int)$outletId   : '') . '
      ' . ($platformId ? 'AND platform_id = ' . (int)$platformId : '') . '
    GROUP BY DATE(order_date) ORDER BY d');
$history->execute([$companyId]);
$history = $history->fetchAll();

// Claim projection per category.
$claimProj = Forecaster::claimMonthlyProjection($companyId, $outletId);

$outlets   = db()->prepare('SELECT id, name FROM outlets WHERE company_id = ? AND is_active = 1 ORDER BY name'); $outlets->execute([$companyId]);   $outlets   = $outlets->fetchAll();
$platforms = db()->prepare('SELECT id, name FROM platforms WHERE company_id = ? AND is_active = 1 ORDER BY name'); $platforms->execute([$companyId]); $platforms = $platforms->fetchAll();

$pageTitle = 'Forecast';
$active    = 'forecast';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header">
  <div><h2>Sales &amp; claim forecast</h2><p>Lightweight time-series projection: day-of-week seasonality + 90-day trend with a confidence band. Claim projection extrapolates current MTD pace.</p></div>
</div>

<form method="get" class="card toolbar">
  <div class="form-row"><label>Outlet</label>
    <select name="outlet_id"><option value="">All</option>
      <?php foreach ($outlets as $o): ?><option value="<?= (int)$o['id'] ?>" <?= $outletId==$o['id']?'selected':'' ?>><?= e($o['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="form-row"><label>Platform</label>
    <select name="platform_id"><option value="">All</option>
      <?php foreach ($platforms as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $platformId==$p['id']?'selected':'' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="form-row"><label>Days ahead</label><input type="number" name="days" value="<?= (int)$days ?>" min="7" max="60"></div>
  <button class="btn btn--primary" type="submit">Apply</button>
  <form method="post" style="display:inline;">
    <?= Csrf::field() ?>
    <input type="hidden" name="outlet_id"   value="<?= e((string)$outletId) ?>">
    <input type="hidden" name="platform_id" value="<?= e((string)$platformId) ?>">
    <input type="hidden" name="days"        value="<?= (int)$days ?>">
    <button class="btn btn--ghost" type="submit">Save snapshot</button>
  </form>
</form>

<div class="card">
  <h3>Sales forecast (next <?= (int)$days ?> days)</h3>
  <?php if (!$forecast): ?>
    <div class="empty">Need at least 14 days of history. Import or sync more sales first.</div>
  <?php else: ?>
    <canvas id="cFcst" height="180"></canvas>
    <table class="data" style="margin-top:14px;">
      <thead><tr><th>Date</th><th class="num">Low</th><th class="num">Mid</th><th class="num">High</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($forecast, 0, 14) as $f): ?>
        <tr><td><?= e($f['date']) ?></td><td class="num"><?= e(money($f['low'])) ?></td><td class="num"><?= e(money($f['mid'])) ?></td><td class="num"><?= e(money($f['high'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="hint">Showing first 14 days; full <?= (int)$days ?>-day series rendered in the chart above.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Claim spend month-end projection</h3>
  <?php if (!$claimProj): ?>
    <div class="empty">No claims yet this month.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr><th>Claim type</th><th class="num">MTD</th><th class="num">Projected month-end</th></tr></thead>
      <tbody>
        <?php foreach ($claimProj as $c): ?>
          <tr><td><?= e($c['claim_type']) ?></td><td class="num"><?= e(money($c['mtd'])) ?></td><td class="num"><?= e(money($c['projected_eom'])) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php if ($forecast): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const histLabels = <?= json_encode(array_column($history, 'd')) ?>;
const histVals   = <?= json_encode(array_map(fn($r) => (float)$r['gross'], $history)) ?>;
const fcLabels   = <?= json_encode(array_column($forecast, 'date')) ?>;
const fcLow      = <?= json_encode(array_map(fn($r) => (float)$r['low'],  $forecast)) ?>;
const fcMid      = <?= json_encode(array_map(fn($r) => (float)$r['mid'],  $forecast)) ?>;
const fcHigh     = <?= json_encode(array_map(fn($r) => (float)$r['high'], $forecast)) ?>;
const labels = histLabels.concat(fcLabels);
const fmtRM = v => 'RM ' + Number(v||0).toLocaleString('en-MY',{minimumFractionDigits:2,maximumFractionDigits:2});

new Chart(document.getElementById('cFcst'), {
  type: 'line',
  data: {
    labels,
    datasets: [
      { label: 'History (gross)', data: histVals.concat(Array(fcLabels.length).fill(null)), borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.1)', fill: true, tension: .3 },
      { label: 'Forecast mid',   data: Array(histLabels.length).fill(null).concat(fcMid),  borderColor: '#16a34a', borderDash: [4,4], tension: .3 },
      { label: 'Forecast low',   data: Array(histLabels.length).fill(null).concat(fcLow),  borderColor: '#a3e635', borderDash: [2,4], tension: .3 },
      { label: 'Forecast high',  data: Array(histLabels.length).fill(null).concat(fcHigh), borderColor: '#84cc16', borderDash: [2,4], tension: .3 },
    ]
  },
  options: { responsive: true, scales: { y: { ticks: { callback: v => fmtRM(v) } } } }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
