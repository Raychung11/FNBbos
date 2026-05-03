<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;
use FNBBOS\Csrf;
use FNBBOS\AuditLog;
use FNBBOS\Engine\LeakagePredictor;

Auth::requireLogin();
Rbac::require('leakage.view');

$companyId = Auth::companyId();

if (requestMethod() === 'POST') {
    Csrf::requireValid();
    $action = (string)input('action');
    if ($action === 'rerun') {
        $findings = LeakagePredictor::runAll($companyId);
        LeakagePredictor::persist($companyId, $findings);
        AuditLog::record('leakage.rerun', 'leakage_findings', null, ['count' => count($findings)]);
        flash('ok', sprintf('Re-scanned. %d finding(s).', count($findings)));
    } elseif ($action === 'ack') {
        $id = asInt(input('id'));
        if ($id) {
            db()->prepare('UPDATE leakage_findings SET acknowledged_at = ? WHERE id = ? AND company_id = ?')
                ->execute([nowDb(), $id, $companyId]);
            AuditLog::record('leakage.ack', 'leakage_finding', $id);
            flash('ok', 'Finding acknowledged.');
        }
    }
    redirect('pages/leakage.php');
}

$showAck = (string)input('show') === 'all';
$where   = $showAck ? 'company_id = ?' : 'company_id = ? AND acknowledged_at IS NULL';
$stmt = db()->prepare('SELECT * FROM leakage_findings WHERE ' . $where . ' ORDER BY created_at DESC, FIELD(severity,"critical","high","medium","low")');
$stmt->execute([$companyId]);
$rows = $stmt->fetchAll();

// Build a quick map of entity names for display.
$outletNames = [];
$o = db()->prepare('SELECT id, name FROM outlets WHERE company_id = ?'); $o->execute([$companyId]);
foreach ($o->fetchAll() as $r) $outletNames[(int)$r['id']] = $r['name'];
$platformNames = [];
$p = db()->prepare('SELECT id, name FROM platforms WHERE company_id = ?'); $p->execute([$companyId]);
foreach ($p->fetchAll() as $r) $platformNames[(int)$r['id']] = $r['name'];

$pageTitle = 'Leakage predictions';
$active    = 'leakage';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header">
  <div><h2>Profit leakage predictions</h2><p>Outlets, platforms, claim categories projected to bleed margin this month. Acknowledge a finding once you've actioned it; re-running the scan replaces today's batch.</p></div>
  <form method="post" style="display:flex;gap:8px;">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="rerun">
    <button class="btn btn--primary" type="submit">Re-run scan</button>
    <a class="btn btn--ghost" href="<?= e(url('pages/leakage.php' . ($showAck ? '' : '?show=all'))) ?>">
      <?= $showAck ? 'Hide acknowledged' : 'Show all' ?>
    </a>
  </form>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty">No leakage findings recorded. Click <strong>Re-run scan</strong> to compute now.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr>
        <th>Severity</th><th>Type</th><th>Affected</th><th>Evidence</th>
        <th class="num">Projected impact</th><th>When</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $entityLabel = '—';
          if ($r['entity_type'] === 'outlet'   && $r['entity_id']) $entityLabel = $outletNames[(int)$r['entity_id']]   ?? ('outlet#' . (int)$r['entity_id']);
          if ($r['entity_type'] === 'platform' && $r['entity_id']) $entityLabel = $platformNames[(int)$r['entity_id']] ?? ('platform#' . (int)$r['entity_id']);
          if ($r['entity_type'] === 'claim_type') $entityLabel = '(claim category)';
      ?>
        <tr style="<?= $r['acknowledged_at'] ? 'opacity:.55;' : '' ?>">
          <td><span class="badge badge--<?= e($r['severity']) ?>"><?= e(strtoupper((string)$r['severity'])) ?></span></td>
          <td><?= e($r['finding_type']) ?></td>
          <td><?= e($entityLabel) ?></td>
          <td><?= e($r['evidence']) ?></td>
          <td class="num"><?= e(money((float)$r['projected_impact'])) ?></td>
          <td><?= e($r['created_at']) ?></td>
          <td>
            <?php if (!$r['acknowledged_at']): ?>
              <form method="post" style="display:inline;">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="ack">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn--ghost btn--sm" type="submit" data-confirm="Mark as acknowledged?">Acknowledge</button>
              </form>
            <?php else: ?>
              <span class="badge badge--ok">acknowledged</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
