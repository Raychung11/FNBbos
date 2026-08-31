<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;
use FNBBOS\Csrf;
use FNBBOS\AuditLog;
use FNBBOS\Engine\Notifier;

Auth::requireLogin();
Rbac::require('claims.review');

$companyId = Auth::companyId();
$pdo = db();

if (requestMethod() === 'POST') {
    Csrf::requireValid();
    $id     = asInt(input('id'));
    $action = (string)input('action');
    $note   = (string)input('note');
    $row = $pdo->prepare('SELECT * FROM claims WHERE id = ? AND company_id = ?');
    $row->execute([$id, $companyId]);
    $claim = $row->fetch();
    if (!$claim) { flash('error', 'Claim not found.'); redirect('pages/claim-approve.php'); }

    $now = nowDb();
    if ($action === 'approve') {
        $pdo->prepare('UPDATE claims SET approval_status = "approved", approved_at = ? WHERE id = ?')->execute([$now, $id]);
        $pdo->prepare('INSERT INTO claim_approvals (claim_id, actor_user_id, decision, note, created_at) VALUES (?,?,?,?,?)')
            ->execute([$id, Auth::id(), 'approved', $note ?: null, $now]);
        AuditLog::record('claim.approve', 'claim', $id, ['note' => $note]);
        Notifier::dispatch(Notifier::EVENT_CLAIM_APPROVED, 'Claim approved',
            'Claim ' . $claim['claim_id'] . ' approved.',
            ['user_id' => (int)$claim['claimant_id'], 'company_id' => $companyId, 'entity' => 'claim', 'entity_id' => $id]);

        // Phase 3: budget burn check post-approval.
        $b = $pdo->prepare('SELECT monthly_claim_budget FROM outlets WHERE id = ?');
        $b->execute([(int)$claim['outlet_id']]);
        $budget = (float)$b->fetchColumn();
        if ($budget > 0) {
            $u = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM claims WHERE outlet_id = ? AND claim_date BETWEEN ? AND ? AND approval_status IN ("approved","paid")');
            $u->execute([(int)$claim['outlet_id'], date('Y-m-01'), date('Y-m-t')]);
            $used = (float)$u->fetchColumn();
            $pct = $used / $budget;
            $threshold = (float)config('alerts.budget_exceed_pct', 1.0);
            $warn      = (float)config('alerts.budget_warn_pct',   0.85);
            if ($pct >= $threshold || $pct >= $warn) {
                Notifier::notifyCompanyFinance(
                    $companyId,
                    Notifier::EVENT_BUDGET_EXCEEDED,
                    sprintf('Outlet claim budget at %.0f%%', $pct * 100),
                    sprintf("Outlet has used %s of %s (%.0f%%) this month after approving %s.",
                        money($used), money($budget), $pct * 100, $claim['claim_id']),
                    ['channels' => ['in_app','email','whatsapp'], 'entity' => 'outlet', 'entity_id' => (int)$claim['outlet_id']]
                );
            }
        }
        flash('ok', 'Claim approved.');
    } elseif ($action === 'reject') {
        $pdo->prepare('UPDATE claims SET approval_status = "rejected", approved_at = ? WHERE id = ?')->execute([$now, $id]);
        $pdo->prepare('INSERT INTO claim_approvals (claim_id, actor_user_id, decision, note, created_at) VALUES (?,?,?,?,?)')
            ->execute([$id, Auth::id(), 'rejected', $note ?: null, $now]);
        AuditLog::record('claim.reject', 'claim', $id, ['note' => $note]);
        Notifier::dispatch(Notifier::EVENT_CLAIM_REJECTED, 'Claim rejected',
            'Claim ' . $claim['claim_id'] . ' rejected. ' . $note,
            ['user_id' => (int)$claim['claimant_id'], 'company_id' => $companyId, 'entity' => 'claim', 'entity_id' => $id]);
        flash('ok', 'Claim rejected.');
    } elseif ($action === 'pay') {
        $pdo->prepare('UPDATE claims SET paid_status = "paid", paid_at = ? WHERE id = ?')->execute([$now, $id]);
        AuditLog::record('claim.pay', 'claim', $id, ['note' => $note]);
        flash('ok', 'Claim marked as paid.');
    }
    redirect('pages/claim-approve.php?id=' . $id);
}

$claimId = asInt(input('id'));
if ($claimId) {
    $stmt = $pdo->prepare('
        SELECT c.*, u.name AS claimant_name, o.name AS outlet_name
        FROM claims c
        JOIN users u   ON u.id = c.claimant_id
        JOIN outlets o ON o.id = c.outlet_id
        WHERE c.id = ? AND c.company_id = ?');
    $stmt->execute([$claimId, $companyId]);
    $claim = $stmt->fetch();

    $factors = $pdo->prepare('
        SELECT f.* FROM claim_risk_factors f
        JOIN claim_risk_scores s ON s.id = f.claim_risk_score_id
        WHERE s.claim_id = ?
        ORDER BY f.score DESC');
    $factors->execute([$claimId]);
    $factors = $factors->fetchAll();

    $approvals = $pdo->prepare('
        SELECT a.*, u.name AS actor
        FROM claim_approvals a
        LEFT JOIN users u ON u.id = a.actor_user_id
        WHERE a.claim_id = ?
        ORDER BY a.created_at DESC');
    $approvals->execute([$claimId]);
    $approvals = $approvals->fetchAll();

    // Phase 3: load OCR extraction (if any) for side-by-side comparison.
    $att = $pdo->prepare('SELECT ocr_json FROM claim_attachments WHERE claim_id = ? AND ocr_json IS NOT NULL ORDER BY id DESC LIMIT 1');
    $att->execute([$claimId]);
    $ocrJson = $att->fetchColumn();
    $ocr = $ocrJson ? json_decode((string)$ocrJson, true) : null;
} else {
    $queue = $pdo->prepare('
        SELECT c.id, c.claim_id, c.claim_type, c.amount, c.risk_score, c.risk_level,
               c.submitted_at, u.name AS claimant, o.name AS outlet
        FROM claims c
        JOIN users u   ON u.id = c.claimant_id
        JOIN outlets o ON o.id = c.outlet_id
        WHERE c.company_id = ? AND c.approval_status = "submitted"
        ORDER BY FIELD(c.risk_level,"critical","high","medium","low"), c.submitted_at ASC');
    $queue->execute([$companyId]);
    $queue = $queue->fetchAll();
    $claim = null;
}

$pageTitle = 'Claim Approval';
$active    = 'claim-approve';
include __DIR__ . '/../partials/header.php';
?>

<?php if (!$claim): ?>
<div class="page-header"><div><h2>Approval queue</h2><p>Submitted claims waiting for review, sorted by risk level then submission time.</p></div></div>
<div class="card">
  <?php if (!$queue): ?><div class="empty">No claims waiting for review. </div>
  <?php else: ?>
    <table class="data">
      <thead><tr><th>Claim ID</th><th>Submitted</th><th>Claimant</th><th>Outlet</th><th>Type</th><th class="num">Amount</th><th>Risk</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($queue as $c): ?>
        <tr>
          <td><?= e($c['claim_id']) ?></td>
          <td><?= e($c['submitted_at']) ?></td>
          <td><?= e($c['claimant']) ?></td>
          <td><?= e($c['outlet']) ?></td>
          <td><?= e($c['claim_type']) ?></td>
          <td class="num"><?= e(money((float)$c['amount'])) ?></td>
          <td><span class="badge badge--<?= e($c['risk_level']) ?>"><?= e(strtoupper((string)$c['risk_level'])) ?> · <?= (int)$c['risk_score'] ?></span></td>
          <td><a class="btn btn--primary btn--sm" href="<?= e(url('pages/claim-approve.php?id=' . (int)$c['id'])) ?>">Review</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="page-header">
  <div><h2><?= e($claim['claim_id']) ?> · <?= e(money((float)$claim['amount'])) ?></h2>
       <p><?= e($claim['claim_type']) ?> · <?= e($claim['claimant_name']) ?> · <?= e($claim['outlet_name']) ?></p></div>
  <a class="btn btn--ghost" href="<?= e(url('pages/claim-approve.php')) ?>">← Back to queue</a>
</div>

<div class="card">
  <h3>Risk score</h3>
  <div class="flex flex--between">
    <div>
      <span class="badge badge--<?= e($claim['risk_level']) ?>"><?= e(strtoupper((string)$claim['risk_level'])) ?> · <?= (int)$claim['risk_score'] ?>/100</span>
    </div>
    <div class="risk-bar" style="width: 60%;">
      <span style="width: <?= (int)$claim['risk_score'] ?>%; background: <?= e(riskColor((string)$claim['risk_level'])) ?>;"></span>
    </div>
  </div>
  <p style="margin-top: 12px;"><?= e((string)$claim['risk_explanation']) ?></p>
  <?php if ($factors): ?>
    <table class="data" style="margin-top: 10px;">
      <thead><tr><th>Factor</th><th class="num">Score</th><th>Reason</th></tr></thead>
      <tbody>
      <?php foreach ($factors as $f): ?>
        <tr><td><?= e($f['factor_key']) ?></td><td class="num"><?= (int)$f['score'] ?></td><td><?= e((string)$f['reason']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php if ($ocr || !empty($claim['ocr_status']) && $claim['ocr_status'] !== 'none'): ?>
<div class="card">
  <h3>OCR receipt extraction</h3>
  <?php if ($claim['ocr_status'] === 'mismatch'): ?>
    <div class="alert alert--warn">OCR receipt total differs from the claimed amount. Verify before approving.</div>
  <?php elseif (!$ocr): ?>
    <div class="alert alert--info">OCR ran but did not extract structured fields. Raw text only.</div>
  <?php endif; ?>
  <table class="data">
    <thead><tr><th>Field</th><th>From receipt (OCR)</th><th>From claim form</th><th>Match?</th></tr></thead>
    <tbody>
      <tr>
        <td>Total</td>
        <td><?= $ocr && $ocr['total'] !== null ? e(money((float)$ocr['total'])) : '—' ?></td>
        <td><?= e(money((float)$claim['amount'])) ?></td>
        <td>
          <?php if (!$ocr || $ocr['total'] === null): ?>—
          <?php elseif (abs((float)$ocr['total'] - (float)$claim['amount']) / max((float)$ocr['total'],0.01) <= 0.05): ?>
            <span class="badge badge--ok">match</span>
          <?php else: ?>
            <span class="badge badge--err">mismatch</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <td>Merchant</td>
        <td><?= e($ocr['merchant'] ?? '—') ?></td>
        <td><?= e($claim['supplier'] ?? '—') ?></td>
        <td>
          <?php if (!$ocr || empty($ocr['merchant']) || empty($claim['supplier'])): ?>—
          <?php elseif (stripos($ocr['merchant'], (string)$claim['supplier']) !== false || stripos((string)$claim['supplier'], $ocr['merchant']) !== false): ?>
            <span class="badge badge--ok">match</span>
          <?php else: ?>
            <span class="badge badge--warn">differs</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <td>Date</td>
        <td><?= e($ocr['date'] ?? '—') ?></td>
        <td><?= e($claim['claim_date']) ?></td>
        <td>
          <?php if (!$ocr || empty($ocr['date'])): ?>—
          <?php elseif ($ocr['date'] === $claim['claim_date']): ?>
            <span class="badge badge--ok">match</span>
          <?php else: ?>
            <span class="badge badge--warn">differs</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <td>SST</td>
        <td><?= $ocr && $ocr['sst'] !== null ? e(money((float)$ocr['sst'])) : '—' ?></td>
        <td>—</td><td>—</td>
      </tr>
      <tr>
        <td>Receipt no.</td>
        <td><?= e($ocr['receipt_no'] ?? '—') ?></td>
        <td>—</td><td>—</td>
      </tr>
      <tr>
        <td>Payment</td>
        <td><?= e($ocr['payment'] ?? '—') ?></td>
        <td><?= e($claim['payment_method'] ?? '—') ?></td>
        <td>—</td>
      </tr>
    </tbody>
  </table>
  <?php if ($ocr && !empty($ocr['items'])): ?>
    <details style="margin-top:10px;"><summary>Line items detected (<?= count($ocr['items']) ?>)</summary>
      <ul style="font-size:13px;color:var(--ink-soft);"><?php foreach ($ocr['items'] as $it): ?><li><?= e($it) ?></li><?php endforeach; ?></ul>
    </details>
  <?php endif; ?>
  <?php if ($ocr && !empty($ocr['raw_text'])): ?>
    <details style="margin-top:6px;"><summary>Raw OCR text</summary>
      <pre style="white-space:pre-wrap;font-size:12px;background:#f9fafc;padding:10px;border-radius:6px;"><?= e($ocr['raw_text']) ?></pre>
    </details>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h3>Claim details</h3>
  <table class="data">
    <tbody>
      <tr><th>Date</th><td><?= e($claim['claim_date']) ?></td><th>Submitted</th><td><?= e($claim['submitted_at']) ?></td></tr>
      <tr><th>Supplier</th><td><?= e($claim['supplier'] ?? '—') ?></td><th>Payment</th><td><?= e($claim['payment_method'] ?? '—') ?></td></tr>
      <tr><th>Cost center</th><td><?= e($claim['cost_center'] ?? '—') ?></td><th>Description</th><td><?= e($claim['description'] ?? '') ?></td></tr>
      <tr><th>Approval</th><td><span class="badge <?= statusBadgeClass($claim['approval_status']) ?>"><?= e($claim['approval_status']) ?></span></td>
          <th>Paid</th><td><span class="badge <?= statusBadgeClass($claim['paid_status']) ?>"><?= e($claim['paid_status']) ?></span></td></tr>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>Decide</h3>
  <form method="post" class="form-grid">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int)$claim['id'] ?>">
    <div class="form-row" style="grid-column: 1 / -1;"><label>Note (optional)</label><textarea name="note"></textarea></div>
    <div class="form-row"><button class="btn btn--success" name="action" value="approve" type="submit">Approve</button></div>
    <div class="form-row"><button class="btn btn--danger"  name="action" value="reject"  type="submit" data-confirm="Reject this claim?">Reject</button></div>
    <?php if (Rbac::can('claims.pay')): ?>
      <div class="form-row"><button class="btn btn--ghost" name="action" value="pay" type="submit" data-confirm="Mark as paid?">Mark as paid</button></div>
    <?php endif; ?>
  </form>
</div>

<?php if ($approvals): ?>
<div class="card">
  <h3>Approval history</h3>
  <table class="data">
    <thead><tr><th>When</th><th>Actor</th><th>Decision</th><th>Note</th></tr></thead>
    <tbody><?php foreach ($approvals as $a): ?>
      <tr><td><?= e($a['created_at']) ?></td><td><?= e($a['actor'] ?? '') ?></td><td><span class="badge <?= statusBadgeClass($a['decision']) ?>"><?= e($a['decision']) ?></span></td><td><?= e($a['note'] ?? '') ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
</div>
<?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
