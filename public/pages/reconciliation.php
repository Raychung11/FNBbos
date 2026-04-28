<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;

Auth::requireLogin();
Rbac::require('sales.view');

$companyId = Auth::companyId();
$from = (string)(input('from') ?: date('Y-m-01'));
$to   = (string)(input('to')   ?: date('Y-m-d'));

$summary = db()->prepare('
    SELECT sfc.reconciliation_status AS status,
           COUNT(*) AS orders,
           SUM(so.gross_sales) AS gross,
           SUM(sfc.net_settlement_system) AS sys_net,
           SUM(sfc.net_settlement_imported) AS imp_net,
           SUM(sfc.difference) AS diff
    FROM sales_orders so
    JOIN sales_fee_calculations sfc ON sfc.sales_order_id = so.id
    WHERE so.company_id = ? AND so.order_date BETWEEN ? AND ?
    GROUP BY sfc.reconciliation_status
    ORDER BY orders DESC');
$summary->execute([$companyId, $from, $to]);
$summary = $summary->fetchAll();

$leak = db()->prepare('
    SELECT so.id, so.order_id, so.order_date, p.name AS platform, o.name AS outlet,
           so.gross_sales, sfc.net_settlement_system, sfc.net_settlement_imported,
           sfc.difference, sfc.variance_pct, sfc.reconciliation_status
    FROM sales_orders so
    JOIN sales_fee_calculations sfc ON sfc.sales_order_id = so.id
    JOIN platforms p ON p.id = so.platform_id
    JOIN outlets   o ON o.id = so.outlet_id
    WHERE so.company_id = ? AND so.order_date BETWEEN ? AND ?
      AND sfc.reconciliation_status IN ("over_deducted","under_deducted","fee_discrepancy","tax_discrepancy")
    ORDER BY ABS(sfc.difference) DESC LIMIT 100');
$leak->execute([$companyId, $from, $to]);
$leak = $leak->fetchAll();

$pageTitle = 'Fee Reconciliation';
$active    = 'reconciliation';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header">
  <div><h2>Fee reconciliation</h2><p>System-calculated settlement vs platform-imported settlement. Drill into the worst variances first.</p></div>
  <form method="get" class="toolbar">
    <div class="form-row"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="form-row"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
    <button class="btn btn--primary" type="submit">Apply</button>
  </form>
</div>

<div class="card">
  <h3>Status breakdown</h3>
  <?php if (!$summary): ?><div class="empty">No data.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr><th>Status</th><th class="num">Orders</th><th class="num">Gross</th><th class="num">Net (system)</th><th class="num">Net (imported)</th><th class="num">Difference</th></tr></thead>
      <tbody><?php foreach ($summary as $r): ?>
        <tr>
          <td><span class="badge <?= statusBadgeClass($r['status']) ?>"><?= e($r['status']) ?></span></td>
          <td class="num"><?= (int)$r['orders'] ?></td>
          <td class="num"><?= e(money((float)$r['gross'])) ?></td>
          <td class="num"><?= e(money((float)$r['sys_net'])) ?></td>
          <td class="num"><?= e(money((float)$r['imp_net'])) ?></td>
          <td class="num"><?= e(money((float)$r['diff'])) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Top 100 discrepancies</h3>
  <?php if (!$leak): ?><div class="empty">No discrepancies in this period — nice.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr>
        <th>Date</th><th>Platform</th><th>Outlet</th><th>Order ID</th>
        <th class="num">Gross</th><th class="num">Net (sys)</th><th class="num">Net (imp.)</th>
        <th class="num">Diff</th><th class="num">Variance %</th><th>Status</th>
      </tr></thead>
      <tbody><?php foreach ($leak as $r): ?>
        <tr>
          <td><?= e($r['order_date']) ?></td>
          <td><?= e($r['platform']) ?></td>
          <td><?= e($r['outlet']) ?></td>
          <td><?= e($r['order_id']) ?></td>
          <td class="num"><?= e(money((float)$r['gross_sales'])) ?></td>
          <td class="num"><?= e(money((float)$r['net_settlement_system'])) ?></td>
          <td class="num"><?= e(money((float)$r['net_settlement_imported'])) ?></td>
          <td class="num"><?= e(money((float)$r['difference'])) ?></td>
          <td class="num"><?= e(number_format((float)$r['variance_pct'], 2)) ?>%</td>
          <td><span class="badge <?= statusBadgeClass($r['reconciliation_status']) ?>"><?= e($r['reconciliation_status']) ?></span></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
