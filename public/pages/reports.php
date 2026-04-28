<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;
use FNBBOS\AuditLog;
use FNBBOS\Engine\Reporter;

Auth::requireLogin();
Rbac::require('reports.view');

$companyId = Auth::companyId();
$from = (string)(input('from') ?: date('Y-m-01'));
$to   = (string)(input('to')   ?: date('Y-m-d'));
$report = (string)input('report');
$exportType = (string)input('export');

$rows = [];
$columns = [];
$title = '';

switch ($report) {
    case 'daily_sales':
        $title = 'Daily Sales';
        $columns = ['day', 'orders', 'gross', 'tax'];
        $rows = Reporter::dailySales($companyId, $from, $to);
        break;
    case 'platform_settlement':
        $title = 'Platform Settlement';
        $columns = ['platform', 'orders', 'gross', 'commission', 'payment_fee', 'tax', 'expected_net', 'reported_net', 'diff'];
        $rows = Reporter::platformSettlement($companyId, $from, $to);
        break;
    case 'claim_summary':
        $title = 'Claim Summary';
        $columns = ['claim_type', 'total', 'approved', 'rejected', 'amount', 'high_risk'];
        $rows = Reporter::claimSummary($companyId, $from, $to);
        break;
    case 'abnormal_claims':
        $title = 'Abnormal Claims';
        $columns = ['claim_id', 'claimant', 'outlet', 'claim_type', 'amount', 'risk_score', 'risk_level', 'risk_explanation', 'approval_status', 'submitted_at'];
        $rows = Reporter::abnormalClaims($companyId, $from, $to);
        break;
    case 'outlet_profit':
        $title = 'Outlet Profit';
        $columns = ['outlet', 'net_revenue', 'claims_paid', 'cost_target', 'profit_estimate'];
        $rows = Reporter::outletProfit($companyId, $from, $to);
        break;
}

if ($exportType !== '' && $report !== '') {
    if ($exportType === 'csv') {
        $file = Reporter::exportCsv($title, $columns, $rows);
        AuditLog::record('report.export', 'report', null, ['report' => $report, 'file' => basename($file), 'format' => 'csv']);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        readfile($file);
        exit;
    } elseif ($exportType === 'pdf') {
        $file = Reporter::renderPdf($title, $columns, $rows);
        AuditLog::record('report.export', 'report', null, ['report' => $report, 'file' => basename($file), 'format' => 'pdf']);
        flash('info', 'PDF stub returned ' . basename($file) . ' — wire dompdf/mpdf for true PDF.');
        redirect('pages/reports.php?report=' . $report . '&from=' . $from . '&to=' . $to);
    }
}

$pageTitle = 'Reports & Exports';
$active    = 'reports';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header"><div><h2>Reports &amp; exports</h2><p>Run finance and management reports. Export to CSV. PDF rendering is wired through a stub for easy swap-in.</p></div></div>

<form method="get" class="card toolbar">
  <div class="form-row"><label>Report</label>
    <select name="report" required>
      <option value="">— choose —</option>
      <option value="daily_sales"          <?= $report==='daily_sales'?'selected':'' ?>>Daily sales</option>
      <option value="platform_settlement"  <?= $report==='platform_settlement'?'selected':'' ?>>Platform settlement</option>
      <option value="claim_summary"        <?= $report==='claim_summary'?'selected':'' ?>>Claim summary</option>
      <option value="abnormal_claims"      <?= $report==='abnormal_claims'?'selected':'' ?>>Abnormal claims</option>
      <option value="outlet_profit"        <?= $report==='outlet_profit'?'selected':'' ?>>Outlet profit</option>
    </select>
  </div>
  <div class="form-row"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="form-row"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn--primary" type="submit">Run</button>
  <?php if ($report): ?>
    <button class="btn btn--ghost" type="submit" name="export" value="csv">Export CSV</button>
    <button class="btn btn--ghost" type="submit" name="export" value="pdf">Export PDF</button>
  <?php endif; ?>
</form>

<?php if ($report): ?>
<div class="card">
  <h3><?= e($title) ?></h3>
  <?php if (!$rows): ?><div class="empty">No data for this range.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr><?php foreach ($columns as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <?php foreach ($columns as $c): $v = $r[$c] ?? ''; ?>
            <td <?= is_numeric($v) ? 'class="num"' : '' ?>>
              <?= is_numeric($v) && abs((float)$v) > 0 ? e(money((float)$v)) : e((string)$v) ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
