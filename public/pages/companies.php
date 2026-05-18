<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;
use FNBBOS\Csrf;
use FNBBOS\AuditLog;

Auth::requireLogin();
Rbac::require('companies.manage');

if (requestMethod() === 'POST') {
    Csrf::requireValid();
    $action = (string)input('action');
    $pdo = db();
    if ($action === 'company.save') {
        $id   = asInt(input('id'));
        $name = (string)input('name');
        $regNo= (string)input('registration_no');
        $sst  = (string)input('sst_number');
        if ($name === '') {
            flash('error', 'Company name is required.');
        } elseif ($id) {
            $pdo->prepare('UPDATE companies SET name=?, registration_no=?, sst_number=? WHERE id=?')
                ->execute([$name, $regNo, $sst, $id]);
            AuditLog::record('company.update', 'company', $id, ['name' => $name]);
            flash('ok', 'Company updated.');
        } else {
            $pdo->prepare('INSERT INTO companies (name, registration_no, sst_number, created_at) VALUES (?,?,?,?)')
                ->execute([$name, $regNo, $sst, nowDb()]);
            AuditLog::record('company.create', 'company', (int)$pdo->lastInsertId(), ['name' => $name]);
            flash('ok', 'Company created.');
        }
    } elseif ($action === 'brand.save') {
        $id        = asInt(input('id'));
        $companyId = asInt(input('company_id'));
        $name      = (string)input('name');
        if ($name === '' || !$companyId) {
            flash('error', 'Brand name and company are required.');
        } elseif ($id) {
            $pdo->prepare('UPDATE brands SET name=?, company_id=? WHERE id=?')
                ->execute([$name, $companyId, $id]);
            AuditLog::record('brand.update', 'brand', $id, ['name' => $name]);
            flash('ok', 'Brand updated.');
        } else {
            $pdo->prepare('INSERT INTO brands (company_id, name, created_at) VALUES (?,?,?)')
                ->execute([$companyId, $name, nowDb()]);
            AuditLog::record('brand.create', 'brand', (int)$pdo->lastInsertId(), ['name' => $name]);
            flash('ok', 'Brand created.');
        }
    }
    redirect('pages/companies.php');
}

$companies = db()->query('SELECT * FROM companies ORDER BY name')->fetchAll();
$brands    = db()->query('
    SELECT b.*, c.name AS company_name
    FROM brands b JOIN companies c ON c.id = b.company_id
    ORDER BY c.name, b.name')->fetchAll();

$pageTitle = 'Companies & Brands';
$active    = 'companies';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header"><div><h2>Companies &amp; brands</h2><p>The top of the org tree. A company has many brands, a brand has many outlets.</p></div></div>

<div class="card">
  <h3>Add / edit company</h3>
  <form method="post" class="form-grid">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="company.save">
    <input type="hidden" name="id" value="">
    <div class="form-row"><label>Name</label><input type="text" name="name" required></div>
    <div class="form-row"><label>Registration No.</label><input type="text" name="registration_no"></div>
    <div class="form-row"><label>SST Number</label><input type="text" name="sst_number"></div>
    <div class="form-row" style="align-self:end;"><button class="btn btn--primary" type="submit">Save company</button></div>
  </form>
</div>

<div class="card">
  <h3>Companies</h3>
  <?php if (!$companies): ?><div class="empty">No companies yet.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr><th>Name</th><th>Reg No.</th><th>SST No.</th><th>Created</th></tr></thead>
      <tbody><?php foreach ($companies as $c): ?>
        <tr>
          <td><?= e($c['name']) ?></td>
          <td><?= e($c['registration_no'] ?? '') ?></td>
          <td><?= e($c['sst_number'] ?? '') ?></td>
          <td><?= e($c['created_at']) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Add brand</h3>
  <form method="post" class="form-grid">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="brand.save">
    <div class="form-row"><label>Company</label>
      <select name="company_id" required>
        <?php foreach ($companies as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $c['id']==Auth::companyId()?'selected':'' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row"><label>Brand name</label><input type="text" name="name" required></div>
    <div class="form-row" style="align-self:end;"><button class="btn btn--primary" type="submit">Save brand</button></div>
  </form>
</div>

<div class="card">
  <h3>Brands</h3>
  <?php if (!$brands): ?><div class="empty">No brands yet.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr><th>Brand</th><th>Company</th></tr></thead>
      <tbody><?php foreach ($brands as $b): ?>
        <tr><td><?= e($b['name']) ?></td><td><?= e($b['company_name']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
