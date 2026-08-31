<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;
use FNBBOS\Csrf;
use FNBBOS\AuditLog;

Auth::requireLogin();
Rbac::require('platforms.manage');

if (requestMethod() === 'POST') {
    Csrf::requireValid();
    $action = (string)input('action');
    $pdo = db();
    if ($action === 'platform.save') {
        $id = asInt(input('id'));
        $data = [
            'company_id'   => Auth::companyId(),
            'code'         => (string)input('code'),
            'name'         => (string)input('name'),
            'merchant_id'  => (string)input('merchant_id'),
            'bank_account' => (string)input('bank_account'),
            'settlement_cycle_days' => asInt(input('settlement_cycle_days')),
            'is_active'    => asInt(input('is_active')),
        ];
        if ($data['code'] === '' || $data['name'] === '') {
            flash('error', 'Platform code and name are required.');
            redirect('pages/platforms.php');
        }
        if ($id) {
            $pdo->prepare('UPDATE platforms SET company_id=?, code=?, name=?, merchant_id=?, bank_account=?, settlement_cycle_days=?, is_active=? WHERE id=?')
                ->execute(array_merge(array_values($data), [$id]));
            AuditLog::record('platform.update', 'platform', $id, $data);
            flash('ok', 'Platform updated.');
        } else {
            $pdo->prepare('INSERT INTO platforms (company_id, code, name, merchant_id, bank_account, settlement_cycle_days, is_active, created_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute(array_merge(array_values($data), [nowDb()]));
            AuditLog::record('platform.create', 'platform', (int)$pdo->lastInsertId(), $data);
            flash('ok', 'Platform created.');
        }
    } elseif ($action === 'fee.save') {
        $platformId = asInt(input('platform_id'));
        $data = [
            'platform_id'         => $platformId,
            'commission_rate'     => asFloat(input('commission_rate')) / 100.0,
            'payment_fee_rate'    => asFloat(input('payment_fee_rate')) / 100.0,
            'fixed_fee'           => asFloat(input('fixed_fee')),
            'voucher_treatment'   => (string)input('voucher_treatment'),
            'delivery_treatment'  => (string)input('delivery_treatment'),
            'refund_treatment'    => (string)input('refund_treatment'),
            'effective_from'      => (string)input('effective_from'),
            'effective_to'        => input('effective_to') ?: null,
            'is_active'           => asInt(input('is_active')),
        ];
        // Deactivate older overlapping rules
        $pdo->prepare('UPDATE platform_fee_rules SET is_active = 0 WHERE platform_id = ? AND effective_to IS NULL')
            ->execute([$platformId]);
        $cols = array_keys($data);
        $sql = 'INSERT INTO platform_fee_rules (' . implode(',', $cols) . ', created_at) VALUES (' . implode(',', array_fill(0,count($cols),'?')) . ', ?)';
        $pdo->prepare($sql)->execute(array_merge(array_values($data), [nowDb()]));
        AuditLog::record('platform_fee_rule.create', 'platform_fee_rule', (int)$pdo->lastInsertId(), $data);
        flash('ok', 'Fee rule saved. Older overlapping rules were deactivated.');
    }
    redirect('pages/platforms.php');
}

$companyId = Auth::companyId();
$platforms = db()->prepare('SELECT * FROM platforms WHERE company_id = ? ORDER BY is_active DESC, name'); $platforms->execute([$companyId]); $platforms = $platforms->fetchAll();
$rules     = db()->prepare('
    SELECT pfr.*, p.name AS platform_name
    FROM platform_fee_rules pfr
    JOIN platforms p ON p.id = pfr.platform_id
    WHERE p.company_id = ?
    ORDER BY p.name, pfr.effective_from DESC');
$rules->execute([$companyId]);
$rules = $rules->fetchAll();

$pageTitle = 'Platforms & Fee Rules';
$active    = 'platforms';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header"><div><h2>Platforms &amp; fee rules</h2><p>Commission, payment, fixed fees and treatment of vouchers/delivery/refunds. Effective-dated — older rules are kept for audit.</p></div></div>

<div class="card">
  <h3>Add platform</h3>
  <form method="post" class="form-grid">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="platform.save">
    <div class="form-row"><label>Code</label><input type="text" name="code" placeholder="grabfood" required></div>
    <div class="form-row"><label>Name</label><input type="text" name="name" placeholder="GrabFood" required></div>
    <div class="form-row"><label>Merchant ID</label><input type="text" name="merchant_id"></div>
    <div class="form-row"><label>Bank account</label><input type="text" name="bank_account"></div>
    <div class="form-row"><label>Settlement cycle (days)</label><input type="number" name="settlement_cycle_days" value="7"></div>
    <div class="form-row"><label>Status</label><select name="is_active"><option value="1">Active</option><option value="0">Inactive</option></select></div>
    <div class="form-row" style="align-self:end;"><button class="btn btn--primary" type="submit">Save platform</button></div>
  </form>
</div>

<div class="card">
  <h3>Add fee rule</h3>
  <form method="post" class="form-grid">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="fee.save">
    <div class="form-row"><label>Platform</label>
      <select name="platform_id" required>
        <?php foreach ($platforms as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-row"><label>Commission % (e.g. 25)</label><input type="number" step="0.01" name="commission_rate" value="0"></div>
    <div class="form-row"><label>Payment gateway % (e.g. 1.5)</label><input type="number" step="0.01" name="payment_fee_rate" value="0"></div>
    <div class="form-row"><label>Fixed fee per order (RM)</label><input type="number" step="0.01" name="fixed_fee" value="0"></div>
    <div class="form-row"><label>Voucher treatment</label>
      <select name="voucher_treatment"><option value="merchant_bears">Merchant bears</option><option value="platform_bears">Platform bears</option></select>
    </div>
    <div class="form-row"><label>Delivery fee treatment</label>
      <select name="delivery_treatment"><option value="platform_bears">Platform bears</option><option value="merchant_bears">Merchant bears</option></select>
    </div>
    <div class="form-row"><label>Refund treatment</label>
      <select name="refund_treatment"><option value="merchant_bears">Merchant bears</option><option value="platform_bears">Platform bears</option></select>
    </div>
    <div class="form-row"><label>Effective from</label><input type="date" name="effective_from" value="<?= e(date('Y-m-d')) ?>" required></div>
    <div class="form-row"><label>Effective to (optional)</label><input type="date" name="effective_to"></div>
    <div class="form-row"><label>Status</label><select name="is_active"><option value="1">Active</option><option value="0">Inactive</option></select></div>
    <div class="form-row" style="align-self:end;"><button class="btn btn--primary" type="submit">Save fee rule</button></div>
  </form>
</div>

<div class="card">
  <h3>Active fee rules</h3>
  <?php if (!$rules): ?><div class="empty">No fee rules configured yet.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr>
        <th>Platform</th><th class="num">Commission</th><th class="num">Payment gw</th><th class="num">Fixed fee</th>
        <th>Voucher</th><th>Delivery</th><th>Refund</th><th>Effective</th><th>Status</th>
      </tr></thead>
      <tbody><?php foreach ($rules as $r): ?>
        <tr>
          <td><?= e($r['platform_name']) ?></td>
          <td class="num"><?= e(pct((float)$r['commission_rate'])) ?></td>
          <td class="num"><?= e(pct((float)$r['payment_fee_rate'])) ?></td>
          <td class="num"><?= e(money((float)$r['fixed_fee'])) ?></td>
          <td><?= e($r['voucher_treatment']) ?></td>
          <td><?= e($r['delivery_treatment']) ?></td>
          <td><?= e($r['refund_treatment']) ?></td>
          <td><?= e($r['effective_from']) ?> &rarr; <?= e($r['effective_to'] ?? '—') ?></td>
          <td><span class="badge <?= ((int)$r['is_active']===1)?'badge--ok':'badge--muted' ?>"><?= ((int)$r['is_active']===1)?'Active':'Retired' ?></span></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Platforms</h3>
  <?php if (!$platforms): ?><div class="empty">No platforms yet.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr><th>Code</th><th>Name</th><th>Merchant ID</th><th>Bank acct</th><th class="num">Settlement (days)</th><th>Status</th></tr></thead>
      <tbody><?php foreach ($platforms as $p): ?>
        <tr>
          <td><?= e($p['code']) ?></td>
          <td><?= e($p['name']) ?></td>
          <td><?= e($p['merchant_id'] ?? '') ?></td>
          <td><?= e($p['bank_account'] ?? '') ?></td>
          <td class="num"><?= (int)$p['settlement_cycle_days'] ?></td>
          <td><span class="badge <?= ((int)$p['is_active']===1)?'badge--ok':'badge--muted' ?>"><?= ((int)$p['is_active']===1)?'Active':'Inactive' ?></span></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
