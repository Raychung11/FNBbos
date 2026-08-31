<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;

Auth::requireLogin();
Rbac::require('dashboard.view');

$companyId = Auth::companyId();
$from = (string)(input('from') ?: date('Y-m-01'));
$to   = (string)(input('to')   ?: date('Y-m-d'));

$pdo = db();

function kpi(PDO $pdo, string $sql, array $args, string $col = null) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $row = $stmt->fetch();
    if (!$row) return 0.0;
    return $col ? (float)($row[$col] ?? 0) : (float)reset($row);
}

$grossSales   = kpi($pdo, 'SELECT COALESCE(SUM(gross_sales),0) FROM sales_orders WHERE company_id=? AND order_date BETWEEN ? AND ?', [$companyId, $from, $to]);
$totalTax     = kpi($pdo, 'SELECT COALESCE(SUM(tax_amount),0) FROM sales_fee_calculations sfc JOIN sales_orders so ON so.id=sfc.sales_order_id WHERE so.company_id=? AND so.order_date BETWEEN ? AND ?', [$companyId, $from, $to]);
$platformFees = kpi($pdo, 'SELECT COALESCE(SUM(commission_amount + payment_fee_amount + service_fee_amount),0) FROM sales_fee_calculations sfc JOIN sales_orders so ON so.id=sfc.sales_order_id WHERE so.company_id=? AND so.order_date BETWEEN ? AND ?', [$companyId, $from, $to]);
$netSettle    = kpi($pdo, 'SELECT COALESCE(SUM(net_settlement_system),0) FROM sales_fee_calculations sfc JOIN sales_orders so ON so.id=sfc.sales_order_id WHERE so.company_id=? AND so.order_date BETWEEN ? AND ?', [$companyId, $from, $to]);
$claimTotal   = kpi($pdo, 'SELECT COALESCE(SUM(amount),0) FROM claims WHERE company_id=? AND claim_date BETWEEN ? AND ?', [$companyId, $from, $to]);
$claimApproved= kpi($pdo, 'SELECT COUNT(*) FROM claims WHERE company_id=? AND approval_status="approved" AND claim_date BETWEEN ? AND ?', [$companyId, $from, $to]);
$claimRejected= kpi($pdo, 'SELECT COUNT(*) FROM claims WHERE company_id=? AND approval_status="rejected" AND claim_date BETWEEN ? AND ?', [$companyId, $from, $to]);
$claimHigh    = kpi($pdo, 'SELECT COUNT(*) FROM claims WHERE company_id=? AND risk_level="high" AND claim_date BETWEEN ? AND ?', [$companyId, $from, $to]);
$claimCritical= kpi($pdo, 'SELECT COUNT(*) FROM claims WHERE company_id=? AND risk_level="critical" AND claim_date BETWEEN ? AND ?', [$companyId, $from, $to]);
$leakage      = kpi($pdo, 'SELECT COALESCE(SUM(ABS(difference)),0) FROM sales_fee_calculations sfc JOIN sales_orders so ON so.id=sfc.sales_order_id WHERE so.company_id=? AND reconciliation_status IN ("over_deducted","under_deducted","fee_discrepancy","tax_discrepancy") AND so.order_date BETWEEN ? AND ?', [$companyId, $from, $to]);

$topSuspect = $pdo->prepare('
    SELECT u.name AS claimant, COUNT(*) AS hits, MAX(c.risk_score) AS max_score
    FROM claims c JOIN users u ON u.id = c.claimant_id
    WHERE c.company_id = ? AND c.claim_date BETWEEN ? AND ? AND c.risk_level IN ("high","critical")
    GROUP BY u.id, u.name ORDER BY hits DESC, max_score DESC LIMIT 5');
$topSuspect->execute([$companyId, $from, $to]);
$suspects = $topSuspect->fetchAll();

$worstOutlets = $pdo->prepare('
    SELECT o.name, COALESCE(SUM(c.amount), 0) AS claim_paid
    FROM outlets o
    LEFT JOIN claims c ON c.outlet_id = o.id AND c.claim_date BETWEEN ? AND ? AND c.approval_status IN ("approved","paid")
    WHERE o.company_id = ?
    GROUP BY o.id, o.name ORDER BY claim_paid DESC LIMIT 5');
$worstOutlets->execute([$from, $to, $companyId]);
$outletList = $worstOutlets->fetchAll();

$pageTitle = 'Management Dashboard';
$active    = 'dashboard';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header">
  <div>
    <h2>Operations overview</h2>
    <p>From <?= e($from) ?> to <?= e($to) ?>. Filter and drill-down across outlets, platforms, claim risk levels.</p>
  </div>
  <form method="get" class="toolbar">
    <div class="form-row"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="form-row"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
    <button class="btn btn--primary" type="submit">Apply</button>
  </form>
</div>

<div class="kpi-grid">
  <div class="kpi"><div class="label">Gross sales</div><div class="value"><?= e(money($grossSales)) ?></div></div>
  <div class="kpi"><div class="label">Platform fees</div><div class="value"><?= e(money($platformFees)) ?></div></div>
  <div class="kpi"><div class="label">Net settlement (system)</div><div class="value"><?= e(money($netSettle)) ?></div></div>
  <div class="kpi"><div class="label">SST / tax</div><div class="value"><?= e(money($totalTax)) ?></div></div>
  <div class="kpi"><div class="label">Claims (period)</div><div class="value"><?= e(money($claimTotal)) ?></div></div>
  <div class="kpi kpi--ok"><div class="label">Approved claims</div><div class="value"><?= (int)$claimApproved ?></div></div>
  <div class="kpi kpi--warn"><div class="label">High risk claims</div><div class="value"><?= (int)$claimHigh ?></div></div>
  <div class="kpi kpi--err"><div class="label">Critical risk claims</div><div class="value"><?= (int)$claimCritical ?></div></div>
  <div class="kpi kpi--err"><div class="label">Profit leakage</div><div class="value"><?= e(money($leakage)) ?></div><div class="delta">From fee/tax discrepancies</div></div>
</div>

<div class="card">
  <h3>Top suspicious claimants</h3>
  <?php if (!$suspects): ?><div class="empty">No high-risk claimants in this period.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr><th>Claimant</th><th class="num">High/Critical claims</th><th class="num">Max risk score</th></tr></thead>
      <tbody>
      <?php foreach ($suspects as $s): ?>
        <tr><td><?= e($s['claimant']) ?></td><td class="num"><?= (int)$s['hits'] ?></td><td class="num"><?= (int)$s['max_score'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Top loss-making outlets (by claims paid)</h3>
  <?php if (!$outletList): ?><div class="empty">No data for this period.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr><th>Outlet</th><th class="num">Claims paid</th></tr></thead>
      <tbody>
      <?php foreach ($outletList as $o): ?>
        <tr><td><?= e($o['name']) ?></td><td class="num"><?= e(money((float)$o['claim_paid'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
