<?php
// SLV WMS — pages/settings/tax_codes.php
// Purpose: CRUD for tax_codes. Single page: list at top, create/edit form below.
//          Rates entered as percentage (e.g. 6 means 6%); stored as 0.0600.
// Roles allowed: super_admin
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role('super_admin');

$errors = [];
$editing = null;
$action  = $_REQUEST['action'] ?? 'list';
$id      = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;

// Load record being edited
if ($action === 'edit' && $id > 0) {
    $stmt = db()->prepare('SELECT * FROM tax_codes WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, company_id()]);
    $editing = $stmt->fetch() ?: null;
    if (!$editing) {
        flash('error', 'Tax code not found.');
        redirect('/pages/settings/tax_codes.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = (string)($_POST['_action'] ?? 'save');

    if ($do === 'delete') {
        $tid = (int)($_POST['id'] ?? 0);
        $used = db()->prepare(
            'SELECT COUNT(*) FROM tax_group_codes WHERE tax_code_id = ?'
        );
        $used->execute([$tid]);
        if ((int)$used->fetchColumn() > 0) {
            flash('error', 'Cannot delete: this tax code is used in one or more tax groups.');
        } else {
            $del = db()->prepare('DELETE FROM tax_codes WHERE id = ? AND company_id = ?');
            $del->execute([$tid, company_id()]);
            audit_log('tax_code_delete', 'tax_codes', $tid);
            flash('success', 'Tax code deleted.');
        }
        redirect('/pages/settings/tax_codes.php');
    }

    // Save (create or update)
    $code        = strtoupper(trim((string)($_POST['code'] ?? '')));
    $name        = trim((string)($_POST['name'] ?? ''));
    $rate_pct    = (string)($_POST['rate'] ?? '');
    $type        = (string)($_POST['type'] ?? 'output_tax');
    $is_compound = !empty($_POST['is_compound']) ? 1 : 0;
    $is_active   = !empty($_POST['is_active'])   ? 1 : 0;
    $editing_id  = (int)($_POST['id'] ?? 0);

    if (!preg_match('/^[A-Z0-9_]{1,32}$/', $code))   $errors[] = 'Code must be 1–32 uppercase letters/digits/underscore.';
    if ($name === '' || mb_strlen($name) > 191)      $errors[] = 'Name is required (max 191).';
    if (!is_numeric($rate_pct) || (float)$rate_pct < 0 || (float)$rate_pct > 100)
                                                     $errors[] = 'Rate must be a number 0–100.';
    if (!in_array($type, ['output_tax','withholding','flat'], true))
                                                     $errors[] = 'Type is invalid.';

    if (!$errors) {
        $rate = round((float)$rate_pct / 100, 4);

        if ($editing_id > 0) {
            $stmt = db()->prepare(
                'UPDATE tax_codes
                    SET code = ?, name = ?, rate = ?, type = ?, is_compound = ?, is_active = ?
                  WHERE id = ? AND company_id = ?'
            );
            try {
                $stmt->execute([$code, $name, $rate, $type, $is_compound, $is_active, $editing_id, company_id()]);
                audit_log('tax_code_update', 'tax_codes', $editing_id, compact('code','name','rate','type'));
                flash('success', 'Tax code updated.');
                redirect('/pages/settings/tax_codes.php');
            } catch (PDOException $e) {
                $errors[] = $e->errorInfo[1] === 1062 ? 'Code already exists.' : 'Database error.';
            }
        } else {
            $stmt = db()->prepare(
                'INSERT INTO tax_codes (company_id, code, name, rate, type, is_compound, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            try {
                $stmt->execute([company_id(), $code, $name, $rate, $type, $is_compound, $is_active]);
                $newId = (int)db()->lastInsertId();
                audit_log('tax_code_create', 'tax_codes', $newId, compact('code','name','rate','type'));
                flash('success', 'Tax code created.');
                redirect('/pages/settings/tax_codes.php');
            } catch (PDOException $e) {
                $errors[] = $e->errorInfo[1] === 1062 ? 'Code already exists.' : 'Database error.';
            }
        }

        // Repopulate form on error
        $editing = [
            'id' => $editing_id, 'code' => $code, 'name' => $name,
            'rate' => $rate, 'type' => $type,
            'is_compound' => $is_compound, 'is_active' => $is_active,
        ];
        $action = 'edit';
    }
}

// List
$stmt = db()->prepare('SELECT * FROM tax_codes WHERE company_id = ? ORDER BY code');
$stmt->execute([company_id()]);
$rows = $stmt->fetchAll();

$PAGE_TITLE   = 'Tax codes';
$SETTINGS_TAB = 'tax_codes';
require __DIR__ . '/../../partials/header.php';
require __DIR__ . '/../../partials/settings_nav.php';
?>
<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-semibold text-gray-900">Tax codes</h1>
  <a href="?action=new" class="slv-bg-primary text-white px-3 py-2 rounded text-sm font-medium">+ Add tax code</a>
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
        <th class="text-right px-4 py-2 font-medium">Rate</th>
        <th class="text-left px-4 py-2 font-medium">Type</th>
        <th class="text-left px-4 py-2 font-medium">Compound</th>
        <th class="text-left px-4 py-2 font-medium">Active</th>
        <th class="px-4 py-2"></th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No tax codes yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="px-4 py-2 font-mono"><?= e_($r['code']) ?></td>
          <td class="px-4 py-2"><?= e_($r['name']) ?></td>
          <td class="px-4 py-2 text-right"><?= e_(number_format((float)$r['rate'] * 100, 2)) ?>%</td>
          <td class="px-4 py-2 text-gray-600"><?= e_($r['type']) ?></td>
          <td class="px-4 py-2"><?= ((int)$r['is_compound']) ? 'Yes' : 'No' ?></td>
          <td class="px-4 py-2"><?= ((int)$r['is_active'])   ? 'Yes' : 'No' ?></td>
          <td class="px-4 py-2 text-right whitespace-nowrap">
            <a href="?action=edit&id=<?= e_($r['id']) ?>" class="text-indigo-700 hover:underline">Edit</a>
            <form method="post" class="inline" onsubmit="return confirm('Delete tax code <?= e_($r['code']) ?>?');">
              <?= csrf_field() ?>
              <input type="hidden" name="_action" value="delete">
              <input type="hidden" name="id" value="<?= e_($r['id']) ?>">
              <button class="ml-3 text-red-600 hover:underline">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($action === 'new' || $action === 'edit' || $editing !== null): ?>
  <h2 class="text-lg font-semibold text-gray-900 mb-3"><?= $editing ? 'Edit tax code' : 'New tax code' ?></h2>
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
      <div>
        <label class="block text-sm font-medium text-gray-700">Rate (%)</label>
        <input type="number" name="rate" step="0.0001" min="0" max="100" required
               value="<?= e_(isset($editing['rate']) ? number_format((float)$editing['rate'] * 100, 4) : '') ?>"
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Type</label>
        <select name="type" class="mt-1 w-full rounded border-gray-300 shadow-sm">
          <?php foreach (['output_tax','withholding','flat'] as $t): ?>
            <option value="<?= e_($t) ?>" <?= (($editing['type'] ?? 'output_tax') === $t) ? 'selected' : '' ?>><?= e_($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex items-center gap-6 sm:col-span-2 mt-2">
        <label class="inline-flex items-center gap-2">
          <input type="checkbox" name="is_compound" value="1" <?= (int)($editing['is_compound'] ?? 0) === 1 ? 'checked' : '' ?>
                 class="rounded border-gray-300">
          <span class="text-sm">Compound</span>
        </label>
        <label class="inline-flex items-center gap-2">
          <input type="checkbox" name="is_active" value="1" <?= !isset($editing) || (int)($editing['is_active'] ?? 1) === 1 ? 'checked' : '' ?>
                 class="rounded border-gray-300">
          <span class="text-sm">Active</span>
        </label>
      </div>
    </div>
    <div class="flex justify-end gap-3 pt-2">
      <a href="/pages/settings/tax_codes.php" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Cancel</a>
      <button type="submit" class="slv-bg-primary text-white px-4 py-2 rounded text-sm font-medium">
        <?= $editing && !empty($editing['id']) ? 'Update' : 'Create' ?>
      </button>
    </div>
  </form>
<?php endif; ?>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
