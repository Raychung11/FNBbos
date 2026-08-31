<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;

Auth::requireLogin();
Rbac::require('reports.view');

$companyId = Auth::companyId();
$from = (string)(input('from') ?: date('Y-m-01'));
$to   = (string)(input('to')   ?: date('Y-m-d'));

$pdo = db();

// 1. Sales over time
$sa = $pdo->prepare('
    SELECT DATE(order_date) AS d,
           SUM(gross_sales) AS gross,
           SUM(sfc.commission_amount + sfc.payment_fee_amount + sfc.service_fee_amount) AS fees,
           SUM(sfc.net_settlement_system) AS net
    FROM sales_orders so
    JOIN sales_fee_calculations sfc ON sfc.sales_order_id = so.id
    WHERE so.company_id = ? AND so.order_date BETWEEN ? AND ?
    GROUP BY DATE(order_date) ORDER BY d');
$sa->execute([$companyId, $from, $to]);
$series = $sa->fetchAll();

// 2. Platform mix
$pm = $pdo->prepare('
    SELECT p.name AS platform, SUM(so.gross_sales) AS gross
    FROM sales_orders so
    JOIN platforms p ON p.id = so.platform_id
    WHERE so.company_id = ? AND so.order_date BETWEEN ? AND ?
    GROUP BY p.id, p.name ORDER BY gross DESC');
$pm->execute([$companyId, $from, $to]);
$platforms = $pm->fetchAll();

// 3. Outlet revenue
$or = $pdo->prepare('
    SELECT o.name AS outlet, SUM(sfc.net_settlement_system) AS net
    FROM outlets o
    LEFT JOIN sales_orders so ON so.outlet_id = o.id AND so.order_date BETWEEN ? AND ?
    LEFT JOIN sales_fee_calculations sfc ON sfc.sales_order_id = so.id
    WHERE o.company_id = ?
    GROUP BY o.id, o.name ORDER BY net DESC LIMIT 12');
$or->execute([$from, $to, $companyId]);
$outlets = $or->fetchAll();

// 4. Risk distribution
$rd = $pdo->prepare('
    SELECT risk_level, COUNT(*) AS cnt
    FROM claims
    WHERE company_id = ? AND claim_date BETWEEN ? AND ?
    GROUP BY risk_level');
$rd->execute([$companyId, $from, $to]);
$riskDist = ['low'=>0,'medium'=>0,'high'=>0,'critical'=>0];
foreach ($rd->fetchAll() as $r) $riskDist[$r['risk_level']] = (int)$r['cnt'];

// 5. Reconciliation status breakdown
$rs = $pdo->prepare('
    SELECT sfc.reconciliation_status AS status, COUNT(*) AS cnt
    FROM sales_orders so
    JOIN sales_fee_calculations sfc ON sfc.sales_order_id = so.id
    WHERE so.company_id = ? AND so.order_date BETWEEN ? AND ?
    GROUP BY sfc.reconciliation_status');
$rs->execute([$companyId, $from, $to]);
$reconStatus = $rs->fetchAll();

// 6. Claim type spend
$ct = $pdo->prepare('
    SELECT claim_type, SUM(amount) AS total
    FROM claims
    WHERE company_id = ? AND claim_date BETWEEN ? AND ? AND approval_status IN ("approved","paid")
    GROUP BY claim_type ORDER BY total DESC');
$ct->execute([$companyId, $from, $to]);
$claimTypes = $ct->fetchAll();

$pageTitle = 'Insights';
$active    = 'insights';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header">
  <div><h2>Insights</h2><p>Trends, mix and distribution. Hover any chart for exact figures.</p></div>
  <form method="get" class="toolbar">
    <div class="form-row"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="form-row"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
    <button class="btn btn--primary" type="submit">Apply</button>
  </form>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:18px;">
  <div class="card"><h3>Sales over time</h3><canvas id="cSeries" height="180"></canvas></div>
  <div class="card"><h3>Platform mix (gross)</h3><canvas id="cPlatform" height="180"></canvas></div>
  <div class="card"><h3>Top outlets (net settlement)</h3><canvas id="cOutlet" height="180"></canvas></div>
  <div class="card"><h3>Claim risk distribution</h3><canvas id="cRisk" height="180"></canvas></div>
  <div class="card"><h3>Reconciliation status</h3><canvas id="cRecon" height="180"></canvas></div>
  <div class="card"><h3>Claim spend by type (approved/paid)</h3><canvas id="cClaim" height="180"></canvas></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const PALETTE = ['#2563eb','#0ea5a4','#f97316','#dc2626','#a855f7','#16a34a','#eab308','#0891b2','#db2777','#475569'];
const fmtRM = v => 'RM ' + Number(v||0).toLocaleString('en-MY',{minimumFractionDigits:2,maximumFractionDigits:2});

// 1. Sales over time
new Chart(document.getElementById('cSeries'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_column($series,'d')) ?>,
    datasets: [
      { label: 'Gross', data: <?= json_encode(array_map(fn($r)=>(float)$r['gross'],$series)) ?>, borderColor: PALETTE[0], backgroundColor: 'rgba(37,99,235,.1)', fill: true, tension: .3 },
      { label: 'Fees',  data: <?= json_encode(array_map(fn($r)=>(float)$r['fees'], $series)) ?>, borderColor: PALETTE[3], tension: .3 },
      { label: 'Net',   data: <?= json_encode(array_map(fn($r)=>(float)$r['net'],  $series)) ?>, borderColor: PALETTE[5], tension: .3 },
    ]
  },
  options: { responsive: true, scales: { y: { ticks: { callback: v => fmtRM(v) } } } }
});

// 2. Platform mix
new Chart(document.getElementById('cPlatform'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_column($platforms,'platform')) ?>,
    datasets: [{ data: <?= json_encode(array_map(fn($r)=>(float)$r['gross'],$platforms)) ?>, backgroundColor: PALETTE }]
  },
  options: { plugins: { tooltip: { callbacks: { label: ctx => ctx.label + ': ' + fmtRM(ctx.parsed) } } } }
});

// 3. Outlet revenue
new Chart(document.getElementById('cOutlet'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($outlets,'outlet')) ?>,
    datasets: [{ label: 'Net (RM)', data: <?= json_encode(array_map(fn($r)=>(float)$r['net'],$outlets)) ?>, backgroundColor: PALETTE[0] }]
  },
  options: { indexAxis: 'y', scales: { x: { ticks: { callback: v => fmtRM(v) } } } }
});

// 4. Risk distribution
new Chart(document.getElementById('cRisk'), {
  type: 'bar',
  data: {
    labels: ['Low','Medium','High','Critical'],
    datasets: [{
      data: [<?= (int)$riskDist['low'] ?>, <?= (int)$riskDist['medium'] ?>, <?= (int)$riskDist['high'] ?>, <?= (int)$riskDist['critical'] ?>],
      backgroundColor: ['#16a34a','#eab308','#f97316','#dc2626']
    }]
  },
  options: { plugins: { legend: { display: false } } }
});

// 5. Reconciliation status
new Chart(document.getElementById('cRecon'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_column($reconStatus,'status')) ?>,
    datasets: [{ data: <?= json_encode(array_map(fn($r)=>(int)$r['cnt'],$reconStatus)) ?>, backgroundColor: PALETTE }]
  }
});

// 6. Claim type spend
new Chart(document.getElementById('cClaim'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($claimTypes,'claim_type')) ?>,
    datasets: [{ label: 'Spend (RM)', data: <?= json_encode(array_map(fn($r)=>(float)$r['total'],$claimTypes)) ?>, backgroundColor: PALETTE[2] }]
  },
  options: { scales: { y: { ticks: { callback: v => fmtRM(v) } } } }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
