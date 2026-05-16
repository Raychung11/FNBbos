<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Csrf;

Auth::requireLogin(); // any authenticated user sees their own + company-wide alerts

$companyId = Auth::companyId();
$userId    = Auth::id();

// Scope: notifications addressed to me, or company-wide (user_id NULL) for my company.
$scopeSql  = '((n.user_id = ?) OR (n.user_id IS NULL AND n.company_id = ?))';
$scopeArgs = [$userId, $companyId];

if (requestMethod() === 'POST') {
    Csrf::requireValid();
    $action = (string)input('action');
    if ($action === 'read') {
        $id = asInt(input('id'));
        if ($id) {
            $stmt = db()->prepare('UPDATE notifications n SET read_at = ? WHERE n.id = ? AND ' . $scopeSql);
            $stmt->execute(array_merge([nowDb(), $id], $scopeArgs));
        }
    } elseif ($action === 'read_all') {
        $stmt = db()->prepare('UPDATE notifications n SET read_at = ? WHERE n.read_at IS NULL AND ' . $scopeSql);
        $stmt->execute(array_merge([nowDb()], $scopeArgs));
        flash('ok', 'All notifications marked as read.');
    }
    redirect('pages/notifications.php');
}

$onlyUnread = (string)input('filter') !== 'all';
$sql = 'SELECT n.* FROM notifications n WHERE ' . $scopeSql;
$args = $scopeArgs;
if ($onlyUnread) $sql .= ' AND n.read_at IS NULL';
$sql .= ' ORDER BY n.created_at DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

$pageTitle = 'Notifications';
$active    = 'notifications';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header">
  <div><h2>Notifications</h2><p>Critical claims, settlement mismatches, missing settlements, budget alerts and more. In-app copy of every dispatched alert.</p></div>
  <div style="display:flex;gap:8px;">
    <a class="btn btn--ghost" href="<?= e(url('pages/notifications.php' . ($onlyUnread ? '?filter=all' : ''))) ?>">
      <?= $onlyUnread ? 'Show all' : 'Show unread only' ?>
    </a>
    <form method="post" style="display:inline;">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="read_all">
      <button class="btn btn--primary" type="submit">Mark all read</button>
    </form>
  </div>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty"><?= $onlyUnread ? 'No unread notifications.' : 'No notifications yet.' ?></div>
  <?php else: ?>
    <table class="data">
      <thead><tr><th>When</th><th>Event</th><th>Title</th><th>Message</th><th>Channels</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $n): ?>
        <tr style="<?= $n['read_at'] ? 'opacity:.55;' : 'font-weight:600;' ?>">
          <td><?= e($n['created_at']) ?></td>
          <td><span class="badge badge--muted"><?= e($n['event']) ?></span></td>
          <td><?= e($n['title']) ?></td>
          <td style="white-space:pre-wrap;font-weight:400;"><?= e($n['message']) ?></td>
          <td><?= e($n['channels']) ?></td>
          <td>
            <?php if (!$n['read_at']): ?>
              <form method="post" style="display:inline;">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="read">
                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                <button class="btn btn--ghost btn--sm" type="submit">Mark read</button>
              </form>
            <?php else: ?>
              <span class="badge badge--ok">read</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
