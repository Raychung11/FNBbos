<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;
use FNBBOS\Csrf;
use FNBBOS\AuditLog;
use FNBBOS\Engine\Reconciler;

Auth::requireLogin();
Rbac::require('bank.import');

if (requestMethod() === 'POST') {
    Csrf::requireValid();
    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Please choose a bank statement CSV.');
        redirect('pages/bank-import.php');
    }
    $allowed = (array)config('storage.allowed_csv_ext');
    $ext = strtolower(pathinfo($_FILES['csv']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        flash('error', 'Unsupported file type.');
        redirect('pages/bank-import.php');
    }
    $dest = rtrim((string)config('storage.uploads'), '/') . '/' . uniqid('bank_', true) . '.' . $ext;
    move_uploaded_file($_FILES['csv']['tmp_name'], $dest);

    $fh = fopen($dest, 'r');
    $header = array_map('strtolower', array_map('trim', fgetcsv($fh) ?: []));
    $required = ['value_date', 'amount', 'direction', 'reference', 'platform_code', 'outlet_code'];
    foreach ($required as $col) {
        if (!in_array($col, $header, true)) {
            fclose($fh);
            flash('error', 'Missing required column: ' . $col);
            redirect('pages/bank-import.php');
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $bs = $pdo->prepare('INSERT INTO bank_statement_imports (company_id, uploaded_by, file_name, status, created_at) VALUES (?,?,?,?,?)');
        $bs->execute([Auth::companyId(), Auth::id(), basename($dest), 'completed', nowDb()]);
        $importId = (int)$pdo->lastInsertId();

        $platforms = $pdo->prepare('SELECT id, code FROM platforms WHERE company_id = ?');
        $platforms->execute([Auth::companyId()]);
        $platformLookup = [];
        foreach ($platforms->fetchAll() as $r) $platformLookup[strtolower($r['code'])] = (int)$r['id'];

        $outlets = $pdo->prepare('SELECT id, code FROM outlets WHERE company_id = ?');
        $outlets->execute([Auth::companyId()]);
        $outletLookup = [];
        foreach ($outlets->fetchAll() as $r) $outletLookup[strtolower($r['code'])] = (int)$r['id'];

        $insert = $pdo->prepare('INSERT INTO bank_transactions (company_id, import_id, value_date, amount, direction, reference, platform_id, outlet_id, created_at) VALUES (?,?,?,?,?,?,?,?,?)');
        $count = 0;
        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) === 1 && trim((string)$row[0]) === '') continue;
            $data = array_combine($header, array_pad($row, count($header), ''));
            $insert->execute([
                Auth::companyId(), $importId, $data['value_date'], asFloat($data['amount']),
                strtolower((string)$data['direction']) === 'debit' ? 'debit' : 'credit',
                $data['reference'] ?: null,
                $platformLookup[strtolower((string)$data['platform_code'])] ?? null,
                $outletLookup[strtolower((string)$data['outlet_code'])]     ?? null,
                nowDb(),
            ]);
            $count++;
        }
        fclose($fh);
        AuditLog::record('bank.import', 'bank_statement_import', $importId, ['rows' => $count]);
        $pdo->commit();
        flash('ok', sprintf('Imported %d bank transactions (import #%d).', $count, $importId));
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('error', 'Bank import failed: ' . $e->getMessage());
    }
    redirect('pages/bank-import.php');
}

$companyId = Auth::companyId();
$from = (string)(input('from') ?: date('Y-m-01'));
$to   = (string)(input('to')   ?: date('Y-m-d'));

$matches = Reconciler::match($companyId, $from, $to);
$imports = db()->prepare('SELECT * FROM bank_statement_imports WHERE company_id = ? ORDER BY id DESC LIMIT 20'); $imports->execute([$companyId]); $imports = $imports->fetchAll();

$pageTitle = 'Bank Reconciliation';
$active    = 'bank-import';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header"><div><h2>Bank reconciliation</h2><p>Match expected platform settlements against actual bank receipts. Required CSV columns: <code>value_date, amount, direction, reference, platform_code, outlet_code</code>.</p></div></div>

<div class="card">
  <h3>Upload bank statement</h3>
  <form method="post" enctype="multipart/form-data" class="form-grid">
    <?= Csrf::field() ?>
    <div class="form-row"><label>File</label><input type="file" name="csv" accept=".csv,.xlsx,.xls" required></div>
    <div class="form-row" style="align-self:end;"><button class="btn btn--primary" type="submit">Upload</button></div>
  </form>
</div>

<form method="get" class="card toolbar">
  <div class="form-row"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="form-row"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn--primary" type="submit">Match</button>
</form>

<div class="card">
  <h3>Settlement match results (<?= e($from) ?> → <?= e($to) ?>)</h3>
  <?php if (!$matches): ?><div class="empty">No expected settlements in this window.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr>
        <th>Settle date</th><th class="num">Platform ID</th><th class="num">Outlet ID</th>
        <th class="num">Expected</th><th class="num">Received</th><th class="num">Difference</th><th>Status</th>
      </tr></thead>
      <tbody><?php foreach ($matches as $m): ?>
        <tr>
          <td><?= e($m['settle_date']) ?></td>
          <td class="num"><?= (int)$m['platform_id'] ?></td>
          <td class="num"><?= (int)$m['outlet_id'] ?></td>
          <td class="num"><?= e(money($m['expected'])) ?></td>
          <td class="num"><?= e(money($m['received'])) ?></td>
          <td class="num"><?= e(money($m['difference'])) ?></td>
          <td><span class="badge <?= statusBadgeClass($m['status']) ?>"><?= e($m['status']) ?></span></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Recent bank imports</h3>
  <?php if (!$imports): ?><div class="empty">No bank imports yet.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr><th class="num">#</th><th>File</th><th>Status</th><th>Created</th></tr></thead>
      <tbody><?php foreach ($imports as $i): ?>
        <tr><td class="num"><?= (int)$i['id'] ?></td><td><?= e($i['file_name']) ?></td><td><span class="badge <?= statusBadgeClass($i['status']) ?>"><?= e($i['status']) ?></span></td><td><?= e($i['created_at']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
