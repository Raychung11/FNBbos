<?php
// SLV WMS — pages/settings/tax_groups.php
// Purpose: CRUD for tax_groups + their tax_group_codes membership.
// Roles allowed: super_admin
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role('super_admin');

$errors  = [];
$action  = $_REQUEST['action'] ?? 'list';
$id      = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
$editing = null;
$selectedIds = [];

if ($action === 'edit' && $id > 0) {
    $stmt = db()->prepare('SELECT * FROM tax_groups WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, company_id()]);
    $editing = $stmt->fetch() ?: null;
    if (!$editing) {
        flash('error', 'Tax group not found.');
        redirect('/pages/settings/tax_groups.php');
    }
    $stmt = db()->prepare('SELECT tax_code_id FROM tax_group_codes WHERE group_id = ?');
    $stmt->execute([$id]);
    $selectedIds = array_map('intval', array_column($stmt->fetchAll(), 'tax_code_id'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = (string)($_POST['_action'] ?? 'save');

    if ($do === 'delete') {
        $gid = (int)($_POST['id'] ?? 0);
        $del = db()->prepare('DELETE FROM tax_groups WHERE id = ? AND company_id = ?');
        $del->execute([$gid, company_id()]);
        audit_log('tax_group_delete', 'tax_groups', $gid);
        flash('success', 'Tax group deleted.');
        redirect('/pages/settings/tax_groups.php');
    }

    $editing_id  = (int)($_POST['id'] ?? 0);
    $code        = strtoupper(trim((string)($_POST['code'] ?? '')));
    $name        = trim((string)($_POST['name'] ?? ''));
    $is_active   = !empty($_POST['is_active']) ? 1 : 0;
    $codeIds     = isset($_POST['tax_code_ids']) && is_array($_POST['tax_code_ids'])
                    ? array_map('intval', $_POST['tax_code_ids']) : [];

    if (!preg_match('/^[A-Z0-9_]{1,32}$/', $code)) $errors[] = 'Code must be 1–32 uppercase letters/digits/underscore.';
    if ($name === '' || mb_strlen($name) > 191)    $errors[] = 'Name is required (max 191).';

    if (!$errors) {
        try {
            db_tx(function () use ($editing_id, $code, $name, $is_active, $codeIds) {
                if ($editing_id > 0) {
                    $upd = db()->prepare(
                        'UPDATE tax_groups SET code = ?, name = ?, is_active = ?
                          WHERE id = ? AND company_id = ?'
                    );
                    $upd->execute([$code, $name, $is_active, $editing_id, company_id()]);
                    $gid = $editing_id;
                    db()->prepare('DELETE FROM tax_group_codes WHERE group_id = ?')->execute([$gid]);
                    audit_log('tax_group_update', 'tax_groups', $gid, compact('code','name'));
                } else {
                    $ins = db()->prepare(
                        'INSERT INTO tax_groups (company_id, code, name, is_active) VALUES (?, ?, ?, ?)'
                    );
                    $ins->execute([company_id(), $code, $name, $is_active]);
                    $gid = (int)db()->lastInsertId();
                    audit_log('tax_group_create', 'tax_groups', $gid, compact('code','name'));
                }

                if ($codeIds) {
                    $vrf = db()->prepare(
                        'SELECT id FROM tax_codes WHERE company_id = ? AND id IN (' .
                        implode(',', array_fill(0, count($codeIds), '?')) . ')'
                    );
                    $vrf->execute(array_merge([company_id()], $codeIds));
                    $valid = array_map('intval', array_column($vrf->fetchAll(), 'id'));

                    $bind = db()->prepare(
                        'INSERT INTO tax_group_codes (group_id, tax_code_id, sort_order) VALUES (?, ?, ?)'
                    );
                    $order = 0;
                    foreach ($valid as $tcId) {
                        $bind->execute([$gid, $tcId, $order++]);
                    }
                }
            });
            flash('success', $editing_id > 0 ? 'Tax group updated.' : 'Tax group created.');
            redirect('/pages/settings/tax_groups.php');
        } catch (PDOException $e) {
            $errors[] = (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062)
                ? 'Code already exists.' : 'Database error.';
        }
    }

    // Repopulate
    $editing = ['id' => $editing_id, 'code' => $code, 'name' => $name, 'is_active' => $is_active];
    $selectedIds = $codeIds;
    $action = 'edit';
}

$stmt = db()->prepare(
    "SELECT g.*,
            COALESCE(GROUP_CONCAT(c.code ORDER BY tgc.sort_order, c.code SEPARATOR ', '), '') AS codes_csv
       FROM tax_groups g
  LEFT JOIN tax_group_codes tgc ON tgc.group_id    = g.id
  LEFT JOIN tax_codes       c   ON c.id            = tgc.tax_code_id
      WHERE g.company_id = ?
   GROUP BY g.id
   ORDER BY g.code"
);
$stmt->execute([company_id()]);
$groups = $stmt->fetchAll();

$tcStmt = db()->prepare('SELECT id, code, name, rate FROM tax_codes WHERE company_id = ? AND is_active = 1 ORDER BY code');
$tcStmt->execute([company_id()]);
$availableCodes = $tcStmt->fetchAll();

$PAGE_TITLE   = 'Tax groups';
$SETTINGS_TAB = 'tax_groups';
require __DIR__ . '/../../partials/header.php';
require __DIR__ . '/../../partials/settings_nav.php';
?>
<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-semibold text-gray-900">Tax groups</h1>
  <a href="?action=new" class="slv-bg-primary text-white px-3 py-2 rounded text-sm font-medium">+ Add tax group</a>
</div>

<?php if ($errors): ?>
  <div class="mb-4 border border-red-200 bg-red-50 text-red-800 rounded p-3 text-sm">
    <ul class="list-disc list-inside">
      <?php foreach ($errors as $err): ?><li><?= e_($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="bg-white border border-gray-200 rounded-lg overflow-hidden mb-6">
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 text-gray-600">
      <tr>
        <th class="text-left px-4 py-2 font-medium">Code</th>
        <th class="text-left px-4 py-2 font-medium">Name</th>
        <th class="text-left px-4 py-2 font-medium">Tax codes</th>
        <th class="text-left px-4 py-2 font-medium">Active</th>
        <th class="px-4 py-2"></th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$groups): ?>
        <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No tax groups yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($groups as $g): ?>
        <tr>
          <td class="px-4 py-2 font-mono"><?= e_($g['code']) ?></td>
          <td class="px-4 py-2"><?= e_($g['name']) ?></td>
          <td class="px-4 py-2 text-gray-600"><?= e_($g['codes_csv'] ?: '—') ?></td>
          <td class="px-4 py-2"><?= ((int)$g['is_active']) ? 'Yes' : 'No' ?></td>
          <td class="px-4 py-2 text-right whitespace-nowrap">
            <a href="?action=edit&id=<?= e_($g['id']) ?>" class="text-indigo-700 hover:underline">Edit</a>
            <form method="post" class="inline" onsubmit="return confirm('Delete tax group <?= e_($g['code']) ?>?');">
              <?= csrf_field() ?>
              <input type="hidden" name="_action" value="delete">
              <input type="hidden" name="id" value="<?= e_($g['id']) ?>">
              <button class="ml-3 text-red-600 hover:underline">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($action === 'new' || $action === 'edit' || $editing !== null): ?>
  <h2 class="text-lg font-semibold text-gray-900 mb-3"><?= $editing && !empty($editing['id']) ? 'Edit tax group' : 'New tax group' ?></h2>
  <form method="post" class="bg-white border border-gray-200 rounded-lg p-5 max-w-2xl space-y-4">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="save">
    <input type="hidden" name="id" value="<?= e_($editing['id'] ?? '') ?>">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700">Code</label>
        <input type="text" name="code" value="<?= e_($editing['code'] ?? '') ?>" required pattern="[A-Za-z0-9_]{1,32}"
               maxlength="32" style="text-transform:uppercase"
               class="mt-1 w-full rounded border-gray-300 shadow-sm font-mono">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Name</label>
        <input type="text" name="name" value="<?= e_($editing['name'] ?? '') ?>" required maxlength="191"
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">Tax codes in this group</label>
      <?php if (!$availableCodes): ?>
        <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded p-3">
          No active tax codes. Create them in <a href="/pages/settings/tax_codes.php" class="underline">Tax codes</a> first.
        </p>
      <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
          <?php foreach ($availableCodes as $c): ?>
            <label class="inline-flex items-center gap-2 border border-gray-200 rounded px-3 py-2">
              <input type="checkbox" name="tax_code_ids[]" value="<?= e_($c['id']) ?>"
                     <?= in_array((int)$c['id'], $selectedIds, true) ? 'checked' : '' ?>
                     class="rounded border-gray-300">
              <span class="font-mono text-sm"><?= e_($c['code']) ?></span>
              <span class="text-xs text-gray-500"><?= e_($c['name']) ?> (<?= e_(number_format((float)$c['rate'] * 100, 2)) ?>%)</span>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <div>
      <label class="inline-flex items-center gap-2">
        <input type="checkbox" name="is_active" value="1"
               <?= !isset($editing) || (int)($editing['is_active'] ?? 1) === 1 ? 'checked' : '' ?>
               class="rounded border-gray-300">
        <span class="text-sm">Active</span>
      </label>
    </div>
    <div class="flex justify-end gap-3 pt-2">
      <a href="/pages/settings/tax_groups.php" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Cancel</a>
      <button type="submit" class="slv-bg-primary text-white px-4 py-2 rounded text-sm font-medium">
        <?= $editing && !empty($editing['id']) ? 'Update' : 'Create' ?>
      </button>
    </div>
  </form>
<?php endif; ?>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
