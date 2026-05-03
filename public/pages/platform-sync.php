<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;
use FNBBOS\Csrf;
use FNBBOS\AuditLog;
use FNBBOS\Engine\PlatformSync;

Auth::requireLogin();
Rbac::require('platforms.sync');

$companyId = Auth::companyId();

if (requestMethod() === 'POST') {
    Csrf::requireValid();
    $action = (string)input('action');

    if ($action === 'save_creds') {
        $platformId  = asInt(input('platform_id'));
        $credsRaw    = (string)input('credentials_json', '{}');
        $isActive    = asInt(input('is_active'));
        $decoded     = json_decode($credsRaw, true);
        if (!is_array($decoded)) {
            flash('error', 'Credentials must be valid JSON. Example: {"api_key":"…","outlet_codes":["MAIN-01"]}');
        } elseif (!$platformId) {
            flash('error', 'Pick a platform.');
        } else {
            $now = nowDb();
            db()->prepare('
                INSERT INTO platform_credentials (company_id, platform_id, credentials, is_active, created_at, updated_at)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE credentials = VALUES(credentials), is_active = VALUES(is_active), updated_at = VALUES(updated_at)
            ')->execute([$companyId, $platformId, json_encode($decoded), $isActive, $now, $now]);
            AuditLog::record('platform_credentials.save', 'platform', $platformId, ['fields' => array_keys($decoded)]);
            flash('ok', 'Credentials saved.');
        }
    } elseif ($action === 'sync') {
        $platformId = asInt(input('platform_id'));
        $from = (string)(input('from') ?: date('Y-m-d', strtotime('-7 days')));
        $to   = (string)(input('to')   ?: date('Y-m-d'));
        try {
            $r = PlatformSync::run($companyId, $platformId, $from, $to, Auth::id());
            flash('ok', sprintf('Sync complete (log #%d): %d orders pulled, %d new, %d skipped.',
                $r['log_id'], $r['orders'], $r['new'], $r['skipped']));
        } catch (Throwable $e) {
            flash('error', 'Sync failed: ' . $e->getMessage());
        }
    }
    redirect('pages/platform-sync.php');
}

$creds = db()->prepare('
    SELECT pc.*, p.code, p.name AS platform_name
    FROM platform_credentials pc
    JOIN platforms p ON p.id = pc.platform_id
    WHERE pc.company_id = ?
    ORDER BY p.name');
$creds->execute([$companyId]);
$creds = $creds->fetchAll();

$logs = db()->prepare('
    SELECT psl.*, p.name AS platform_name, u.name AS triggered_by_name
    FROM platform_sync_logs psl
    JOIN platforms p ON p.id = psl.platform_id
    LEFT JOIN users u ON u.id = psl.triggered_by
    WHERE psl.company_id = ?
    ORDER BY psl.id DESC LIMIT 30');
$logs->execute([$companyId]);
$logs = $logs->fetchAll();

$platforms = db()->prepare('SELECT id, code, name FROM platforms WHERE company_id = ? AND is_active = 1 ORDER BY name');
$platforms->execute([$companyId]);
$platforms = $platforms->fetchAll();

$pageTitle = 'Platform Sync';
$active    = 'platform-sync';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header">
  <div><h2>Platform API sync</h2><p>Pull orders directly from delivery platforms instead of CSV upload. The Mock adapter generates realistic data for testing; GrabFood / Foodpanda / ShopeeFood adapters are stubs awaiting partner credentials.</p></div>
</div>

<div class="card">
  <h3>Save / update credentials</h3>
  <form method="post" class="form-grid">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save_creds">
    <div class="form-row"><label>Platform</label>
      <select name="platform_id" required>
        <?php foreach ($platforms as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['code']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row" style="grid-column: 1 / -1;">
      <label>Credentials (JSON)</label>
      <textarea name="credentials_json" placeholder='{"api_key":"…","outlet_codes":["MAIN-01","KL-01"]}'>{}</textarea>
      <span class="hint">Stored as JSON in <code>platform_credentials.credentials</code>. The Mock adapter understands <code>outlet_codes</code> (array of codes to spread orders across). Real adapters will document their required keys.</span>
    </div>
    <div class="form-row"><label>Status</label>
      <select name="is_active"><option value="1">Active</option><option value="0">Inactive</option></select>
    </div>
    <div class="form-row" style="align-self:end;"><button class="btn btn--primary" type="submit">Save</button></div>
  </form>
</div>

<div class="card">
  <h3>Run a sync</h3>
  <form method="post" class="form-grid">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="sync">
    <div class="form-row"><label>Platform</label>
      <select name="platform_id" required>
        <?php foreach ($platforms as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row"><label>From</label><input type="date" name="from" value="<?= e(date('Y-m-d', strtotime('-7 days'))) ?>" required></div>
    <div class="form-row"><label>To</label>  <input type="date" name="to"   value="<?= e(date('Y-m-d')) ?>" required></div>
    <div class="form-row" style="align-self:end;"><button class="btn btn--success" type="submit">Pull orders</button></div>
  </form>
</div>

<div class="card">
  <h3>Configured credentials</h3>
  <?php if (!$creds): ?><div class="empty">No platform credentials saved yet.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr><th>Platform</th><th>Last sync</th><th>Status</th><th>Active</th></tr></thead>
      <tbody><?php foreach ($creds as $c): ?>
        <tr>
          <td><?= e($c['platform_name']) ?> (<?= e($c['code']) ?>)</td>
          <td><?= e($c['last_sync_at'] ?? '—') ?></td>
          <td><?= e($c['last_status'] ?? '—') ?></td>
          <td><span class="badge <?= ((int)$c['is_active']===1)?'badge--ok':'badge--muted' ?>"><?= ((int)$c['is_active']===1)?'Active':'Inactive' ?></span></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Recent sync runs</h3>
  <?php if (!$logs): ?><div class="empty">No syncs yet.</div>
  <?php else: ?>
    <table class="data">
      <thead><tr><th class="num">#</th><th>Platform</th><th>Window</th><th class="num">Pulled</th><th class="num">New</th><th>Status</th><th>By</th><th>Started</th><th>Message</th></tr></thead>
      <tbody><?php foreach ($logs as $l): ?>
        <tr>
          <td class="num"><?= (int)$l['id'] ?></td>
          <td><?= e($l['platform_name']) ?></td>
          <td><?= e($l['date_from']) ?> → <?= e($l['date_to']) ?></td>
          <td class="num"><?= (int)$l['orders_pulled'] ?></td>
          <td class="num"><?= (int)$l['orders_new'] ?></td>
          <td><span class="badge <?= statusBadgeClass($l['status']) ?>"><?= e($l['status']) ?></span></td>
          <td><?= e($l['triggered_by_name'] ?? 'system') ?></td>
          <td><?= e($l['started_at']) ?></td>
          <td><?= e($l['message'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
