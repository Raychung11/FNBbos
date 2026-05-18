<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;

Auth::requireLogin();
Rbac::require('claims.view');

$companyId = Auth::companyId();
$mine      = (string)input('mine') === '1';
$status    = (string)input('status');
$risk      = (string)input('risk');

$where = ['c.company_id = ?'];
$args  = [$companyId];
if ($mine) { $where[] = 'c.claimant_id = ?'; $args[] = Auth::id(); }
if ($status !== '') { $where[] = 'c.approval_status = ?'; $args[] = $status; }
if ($risk   !== '') { $where[] = 'c.risk_level = ?';      $args[] = $risk; }

$sql = '
    SELECT c.id, c.claim_id, c.claim_type, c.claim_date, c.amount, c.supplier,
           c.risk_score, c.risk_level, c.approval_status, c.paid_status,
           c.submitted_at, u.name AS claimant, o.name AS outlet
    FROM claims c
    JOIN users u    ON u.id = c.claimant_id
    JOIN outlets o  ON o.id = c.outlet_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY c.submitted_at DESC LIMIT 500';
$stmt = db()->prepare($sql);
$stmt->execute($args);
$claims = $stmt->fetchAll();

$pageTitle = 'Claims';
$active    = 'claims';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header"><div><h2>Claims</h2><p>All submitted claims you can see. Drill into any row for the full risk breakdown.</p></div></div>

<form method="get" class="card toolbar">
  <div class="form-row"><label>Scope</label>
    <select name="mine"><option value="">All</option><option value="1" <?= $mine?'selected':'' ?>>Mine only</option></select>
  </div>
  <div class="form-row"><label>Status</label>
    <select name="status"><option value="">Any</option>
      <?php foreach (['draft','submitted','approved','rejected','paid'] as $s): ?>
        <option value="<?= e($s) ?>" <?= $status===$s?'selected':'' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-row"><label>Risk</label>
    <select name="risk"><option value="">Any</option>
      <?php foreach (['low','medium','high','critical'] as $r): ?>
        <option value="<?= e($r) ?>" <?= $risk===$r?'selected':'' ?>><?= e($r) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn btn--primary" type="submit">Filter</button>
</form>

<div class="card">
  <?php if (!$claims): ?><div class="empty">No claims match.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr>
        <th>Claim ID</th><th>Date</th><th>Claimant</th><th>Outlet</th><th>Type</th>
        <th class="num">Amount</th><th>Risk</th><th>Status</th><th>Paid</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($claims as $c): ?>
        <tr>
          <td><?= e($c['claim_id']) ?></td>
          <td><?= e($c['claim_date']) ?></td>
          <td><?= e($c['claimant']) ?></td>
          <td><?= e($c['outlet']) ?></td>
          <td><?= e($c['claim_type']) ?></td>
          <td class="num"><?= e(money((float)$c['amount'])) ?></td>
          <td>
            <span class="badge badge--<?= e($c['risk_level']) ?>">
              <?= e(strtoupper((string)$c['risk_level'])) ?> · <?= (int)$c['risk_score'] ?>
            </span>
          </td>
          <td><span class="badge <?= statusBadgeClass($c['approval_status']) ?>"><?= e($c['approval_status']) ?></span></td>
          <td><span class="badge <?= statusBadgeClass($c['paid_status']) ?>"><?= e($c['paid_status']) ?></span></td>
          <td><a class="btn btn--ghost btn--sm" href="<?= e(url('pages/claim-approve.php?id=' . (int)$c['id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
