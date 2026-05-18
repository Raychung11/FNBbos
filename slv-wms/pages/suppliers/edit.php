<?php
// SLV WMS — pages/suppliers/edit.php
// Roles allowed: super_admin, warehouse_manager
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager']);
require __DIR__ . '/_save.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Missing supplier id.');
    redirect('/pages/suppliers/index.php');
}

$stmt = db()->prepare('SELECT * FROM suppliers WHERE id = ? AND company_id = ?');
$stmt->execute([$id, company_id()]);
$existing = $stmt->fetch();
if (!$existing) {
    flash('error', 'Supplier not found.');
    redirect('/pages/suppliers/index.php');
}

$errors = [];
$values = $existing;
$isEdit = true;
$supplier_id = $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    [$errors, $values] = suppliers_save_post($existing);
    if (!$errors) {
        flash('success', "Supplier {$values['code']} updated.");
        redirect('/pages/suppliers/index.php');
    }
}

$PAGE_TITLE = 'Edit supplier';
require __DIR__ . '/../../partials/header.php';
?>
<div class="mb-6">
  <a href="/pages/suppliers/index.php" class="text-sm text-gray-500 hover:text-gray-900">&larr; Suppliers</a>
  <h1 class="text-2xl font-semibold text-gray-900 mt-1">Edit supplier</h1>
</div>
<?php require __DIR__ . '/_form.php'; ?>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
