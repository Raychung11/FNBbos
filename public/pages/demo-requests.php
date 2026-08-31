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
    $action = (string)input('action', 'status');

    if ($action === 'convert') {
        $id = asInt(input('id'));
        if ($id) convertDemoToTenant($id);
        redirect('pages/demo-requests.php');
    }

    // Default: status update.
    $id     = asInt(input('id'));
    $status = (string)input('status');
    if ($id && in_array($status, ['new','contacted','demoed','converted','rejected'], true)) {
        db()->prepare('UPDATE demo_requests SET status = ? WHERE id = ?')->execute([$status, $id]);
        AuditLog::record('demo_request.update', 'demo_request', $id, ['status' => $status]);
        flash('ok', 'Status updated.');
    }
    redirect('pages/demo-requests.php');
}

/**
 * Provision a brand-new tenant from a demo request: company, brand, starter
 * outlet and a Company Admin user using the demo's email + name. The generated
 * password is shown once via flash so the operator can hand it to the customer.
 */
function convertDemoToTenant(int $demoId): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM demo_requests WHERE id = ?');
    $stmt->execute([$demoId]);
    $demo = $stmt->fetch();
    if (!$demo) { flash('error', 'Demo request not found.'); return; }
    if ($demo['status'] === 'converted') { flash('error', 'Already converted.'); return; }

    $emailCheck = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
    $emailCheck->execute([$demo['email']]);
    if ($emailCheck->fetchColumn()) {
        flash('error', 'A user with email ' . $demo['email'] . ' already exists. Cannot create tenant.');
        return;
    }

    $roleStmt = $pdo->prepare('SELECT id FROM roles WHERE slug = ?');
    $roleStmt->execute(['company_admin']);
    $roleId = (int)$roleStmt->fetchColumn();
    if (!$roleId) { flash('error', 'company_admin role missing — run seed.sql.'); return; }

    $password = generateTenantPassword();
    $hash = password_hash($password, config('security.password_algo'), ['cost' => config('security.password_cost')]);
    $now = nowDb();

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO companies (name, registration_no, sst_number, created_at) VALUES (?,?,?,?)')
            ->execute([$demo['company_name'], null, null, $now]);
        $companyId = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO brands (company_id, name, created_at) VALUES (?,?,?)')
            ->execute([$companyId, $demo['company_name'], $now]);
        $brandId = (int)$pdo->lastInsertId();

        $pdo->prepare('
            INSERT INTO outlets
              (company_id, brand_id, code, name, sst_registered,
               operating_cost_target, monthly_claim_budget, is_active, created_at)
            VALUES (?,?,?,?,?,?,?,?,?)
        ')->execute([
            $companyId, $brandId, 'MAIN-01', 'Main outlet',
            0, 0, 0, 1, $now,
        ]);
        $outletId = (int)$pdo->lastInsertId();

        $pdo->prepare('
            INSERT INTO users
              (company_id, role_id, default_outlet_id, name, email, password_hash, phone, is_active, created_at)
            VALUES (?,?,?,?,?,?,?,?,?)
        ')->execute([
            $companyId, $roleId, $outletId,
            $demo['full_name'], $demo['email'], $hash, $demo['phone'] ?: null,
            1, $now,
        ]);
        $userId = (int)$pdo->lastInsertId();

        $pdo->prepare('
            INSERT INTO approval_rules
              (company_id, claim_type, amount_min, amount_max, required_role_id,
               risk_threshold, auto_approve, priority, is_active, created_at)
            SELECT ?, "*", 0, NULL, r.id, "low,medium,high,critical", 0, 100, 1, ?
            FROM roles r WHERE r.slug = "company_admin"
        ')->execute([$companyId, $now]);

        $pdo->prepare('UPDATE demo_requests SET status = "converted" WHERE id = ?')->execute([$demoId]);

        AuditLog::record('demo_request.convert', 'demo_request', $demoId, [
            'company_id' => $companyId, 'user_id' => $userId,
        ]);
        AuditLog::record('company.create', 'company', $companyId, ['source' => 'demo_request', 'demo_id' => $demoId]);
        AuditLog::record('user.create',    'user',    $userId, ['email' => $demo['email'], 'source' => 'demo_request']);

        $pdo->commit();

        $loginUrl = url('login.php');
        flash('ok', sprintf(
            'Tenant created for %s. Send these credentials to the customer:%s',
            $demo['company_name'],
            "\n• Login URL: {$loginUrl}\n• Email: {$demo['email']}\n• Password: {$password}\n• Role: Company Admin"
        ));
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('error', 'Tenant conversion failed: ' . $e->getMessage());
    }
}

function generateTenantPassword(int $length = 14): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out . '!';
}

$status = (string)input('status');
$where = '1=1';
$args  = [];
if ($status !== '') { $where .= ' AND status = ?'; $args[] = $status; }

$stmt = db()->prepare('SELECT * FROM demo_requests WHERE ' . $where . ' ORDER BY created_at DESC LIMIT 500');
$stmt->execute($args);
$rows = $stmt->fetchAll();

$alerts = (array)config('notifications.operator_alerts', []);

$pageTitle = 'Demo Requests';
$active    = 'demo-requests';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header"><div><h2>Demo requests</h2><p>Inbound requests from the public landing page. Reach out, run the demo, and convert hot leads into tenants in one click.</p></div></div>

<?php
$ok = flash('ok');
if ($ok):
?>
  <div class="alert alert--ok"><pre style="white-space: pre-wrap; margin: 0; font-family: inherit;"><?= e($ok) ?></pre></div>
<?php endif; ?>
<?php if ($err = flash('error')): ?>
  <div class="alert alert--error"><?= e($err) ?></div>
<?php endif; ?>

<?php if (!empty($alerts['channels']) && in_array('email', $alerts['channels'], true) && !empty($alerts['email_to'])): ?>
  <div class="alert alert--info">Inbound demo notifications go to <strong><?= e($alerts['email_to']) ?></strong>
    <?php if (in_array('whatsapp', $alerts['channels'], true) && !empty($alerts['whatsapp_to'])): ?>
      and WhatsApp <strong><?= e($alerts['whatsapp_to']) ?></strong>
    <?php endif; ?>.</div>
<?php else: ?>
  <div class="alert alert--warn">External notifications are disabled. To get pinged on new demo requests, set <code>notifications.operator_alerts</code> in <code>config/config.php</code> and add <code>email</code> / <code>whatsapp</code> to the channels array.</div>
<?php endif; ?>

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
        <th class="num">Outlets</th><th>Platforms</th><th>Status</th><th>Actions</th>
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
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
              <form method="post" style="display:flex;gap:6px;align-items:center;">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <select name="status">
                  <?php foreach (['new','contacted','demoed','converted','rejected'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= $r['status']===$s?'selected':'' ?>><?= e($s) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn--ghost btn--sm" type="submit">Update</button>
              </form>
              <?php if ($r['status'] !== 'converted'): ?>
                <form method="post" style="display:inline;">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="convert">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn--success btn--sm" type="submit"
                          data-confirm="Create a tenant for <?= e($r['company_name']) ?>? A Company Admin user will be provisioned for <?= e($r['email']) ?>.">
                    Convert to tenant
                  </button>
                </form>
              <?php endif; ?>
            </div>
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
