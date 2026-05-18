<?php
// SLV WMS — pages/warehouses/create.php
// Purpose: Create a new warehouse.
// Roles allowed: super_admin
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role('super_admin');

$errors = [];
$values = ['code' => '', 'name' => '', 'address' => '', 'contact' => '', 'status' => 'ACTIVE'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $values = [
        'code'    => strtoupper(trim((string)($_POST['code']    ?? ''))),
        'name'    => trim((string)($_POST['name']    ?? '')),
        'address' => trim((string)($_POST['address'] ?? '')),
        'contact' => trim((string)($_POST['contact'] ?? '')),
        'status'  => (string)($_POST['status']  ?? 'ACTIVE'),
    ];

    if (!preg_match('/^[A-Z0-9_\-]{1,64}$/', $values['code']))   $errors[] = 'Code must be 1–64 uppercase letters/digits/dash/underscore.';
    if ($values['name'] === '' || mb_strlen($values['name']) > 191) $errors[] = 'Name is required (max 191).';
    if (!in_array($values['status'], ['ACTIVE','INACTIVE'], true)) $errors[] = 'Invalid status.';

    if (!$errors) {
        try {
            $stmt = db()->prepare(
                'INSERT INTO warehouses (company_id, code, name, address, contact, status)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                company_id(), $values['code'], $values['name'],
                $values['address'] ?: null, $values['contact'] ?: null, $values['status'],
            ]);
            $newId = (int)db()->lastInsertId();
            audit_log('warehouse_create', 'warehouses', $newId, $values);
            flash('success', "Warehouse {$values['code']} created.");
            redirect('/pages/warehouses/index.php');
        } catch (PDOException $e) {
            $errors[] = (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062)
                ? 'Code already exists for this company.' : 'Database error.';
        }
    }
}

$PAGE_TITLE = 'New warehouse';
require __DIR__ . '/../../partials/header.php';
?>
<div class="mb-6">
  <a href="/pages/warehouses/index.php" class="text-sm text-gray-500 hover:text-gray-900">&larr; Warehouses</a>
  <h1 class="text-2xl font-semibold text-gray-900 mt-1">New warehouse</h1>
</div>

<?php if ($errors): ?>
  <div class="mb-4 border border-red-200 bg-red-50 text-red-800 rounded p-3 text-sm max-w-2xl">
    <ul class="list-disc list-inside">
      <?php foreach ($errors as $err): ?><li><?= e_($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" class="bg-white border border-gray-200 rounded-lg p-5 max-w-2xl space-y-4">
  <?= csrf_field() ?>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
      <label class="block text-sm font-medium text-gray-700">Code</label>
      <input type="text" name="code" value="<?= e_($values['code']) ?>" required pattern="[A-Za-z0-9_\-]{1,64}"
             maxlength="64" style="text-transform:uppercase"
             class="mt-1 w-full rounded border-gray-300 shadow-sm font-mono">
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Name</label>
      <input type="text" name="name" value="<?= e_($values['name']) ?>" required maxlength="191"
             class="mt-1 w-full rounded border-gray-300 shadow-sm">
    </div>
    <div class="sm:col-span-2">
      <label class="block text-sm font-medium text-gray-700">Address</label>
      <textarea name="address" rows="2" class="mt-1 w-full rounded border-gray-300 shadow-sm"><?= e_($values['address']) ?></textarea>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Contact</label>
      <input type="text" name="contact" value="<?= e_($values['contact']) ?>" maxlength="191"
             class="mt-1 w-full rounded border-gray-300 shadow-sm">
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Status</label>
      <select name="status" class="mt-1 w-full rounded border-gray-300 shadow-sm">
        <?php foreach (['ACTIVE','INACTIVE'] as $s): ?>
          <option value="<?= e_($s) ?>" <?= $values['status'] === $s ? 'selected' : '' ?>><?= e_($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="flex justify-end gap-3 pt-2">
    <a href="/pages/warehouses/index.php" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    <button type="submit" class="slv-bg-primary text-white px-4 py-2 rounded text-sm font-medium">Create warehouse</button>
  </div>
</form>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
