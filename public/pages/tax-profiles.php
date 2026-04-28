<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;
use FNBBOS\Csrf;
use FNBBOS\AuditLog;

Auth::requireLogin();
Rbac::require('tax.manage');

if (requestMethod() === 'POST') {
    Csrf::requireValid();
    $action = (string)input('action');
    $pdo = db();
    if ($action === 'profile.save') {
        $data = [
            'company_id'   => Auth::companyId(),
            'name'         => (string)input('name'),
            'category'     => (string)input('category'),
            'is_inclusive' => asInt(input('is_inclusive')),
            'is_active'    => asInt(input('is_active')),
        ];
        if ($data['name'] === '') {
            flash('error', 'Profile name is required.');
            redirect('pages/tax-profiles.php');
        }
        $pdo->prepare('INSERT INTO tax_profiles (company_id, name, category, is_inclusive, is_active, created_at) VALUES (?,?,?,?,?,?)')
            ->execute(array_merge(array_values($data), [nowDb()]));
        AuditLog::record('tax_profile.create', 'tax_profile', (int)$pdo->lastInsertId(), $data);
        flash('ok', 'Tax profile created.');
    } elseif ($action === 'rule.save') {
        $data = [
            'tax_profile_id'   => asInt(input('tax_profile_id')),
            'rate'             => asFloat(input('rate')) / 100.0,
            'effective_from'   => (string)input('effective_from'),
            'effective_to'     => input('effective_to') ?: null,
            'outlet_id'        => asInt(input('outlet_id')) ?: null,
            'platform_id'      => asInt(input('platform_id')) ?: null,
            'is_active'        => asInt(input('is_active')),
        ];
        $cols = array_keys($data);
        $sql  = 'INSERT INTO tax_rules (' . implode(',', $cols) . ', created_at) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ', ?)';
        $pdo->prepare($sql)->execute(array_merge(array_values($data), [nowDb()]));
        AuditLog::record('tax_rule.create', 'tax_rule', (int)$pdo->lastInsertId(), $data);
        flash('ok', 'Tax rule saved.');
    }
    redirect('pages/tax-profiles.php');
}

$companyId = Auth::companyId();
$profiles  = db()->prepare('SELECT * FROM tax_profiles WHERE company_id = ? ORDER BY name'); $profiles->execute([$companyId]); $profiles = $profiles->fetchAll();
$rules = db()->prepare('
    SELECT tr.*, tp.name AS profile_name, o.name AS outlet_name, p.name AS platform_name
    FROM tax_rules tr
    JOIN tax_profiles tp ON tp.id = tr.tax_profile_id
    LEFT JOIN outlets o   ON o.id  = tr.outlet_id
    LEFT JOIN platforms p ON p.id  = tr.platform_id
    WHERE tp.company_id = ?
    ORDER BY tp.name, tr.effective_from DESC');
$rules->execute([$companyId]);
$rules = $rules->fetchAll();

$outlets   = db()->prepare('SELECT id, name FROM outlets WHERE company_id = ? ORDER BY name'); $outlets->execute([$companyId]); $outlets = $outlets->fetchAll();
$platforms = db()->prepare('SELECT id, name FROM platforms WHERE company_id = ? ORDER BY name'); $platforms->execute([$companyId]); $platforms = $platforms->fetchAll();

$pageTitle = 'Tax Profiles';
$active    = 'tax-profiles';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header"><div><h2>Tax profiles &amp; rules</h2><p>Tax rates are <strong>not hard-coded</strong>. Configure 0%, 6%, 8% or any custom rate, inclusive or exclusive, with effective dates and per outlet/platform overrides.</p></div></div>

<div class="card">
  <h3>Add profile</h3>
  <form method="post" class="form-grid">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="profile.save">
    <div class="form-row"><label>Profile name</label><input type="text" name="name" required placeholder="Standard SST 8%"></div>
    <div class="form-row"><label>Category</label>
      <select name="category">
        <option value="standard">Standard</option>
        <option value="zero_rated">Zero rated</option>
        <option value="exempt">Exempt</option>
        <option value="custom">Custom</option>
      </select>
    </div>
    <div class="form-row"><label>Tax mode</label>
      <select name="is_inclusive"><option value="0">Exclusive</option><option value="1">Inclusive</option></select>
    </div>
    <div class="form-row"><label>Status</label><select name="is_active"><option value="1">Active</option><option value="0">Inactive</option></select></div>
    <div class="form-row" style="align-self:end;"><button class="btn btn--primary" type="submit">Save profile</button></div>
  </form>
</div>

<div class="card">
  <h3>Add rule (effective-dated rate)</h3>
  <form method="post" class="form-grid">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="rule.save">
    <div class="form-row"><label>Profile</label>
      <select name="tax_profile_id" required>
        <?php foreach ($profiles as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-row"><label>Rate %</label><input type="number" step="0.01" name="rate" value="6"></div>
    <div class="form-row"><label>Effective from</label><input type="date" name="effective_from" required value="<?= e(date('Y-m-d')) ?>"></div>
    <div class="form-row"><label>Effective to (optional)</label><input type="date" name="effective_to"></div>
    <div class="form-row"><label>Outlet (optional)</label>
      <select name="outlet_id"><option value="">— all —</option>
        <?php foreach ($outlets as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-row"><label>Platform (optional)</label>
      <select name="platform_id"><option value="">— all —</option>
        <?php foreach ($platforms as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-row"><label>Status</label><select name="is_active"><option value="1">Active</option><option value="0">Inactive</option></select></div>
    <div class="form-row" style="align-self:end;"><button class="btn btn--primary" type="submit">Save rule</button></div>
  </form>
</div>

<div class="card">
  <h3>Profiles</h3>
  <?php if (!$profiles): ?><div class="empty">No profiles yet.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr><th>Name</th><th>Category</th><th>Mode</th><th>Status</th></tr></thead>
      <tbody><?php foreach ($profiles as $p): ?>
        <tr><td><?= e($p['name']) ?></td><td><?= e($p['category']) ?></td>
            <td><?= ((int)$p['is_inclusive']===1)?'Inclusive':'Exclusive' ?></td>
            <td><span class="badge <?= ((int)$p['is_active']===1)?'badge--ok':'badge--muted' ?>"><?= ((int)$p['is_active']===1)?'Active':'Inactive' ?></span></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Rules</h3>
  <?php if (!$rules): ?><div class="empty">No rules yet.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr><th>Profile</th><th class="num">Rate</th><th>Effective</th><th>Outlet</th><th>Platform</th><th>Status</th></tr></thead>
      <tbody><?php foreach ($rules as $r): ?>
        <tr>
          <td><?= e($r['profile_name']) ?></td>
          <td class="num"><?= e(pct((float)$r['rate'])) ?></td>
          <td><?= e($r['effective_from']) ?> &rarr; <?= e($r['effective_to'] ?? '—') ?></td>
          <td><?= e($r['outlet_name'] ?? 'all') ?></td>
          <td><?= e($r['platform_name'] ?? 'all') ?></td>
          <td><span class="badge <?= ((int)$r['is_active']===1)?'badge--ok':'badge--muted' ?>"><?= ((int)$r['is_active']===1)?'Active':'Retired' ?></span></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
