<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;
use FNBBOS\Csrf;
use FNBBOS\AuditLog;

Auth::requireLogin();
Rbac::require('approvals.manage');

if (requestMethod() === 'POST') {
    Csrf::requireValid();
    $data = [
        'company_id'      => Auth::companyId(),
        'claim_type'      => (string)(input('claim_type') ?: '*'),
        'amount_min'      => asFloat(input('amount_min')),
        'amount_max'      => input('amount_max') !== '' ? asFloat(input('amount_max')) : null,
        'required_role_id'=> asInt(input('required_role_id')),
        'risk_threshold'  => (string)(input('risk_threshold') ?: 'low,medium,high,critical'),
        'auto_approve'    => asInt(input('auto_approve')),
        'priority'        => asInt(input('priority')) ?: 100,
        'is_active'       => asInt(input('is_active')),
    ];
    $cols = array_keys($data);
    $sql = 'INSERT INTO approval_rules (' . implode(',', $cols) . ', created_at) VALUES (' . implode(',', array_fill(0,count($cols),'?')) . ', ?)';
    db()->prepare($sql)->execute(array_merge(array_values($data), [nowDb()]));
    AuditLog::record('approval_rule.create', 'approval_rule', (int)db()->lastInsertId(), $data);
    flash('ok', 'Approval rule saved.');
    redirect('pages/approval-rules.php');
}

$companyId = Auth::companyId();
$rules = db()->prepare('
    SELECT ar.*, r.name AS role_name
    FROM approval_rules ar
    LEFT JOIN roles r ON r.id = ar.required_role_id
    WHERE ar.company_id = ?
    ORDER BY ar.priority ASC, ar.amount_min ASC');
$rules->execute([$companyId]);
$rules = $rules->fetchAll();
$roles = db()->query('SELECT id, name FROM roles ORDER BY name')->fetchAll();

$pageTitle = 'Approval Rules';
$active    = 'approval-rules';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header"><div><h2>Approval rules</h2><p>Configure who must approve based on claim type, amount range, and risk threshold. First active matching rule wins (lowest priority number first).</p></div></div>

<div class="card">
  <h3>Add rule</h3>
  <form method="post" class="form-grid">
    <?= Csrf::field() ?>
    <div class="form-row"><label>Claim type (or *)</label>
      <select name="claim_type">
        <option value="*">Any</option>
        <?php foreach (['staff_meal','petrol','transport','supplier_purchase','petty_cash','marketing','delivery','maintenance','voucher_redemption','other'] as $t): ?>
          <option value="<?= e($t) ?>"><?= e($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row"><label>Amount min (RM)</label><input type="number" step="0.01" name="amount_min" value="0"></div>
    <div class="form-row"><label>Amount max (RM, blank for none)</label><input type="number" step="0.01" name="amount_max"></div>
    <div class="form-row"><label>Required role</label>
      <select name="required_role_id" required>
        <?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-row"><label>Risk levels (comma list)</label>
      <input type="text" name="risk_threshold" value="low,medium,high,critical">
      <span class="hint">Match against the claim's risk level. Use <code>high,critical</code> to require this approver only when risky.</span>
    </div>
    <div class="form-row"><label>Auto approve?</label>
      <select name="auto_approve"><option value="0">No</option><option value="1">Yes</option></select>
    </div>
    <div class="form-row"><label>Priority (lower = first)</label><input type="number" name="priority" value="100"></div>
    <div class="form-row"><label>Status</label><select name="is_active"><option value="1">Active</option><option value="0">Inactive</option></select></div>
    <div class="form-row" style="align-self:end;"><button class="btn btn--primary" type="submit">Save rule</button></div>
  </form>
</div>

<div class="card">
  <h3>Active rules</h3>
  <?php if (!$rules): ?><div class="empty">No rules yet — every claim will go straight to finance.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr><th class="num">Priority</th><th>Claim type</th><th>Amount range</th><th>Risk</th><th>Required role</th><th>Auto approve</th><th>Status</th></tr></thead>
      <tbody><?php foreach ($rules as $r): ?>
        <tr>
          <td class="num"><?= (int)$r['priority'] ?></td>
          <td><?= e($r['claim_type']) ?></td>
          <td><?= e(money((float)$r['amount_min'])) ?> – <?= e($r['amount_max'] !== null ? money((float)$r['amount_max']) : '∞') ?></td>
          <td><?= e($r['risk_threshold']) ?></td>
          <td><?= e($r['role_name'] ?? '') ?></td>
          <td><?= ((int)$r['auto_approve']===1) ? 'Yes' : 'No' ?></td>
          <td><span class="badge <?= ((int)$r['is_active']===1)?'badge--ok':'badge--muted' ?>"><?= ((int)$r['is_active']===1)?'Active':'Inactive' ?></span></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
