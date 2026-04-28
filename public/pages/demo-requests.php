<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;
use FNBBOS\Csrf;
use FNBBOS\AuditLog;

Auth::requireLogin();
Rbac::require('demo.review');

if (requestMethod() === 'POST') {
    Csrf::requireValid();
    $id     = asInt(input('id'));
    $status = (string)input('status');
    if ($id && in_array($status, ['new','contacted','demoed','converted','rejected'], true)) {
        db()->prepare('UPDATE demo_requests SET status = ? WHERE id = ?')->execute([$status, $id]);
        AuditLog::record('demo_request.update', 'demo_request', $id, ['status' => $status]);
        flash('ok', 'Status updated.');
    }
    redirect('pages/demo-requests.php');
}

$status = (string)input('status');
$where = '1=1';
$args  = [];
if ($status !== '') { $where .= ' AND status = ?'; $args[] = $status; }

$stmt = db()->prepare('SELECT * FROM demo_requests WHERE ' . $where . ' ORDER BY created_at DESC LIMIT 500');
$stmt->execute($args);
$rows = $stmt->fetchAll();

$pageTitle = 'Demo Requests';
$active    = 'demo-requests';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header"><div><h2>Demo requests</h2><p>Inbound requests from the public landing page. Reach out, run the demo, and update status as they convert.</p></div></div>

<form method="get" class="card toolbar">
  <div class="form-row"><label>Status</label>
    <select name="status">
      <option value="">Any</option>
      <?php foreach (['new','contacted','demoed','converted','rejected'] as $s): ?>
        <option value="<?= e($s) ?>" <?= $status===$s?'selected':'' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn btn--primary" type="submit">Filter</button>
</form>

<div class="card">
  <?php if (!$rows): ?><div class="empty">No demo requests yet.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr>
        <th>Received</th><th>Company</th><th>Contact</th><th>Email</th><th>Phone</th>
        <th class="num">Outlets</th><th>Platforms</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['created_at']) ?></td>
          <td><?= e($r['company_name']) ?></td>
          <td><?= e($r['full_name']) ?></td>
          <td><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a></td>
          <td><?= e($r['phone'] ?? '—') ?></td>
          <td class="num"><?= $r['outlet_count'] !== null ? (int)$r['outlet_count'] : '—' ?></td>
          <td><?= e($r['platforms'] ?? '—') ?></td>
          <td><span class="badge <?= statusBadgeClass($r['status']) ?>"><?= e($r['status']) ?></span></td>
          <td>
            <form method="post" style="display:flex;gap:6px;align-items:center;">
              <?= Csrf::field() ?>
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <select name="status">
                <?php foreach (['new','contacted','demoed','converted','rejected'] as $s): ?>
                  <option value="<?= e($s) ?>" <?= $r['status']===$s?'selected':'' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn--ghost btn--sm" type="submit">Update</button>
            </form>
          </td>
        </tr>
        <?php if (!empty($r['message'])): ?>
          <tr><td colspan="9" style="background:#f9fafc;font-size:12px;color:var(--ink-soft);"><strong>Message:</strong> <?= e($r['message']) ?></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
