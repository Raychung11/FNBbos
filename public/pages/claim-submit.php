<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;
use FNBBOS\Csrf;
use FNBBOS\AuditLog;
use FNBBOS\Engine\RiskEngine;
use FNBBOS\Engine\Notifier;
use FNBBOS\ApprovalRouter;

Auth::requireLogin();
Rbac::require('claims.submit');

if (requestMethod() === 'POST') {
    Csrf::requireValid();
    $companyId = Auth::companyId();
    $userId    = Auth::id();

    $outletId    = asInt(input('outlet_id')) ?: Auth::outletId();
    $claimType   = (string)input('claim_type');
    $claimDate   = (string)input('claim_date');
    $amount      = asFloat(input('amount'));
    $supplier    = (string)input('supplier');
    $description = (string)input('description');
    $paymentMethod = (string)input('payment_method');
    $costCenter  = (string)input('cost_center');

    if (!$outletId || $claimType === '' || $claimDate === '' || $amount <= 0) {
        flash('error', 'Outlet, claim type, claim date and amount are required.');
        redirect('pages/claim-submit.php');
    }

    // Receipt upload
    $receiptPath = null;
    $receiptHash = null;
    if (!empty($_FILES['receipt']['name']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        $allowed = (array)config('storage.allowed_receipt_ext');
        $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            flash('error', 'Receipt file type not allowed.');
            redirect('pages/claim-submit.php');
        }
        $maxBytes = (int)config('storage.max_upload_mb', 8) * 1024 * 1024;
        if ($_FILES['receipt']['size'] > $maxBytes) {
            flash('error', 'Receipt file too large.');
            redirect('pages/claim-submit.php');
        }
        $dir = rtrim((string)config('storage.uploads'), '/') . '/receipts/' . date('Y/m');
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $receiptPath = $dir . '/' . uniqid('rcpt_', true) . '.' . $ext;
        move_uploaded_file($_FILES['receipt']['tmp_name'], $receiptPath);
        $receiptHash = hash_file('sha256', $receiptPath);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $claimCode = 'CLM-' . date('Ymd') . '-' . strtoupper(substr(uuid(), 0, 6));
        $insert = $pdo->prepare('
            INSERT INTO claims
              (company_id, claimant_id, outlet_id, claim_id, claim_type, claim_date, amount,
               supplier, description, payment_method, cost_center,
               receipt_path, receipt_hash,
               approval_status, paid_status, submitted_at, created_at)
            VALUES (?,?,?,?,?,?,?, ?, ?, ?, ?, ?, ?, "submitted", "unpaid", ?, ?)');
        $now = nowDb();
        $insert->execute([
            $companyId, $userId, $outletId, $claimCode, $claimType, $claimDate, $amount,
            $supplier ?: null, $description ?: null, $paymentMethod ?: null, $costCenter ?: null,
            $receiptPath, $receiptHash,
            $now, $now,
        ]);
        $claimId = (int)$pdo->lastInsertId();

        if ($receiptPath) {
            $pdo->prepare('INSERT INTO claim_attachments (claim_id, file_path, file_hash, mime_type, created_at) VALUES (?,?,?,?,?)')
                ->execute([$claimId, $receiptPath, $receiptHash, mime_content_type($receiptPath) ?: null, $now]);
        }

        // Build risk-scoring context
        $ctx = [];
        $r = $pdo->prepare('SELECT AVG(amount) FROM claims WHERE claimant_id = ?');                 $r->execute([$userId]);   $ctx['avg_staff']    = (float)$r->fetchColumn();
        $r = $pdo->prepare('SELECT AVG(amount) FROM claims WHERE outlet_id = ?');                   $r->execute([$outletId]); $ctx['avg_outlet']   = (float)$r->fetchColumn();
        $r = $pdo->prepare('SELECT AVG(amount) FROM claims WHERE company_id = ? AND claim_type = ?');$r->execute([$companyId, $claimType]); $ctx['avg_category'] = (float)$r->fetchColumn();

        $window = (int)config('risk.frequency_window_days', 3);
        $r = $pdo->prepare('SELECT COUNT(*) FROM claims WHERE claimant_id = ? AND submitted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)');
        $r->execute([$userId, $window]);
        $ctx['recent_claim_count'] = (int)$r->fetchColumn();

        $r = $pdo->prepare('SELECT COUNT(*) FROM claims WHERE company_id = ? AND receipt_hash = ? AND id <> ?');
        $r->execute([$companyId, $receiptHash, $claimId]);
        $ctx['duplicate_receipt_count'] = $receiptHash ? (int)$r->fetchColumn() : 0;

        $r = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(amount),0) FROM claims WHERE claimant_id = ? AND claim_date = ? AND id <> ?');
        $r->execute([$userId, $claimDate, $claimId]);
        $row = $r->fetch(PDO::FETCH_NUM); $ctx['same_day_claims_count'] = (int)$row[0]; $ctx['same_day_claims_total'] = (float)$row[1];

        $r = $pdo->prepare('SELECT amount_max FROM approval_rules WHERE company_id = ? AND is_active = 1 AND amount_max IS NOT NULL ORDER BY amount_max ASC LIMIT 1');
        $r->execute([$companyId]); $ctx['approval_threshold'] = (float)$r->fetchColumn();

        if ($supplier !== '') {
            $r = $pdo->prepare('SELECT COUNT(*) FROM claims WHERE company_id = ? AND supplier = ?');
            $r->execute([$companyId, $supplier]);
            $ctx['supplier_is_new'] = (int)$r->fetchColumn() <= 1;
            $r = $pdo->prepare('SELECT COUNT(DISTINCT claimant_id) FROM claims WHERE company_id = ? AND supplier = ?');
            $r->execute([$companyId, $supplier]);
            $ctx['supplier_used_only_by_one'] = ((int)$r->fetchColumn() === 1);
        }

        $r = $pdo->prepare('SELECT monthly_claim_budget FROM outlets WHERE id = ?');
        $r->execute([$outletId]); $ctx['outlet_monthly_budget'] = (float)$r->fetchColumn();
        $r = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM claims WHERE outlet_id = ? AND claim_date BETWEEN ? AND ? AND id <> ?');
        $r->execute([$outletId, date('Y-m-01'), date('Y-m-t'), $claimId]);
        $ctx['outlet_month_to_date'] = (float)$r->fetchColumn();

        $r = $pdo->prepare('SELECT r.slug FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
        $r->execute([$userId]); $ctx['claimant_role'] = (string)$r->fetchColumn();

        $claimRow = ['amount' => $amount, 'claim_type' => $claimType, 'submitted_at' => $now];
        $risk = RiskEngine::score($claimRow, $ctx);

        $pdo->prepare('UPDATE claims SET risk_score = ?, risk_level = ?, risk_explanation = ? WHERE id = ?')
            ->execute([$risk['score'], $risk['level'], $risk['explanation'], $claimId]);

        $rsId = null;
        $pdo->prepare('INSERT INTO claim_risk_scores (claim_id, score, level, explanation, created_at) VALUES (?,?,?,?,?)')
            ->execute([$claimId, $risk['score'], $risk['level'], $risk['explanation'], $now]);
        $rsId = (int)$pdo->lastInsertId();
        $f = $pdo->prepare('INSERT INTO claim_risk_factors (claim_risk_score_id, factor_key, score, reason) VALUES (?,?,?,?)');
        foreach ($risk['factors'] as $factor) {
            $f->execute([$rsId, $factor['key'], (int)$factor['score'], (string)($factor['reason'] ?? '')]);
        }

        $rule = ApprovalRouter::nextApproverRole($companyId, $claimType, $amount, $risk['level']);
        if ($rule && (int)$rule['auto_approve'] === 1 && $risk['level'] === 'low') {
            $pdo->prepare('UPDATE claims SET approval_status = "approved", approved_at = ? WHERE id = ?')
                ->execute([$now, $claimId]);
        }

        AuditLog::record('claim.submit', 'claim', $claimId, [
            'amount'   => $amount, 'type' => $claimType,
            'risk'     => ['score' => $risk['score'], 'level' => $risk['level']],
        ]);
        $pdo->commit();

        if ($risk['level'] === 'critical') {
            Notifier::dispatch(Notifier::EVENT_CRITICAL_CLAIM,
                'Critical risk claim submitted',
                sprintf('Claim %s (%s) — score %d/100. %s',
                    $claimCode, money($amount), $risk['score'], $risk['explanation']),
                ['company_id' => $companyId, 'entity' => 'claim', 'entity_id' => $claimId]);
        }

        flash('ok', sprintf('Claim %s submitted. Risk: %s (%d/100).', $claimCode, strtoupper($risk['level']), $risk['score']));
        redirect('pages/claims.php');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('error', 'Failed to submit claim: ' . $e->getMessage());
        redirect('pages/claim-submit.php');
    }
}

$companyId = Auth::companyId();
$outlets = db()->prepare('SELECT id, name FROM outlets WHERE company_id = ? AND is_active = 1 ORDER BY name'); $outlets->execute([$companyId]); $outlets = $outlets->fetchAll();

$pageTitle = 'Submit Claim';
$active    = 'claim-submit';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header"><div><h2>Submit a claim</h2><p>Risk scoring runs automatically on submit. Critical claims trigger notifications and may auto-hold pending review.</p></div></div>

<div class="card">
  <form method="post" enctype="multipart/form-data" class="form-grid">
    <?= Csrf::field() ?>
    <div class="form-row"><label>Outlet</label>
      <select name="outlet_id" required>
        <?php foreach ($outlets as $o): ?>
          <option value="<?= (int)$o['id'] ?>" <?= Auth::outletId()==$o['id']?'selected':'' ?>><?= e($o['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row"><label>Claim type</label>
      <select name="claim_type" required>
        <?php foreach (['staff_meal','petrol','transport','supplier_purchase','petty_cash','marketing','delivery','maintenance','voucher_redemption','other'] as $t): ?>
          <option value="<?= e($t) ?>"><?= e($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row"><label>Claim date</label><input type="date" name="claim_date" required value="<?= e(date('Y-m-d')) ?>"></div>
    <div class="form-row"><label>Amount (RM)</label><input type="number" step="0.01" min="0.01" name="amount" required></div>
    <div class="form-row"><label>Supplier / vendor</label><input type="text" name="supplier"></div>
    <div class="form-row"><label>Payment method</label>
      <select name="payment_method">
        <option value="cash">Cash</option>
        <option value="card">Card</option>
        <option value="bank_transfer">Bank transfer</option>
        <option value="ewallet">E-wallet</option>
        <option value="other">Other</option>
      </select>
    </div>
    <div class="form-row"><label>Cost center</label><input type="text" name="cost_center"></div>
    <div class="form-row" style="grid-column: 1 / -1;"><label>Description</label><textarea name="description" placeholder="What was this for?"></textarea></div>
    <div class="form-row" style="grid-column: 1 / -1;"><label>Receipt</label>
      <input type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic">
      <span class="hint">Hash is captured to detect duplicate receipts.</span>
    </div>
    <div class="form-row" style="align-self:end;"><button class="btn btn--primary" type="submit">Submit claim</button></div>
  </form>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
