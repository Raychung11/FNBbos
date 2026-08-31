<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;

Auth::requireLogin();
Rbac::require('audit.view');

$companyId = Auth::companyId();
$action = (string)input('action');
$entity = (string)input('entity');
$from = (string)(input('from') ?: date('Y-m-d', strtotime('-30 days')));
$to   = (string)(input('to')   ?: date('Y-m-d'));

$where = ['(al.company_id = ? OR al.company_id IS NULL)'];
$args  = [$companyId];
$where[] = 'al.created_at BETWEEN ? AND ?';
$args[] = $from . ' 00:00:00'; $args[] = $to . ' 23:59:59';
if ($action !== '') { $where[] = 'al.action = ?'; $args[] = $action; }
if ($entity !== '') { $where[] = 'al.entity_type = ?'; $args[] = $entity; }

$sql = '
    SELECT al.*, u.name AS user_name
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.user_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY al.created_at DESC LIMIT 500';
$stmt = db()->prepare($sql);
$stmt->execute($args);
$logs = $stmt->fetchAll();

$pageTitle = 'Audit Logs';
$active    = 'audit-logs';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header"><div><h2>Audit logs</h2><p>Append-only trail of every financial mutation, claim state change, login and import. Last 500 entries.</p></div></div>

<form method="get" class="card toolbar">
  <div class="form-row"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="form-row"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="form-row"><label>Action</label><input type="text" name="action" value="<?= e($action) ?>" placeholder="e.g. claim.approve"></div>
  <div class="form-row"><label>Entity</label><input type="text" name="entity" value="<?= e($entity) ?>" placeholder="e.g. claim"></div>
  <button class="btn btn--primary" type="submit">Filter</button>
</form>

<div class="card">
  <?php if (!$logs): ?><div class="empty">No audit events match.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr><th>When</th><th>User</th><th>Action</th><th>Entity</th><th class="num">ID</th><th>IP</th><th>Context</th></tr></thead>
      <tbody><?php foreach ($logs as $l): ?>
        <tr>
          <td><?= e($l['created_at']) ?></td>
          <td><?= e($l['user_name'] ?? 'system') ?></td>
          <td><?= e($l['action']) ?></td>
          <td><?= e($l['entity_type']) ?></td>
          <td class="num"><?= $l['entity_id'] !== null ? (int)$l['entity_id'] : '' ?></td>
          <td><?= e((string)$l['ip_address']) ?></td>
          <td><pre style="margin:0;font-size:11px;white-space:pre-wrap;"><?= e((string)$l['context_json']) ?></pre></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
