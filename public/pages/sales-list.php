<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;

Auth::requireLogin();
Rbac::require('sales.view');

$companyId = Auth::companyId();
$from = (string)(input('from') ?: date('Y-m-01'));
$to   = (string)(input('to')   ?: date('Y-m-d'));
$platformId = asInt(input('platform_id'));
$outletId   = asInt(input('outlet_id'));
$status     = (string)input('status');

$where = [' so.company_id = ? '];
$args  = [$companyId];
$where[] = ' so.order_date BETWEEN ? AND ? ';
$args[] = $from; $args[] = $to;
if ($platformId) { $where[] = ' so.platform_id = ? '; $args[] = $platformId; }
if ($outletId)   { $where[] = ' so.outlet_id = ? ';   $args[] = $outletId; }
if ($status !== '') { $where[] = ' sfc.reconciliation_status = ? '; $args[] = $status; }

$sql = '
    SELECT so.*, sfc.net_settlement_system, sfc.difference, sfc.reconciliation_status, sfc.tax_amount AS sys_tax,
           p.name AS platform_name, o.name AS outlet_name
    FROM sales_orders so
    JOIN sales_fee_calculations sfc ON sfc.sales_order_id = so.id
    JOIN platforms p ON p.id = so.platform_id
    JOIN outlets   o ON o.id = so.outlet_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY so.order_date DESC, so.id DESC
    LIMIT 500';
$stmt = db()->prepare($sql);
$stmt->execute($args);
$orders = $stmt->fetchAll();

$platforms = db()->prepare('SELECT id, name FROM platforms WHERE company_id = ? ORDER BY name'); $platforms->execute([$companyId]); $platforms = $platforms->fetchAll();
$outlets   = db()->prepare('SELECT id, name FROM outlets WHERE company_id = ? ORDER BY name');   $outlets->execute([$companyId]);   $outlets   = $outlets->fetchAll();

$pageTitle = 'Sales Orders';
$active    = 'sales-list';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header"><div><h2>Sales orders</h2><p>Latest 500 orders matching your filters. Each row carries the system-calculated net settlement and reconciliation status.</p></div></div>

<form class="card toolbar" method="get">
  <div class="form-row"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="form-row"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="form-row"><label>Platform</label>
    <select name="platform_id"><option value="">All</option>
      <?php foreach ($platforms as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $platformId==$p['id']?'selected':'' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="form-row"><label>Outlet</label>
    <select name="outlet_id"><option value="">All</option>
      <?php foreach ($outlets as $o): ?><option value="<?= (int)$o['id'] ?>" <?= $outletId==$o['id']?'selected':'' ?>><?= e($o['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="form-row"><label>Status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach (['matched','partially_matched','unmatched','fee_discrepancy','tax_discrepancy','missing_settlement','over_deducted','under_deducted'] as $s): ?>
        <option value="<?= e($s) ?>" <?= $status===$s?'selected':'' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-row" style="align-self:end;"><button class="btn btn--primary" type="submit">Filter</button></div>
</form>

<div class="card">
  <?php if (!$orders): ?><div class="empty">No orders match your filters.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr>
        <th>Order date</th><th>Platform</th><th>Outlet</th><th>Order ID</th>
        <th class="num">Gross</th><th class="num">Tax (sys)</th>
        <th class="num">Net (sys)</th><th class="num">Net (imp.)</th><th class="num">Diff</th>
        <th>Status</th>
      </tr></thead>
      <tbody><?php foreach ($orders as $o): ?>
        <tr>
          <td><?= e($o['order_date']) ?></td>
          <td><?= e($o['platform_name']) ?></td>
          <td><?= e($o['outlet_name']) ?></td>
          <td><?= e($o['order_id']) ?></td>
          <td class="num"><?= e(money((float)$o['gross_sales'])) ?></td>
          <td class="num"><?= e(money((float)$o['sys_tax'])) ?></td>
          <td class="num"><?= e(money((float)$o['net_settlement_system'])) ?></td>
          <td class="num"><?= e(money((float)$o['net_settlement_imported'])) ?></td>
          <td class="num"><?= e(money((float)$o['difference'])) ?></td>
          <td><span class="badge <?= statusBadgeClass($o['reconciliation_status']) ?>"><?= e($o['reconciliation_status']) ?></span></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
