<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;
use FNBBOS\Csrf;
use FNBBOS\AuditLog;

Auth::requireLogin();
Rbac::require('outlets.manage');

if (requestMethod() === 'POST') {
    Csrf::requireValid();
    $id   = asInt(input('id'));
    $data = [
        'company_id'              => asInt(input('company_id')) ?: Auth::companyId(),
        'brand_id'                => asInt(input('brand_id')) ?: null,
        'code'                    => (string)input('code'),
        'name'                    => (string)input('name'),
        'address'                 => (string)input('address'),
        'pic_name'                => (string)input('pic_name'),
        'phone'                   => (string)input('phone'),
        'sst_registered'          => asInt(input('sst_registered')),
        'tax_profile_id'          => asInt(input('tax_profile_id')) ?: null,
        'operating_cost_target'   => asFloat(input('operating_cost_target')),
        'monthly_claim_budget'    => asFloat(input('monthly_claim_budget')),
        'bank_account'            => (string)input('bank_account'),
        'is_active'               => asInt(input('is_active')),
    ];
    if ($data['name'] === '' || $data['code'] === '') {
        flash('error', 'Outlet code and name are required.');
        redirect('pages/outlets.php');
    }
    $pdo = db();
    if ($id) {
        $sql = 'UPDATE outlets SET company_id=?, brand_id=?, code=?, name=?, address=?, pic_name=?, phone=?, sst_registered=?, tax_profile_id=?, operating_cost_target=?, monthly_claim_budget=?, bank_account=?, is_active=? WHERE id=?';
        $pdo->prepare($sql)->execute(array_merge(array_values($data), [$id]));
        AuditLog::record('outlet.update', 'outlet', $id, $data);
        flash('ok', 'Outlet updated.');
    } else {
        $cols = array_keys($data);
        $sql  = 'INSERT INTO outlets (' . implode(',', $cols) . ', created_at) VALUES (' . implode(',', array_fill(0,count($cols),'?')) . ', ?)';
        $pdo->prepare($sql)->execute(array_merge(array_values($data), [nowDb()]));
        AuditLog::record('outlet.create', 'outlet', (int)$pdo->lastInsertId(), $data);
        flash('ok', 'Outlet created.');
    }
    redirect('pages/outlets.php');
}

$companyId = Auth::companyId();
$outlets = db()->prepare('
    SELECT o.*, b.name AS brand_name, tp.name AS tax_profile_name
    FROM outlets o
    LEFT JOIN brands b ON b.id = o.brand_id
    LEFT JOIN tax_profiles tp ON tp.id = o.tax_profile_id
    WHERE o.company_id = ?
    ORDER BY o.is_active DESC, o.name');
$outlets->execute([$companyId]);
$outlets = $outlets->fetchAll();

$brands     = db()->prepare('SELECT id, name FROM brands WHERE company_id = ? ORDER BY name'); $brands->execute([$companyId]);     $brands     = $brands->fetchAll();
$taxProfiles= db()->prepare('SELECT id, name FROM tax_profiles WHERE company_id = ? ORDER BY name'); $taxProfiles->execute([$companyId]); $taxProfiles= $taxProfiles->fetchAll();

$pageTitle = 'Outlets';
$active    = 'outlets';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header"><div><h2>Outlets</h2><p>Each outlet belongs to a brand and has its own SST profile, claim budget, and bank account.</p></div></div>

<div class="card">
  <h3>Add outlet</h3>
  <form method="post" class="form-grid">
    <?= Csrf::field() ?>
    <div class="form-row"><label>Brand</label>
      <select name="brand_id">
        <option value="">— none —</option>
        <?php foreach ($brands as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-row"><label>Outlet code</label><input type="text" name="code" required placeholder="e.g. KL-01"></div>
    <div class="form-row"><label>Outlet name</label><input type="text" name="name" required></div>
    <div class="form-row"><label>Address</label><input type="text" name="address"></div>
    <div class="form-row"><label>PIC name</label><input type="text" name="pic_name"></div>
    <div class="form-row"><label>Phone</label><input type="text" name="phone"></div>
    <div class="form-row"><label>SST registered?</label>
      <select name="sst_registered"><option value="1">Yes</option><option value="0">No</option></select>
    </div>
    <div class="form-row"><label>Tax profile</label>
      <select name="tax_profile_id">
        <option value="">— default —</option>
        <?php foreach ($taxProfiles as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-row"><label>Operating cost target (RM)</label><input type="number" step="0.01" name="operating_cost_target" value="0"></div>
    <div class="form-row"><label>Monthly claim budget (RM)</label><input type="number" step="0.01" name="monthly_claim_budget" value="0"></div>
    <div class="form-row"><label>Bank account</label><input type="text" name="bank_account"></div>
    <div class="form-row"><label>Status</label>
      <select name="is_active"><option value="1">Active</option><option value="0">Inactive</option></select>
    </div>
    <div class="form-row" style="align-self:end;"><button class="btn btn--primary" type="submit">Save outlet</button></div>
  </form>
</div>

<div class="card">
  <h3>Existing outlets</h3>
  <?php if (!$outlets): ?><div class="empty">No outlets yet.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr>
        <th>Code</th><th>Name</th><th>Brand</th><th>Tax profile</th>
        <th class="num">Cost target</th><th class="num">Claim budget</th>
        <th>SST?</th><th>Status</th>
      </tr></thead>
      <tbody><?php foreach ($outlets as $o): ?>
        <tr>
          <td><?= e($o['code']) ?></td>
          <td><?= e($o['name']) ?></td>
          <td><?= e($o['brand_name'] ?? '') ?></td>
          <td><?= e($o['tax_profile_name'] ?? '—') ?></td>
          <td class="num"><?= e(money((float)$o['operating_cost_target'])) ?></td>
          <td class="num"><?= e(money((float)$o['monthly_claim_budget'])) ?></td>
          <td><?= ((int)$o['sst_registered'] === 1) ? 'Yes' : 'No' ?></td>
          <td><span class="badge <?= ((int)$o['is_active']===1)?'badge--ok':'badge--muted' ?>"><?= ((int)$o['is_active']===1) ? 'Active':'Inactive' ?></span></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
