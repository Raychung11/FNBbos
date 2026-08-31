<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;
use FNBBOS\Csrf;
use FNBBOS\Engine\Importer;
use FNBBOS\Engine\Notifier;

Auth::requireLogin();
Rbac::require('sales.import');

$result = null;

if (requestMethod() === 'POST') {
    Csrf::requireValid();
    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Please select a CSV file.');
        redirect('pages/sales-import.php');
    }
    $allowed = (array)config('storage.allowed_csv_ext');
    $ext = strtolower(pathinfo($_FILES['csv']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        flash('error', 'Unsupported file type. Allowed: ' . implode(', ', $allowed));
        redirect('pages/sales-import.php');
    }
    $maxBytes = (int)config('storage.max_upload_mb', 8) * 1024 * 1024;
    if ($_FILES['csv']['size'] > $maxBytes) {
        flash('error', 'File too large.');
        redirect('pages/sales-import.php');
    }
    $dest = rtrim((string)config('storage.uploads'), '/') . '/' . uniqid('sales_', true) . '.' . $ext;
    if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0775, true);
    move_uploaded_file($_FILES['csv']['tmp_name'], $dest);

    try {
        $result = Importer::ingestCsv($dest, Auth::companyId(), Auth::id());
        flash('ok', sprintf('Import complete: %d accepted, %d rejected (batch #%d).',
            $result['accepted'], $result['rejected'], $result['batch_id']));

        // Phase 3: alert finance if this batch produced fee/tax discrepancies.
        $disc = db()->prepare('
            SELECT sfc.reconciliation_status AS status, COUNT(*) AS cnt, SUM(ABS(sfc.difference)) AS total_diff
            FROM sales_fee_calculations sfc
            JOIN sales_orders so ON so.id = sfc.sales_order_id
            WHERE so.batch_id = ?
              AND sfc.reconciliation_status IN ("over_deducted","under_deducted","fee_discrepancy","tax_discrepancy")
            GROUP BY sfc.reconciliation_status');
        $disc->execute([$result['batch_id']]);
        $discRows = $disc->fetchAll();
        if ($discRows) {
            $totalDiff = 0; $totalCnt = 0; $lines = [];
            foreach ($discRows as $r) {
                $totalDiff += (float)$r['total_diff']; $totalCnt += (int)$r['cnt'];
                $lines[] = sprintf('• %s: %d orders, %s', $r['status'], $r['cnt'], money((float)$r['total_diff']));
            }
            Notifier::notifyCompanyFinance(
                Auth::companyId(),
                Notifier::EVENT_SETTLEMENT_MISMATCH,
                'Settlement discrepancies detected',
                sprintf("Batch #%d produced %d discrepant orders worth %s in total.\n%s",
                    $result['batch_id'], $totalCnt, money($totalDiff), implode("\n", $lines)),
                [
                    'entity' => 'sales_import_batch', 'entity_id' => $result['batch_id'],
                    'channels' => ['in_app','email','whatsapp'],
                ]
            );
        }
    } catch (Throwable $e) {
        flash('error', 'Import failed: ' . $e->getMessage());
    }
}

$batches = db()->prepare('
    SELECT b.*, u.name AS uploaded_by_name
    FROM sales_import_batches b
    LEFT JOIN users u ON u.id = b.uploaded_by
    WHERE b.company_id = ?
    ORDER BY b.id DESC LIMIT 20');
$batches->execute([Auth::companyId()]);
$batches = $batches->fetchAll();

$pageTitle = 'Sales Import';
$active    = 'sales-import';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header"><div><h2>Sales import</h2><p>Upload a CSV. The Importer validates rows, persists each order, and runs the Fee + Tax engines to populate the reconciliation snapshot.</p></div></div>

<div class="card">
  <h3>Upload CSV</h3>
  <form method="post" enctype="multipart/form-data" class="form-grid">
    <?= Csrf::field() ?>
    <div class="form-row">
      <label>File</label>
      <input type="file" name="csv" accept=".csv,.xlsx,.xls" required>
      <span class="hint">Required columns: <code>platform_code, outlet_code, order_id, order_date, gross_sales</code>.<br>Optional: item_subtotal, service_charge, sst_amount, discount, voucher, refund, platform_commission, payment_fee, delivery_fee, adjustment, net_settlement, settlement_date, bank_reference.</span>
    </div>
    <div class="form-row" style="align-self:end;"><button type="submit" class="btn btn--primary">Upload &amp; process</button></div>
  </form>
  <div style="margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--panel-border);">
    <strong>First time?</strong> Download the CSV template and fill in your data:
    <a class="btn btn--ghost btn--sm" href="<?= e(url('download.php?name=sales_import_csv')) ?>">Sales import template (CSV)</a>
    <a class="btn btn--ghost btn--sm" href="<?= e(url('download.php?name=sales_import_readme')) ?>">Column instructions (TXT)</a>
  </div>
</div>

<?php if ($result && $result['errors']): ?>
<div class="card">
  <h3>Errors in last upload</h3>
  <table class="data">
    <thead><tr><th class="num">Row</th><th>Order ID</th><th>Code</th><th>Message</th></tr></thead>
    <tbody>
      <?php foreach ($result['errors'] as $err): ?>
        <tr><td class="num"><?= (int)$err['row'] ?></td><td><?= e($err['order_id']) ?></td><td><?= e($err['code']) ?></td><td><?= e($err['message']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="card">
  <h3>Recent import batches</h3>
  <?php if (!$batches): ?><div class="empty">No imports yet.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr><th class="num">#</th><th>File</th><th>Uploaded by</th><th class="num">Total</th><th class="num">Accepted</th><th class="num">Rejected</th><th>Status</th><th>At</th></tr></thead>
      <tbody><?php foreach ($batches as $b): ?>
        <tr>
          <td class="num"><?= (int)$b['id'] ?></td>
          <td><?= e($b['file_name']) ?></td>
          <td><?= e($b['uploaded_by_name'] ?? '') ?></td>
          <td class="num"><?= (int)$b['total_rows'] ?></td>
          <td class="num"><?= (int)$b['accepted_rows'] ?></td>
          <td class="num"><?= (int)$b['rejected_rows'] ?></td>
          <td><span class="badge <?= statusBadgeClass($b['status']) ?>"><?= e($b['status']) ?></span></td>
          <td><?= e($b['created_at']) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
