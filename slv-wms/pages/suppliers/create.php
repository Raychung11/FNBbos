<?php
// SLV WMS — pages/suppliers/create.php
// Roles allowed: super_admin, warehouse_manager
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager']);
require __DIR__ . '/_save.php';

$errors = [];
$values = ['status' => 'ACTIVE'];
$isEdit = false;
$supplier_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    [$errors, $values] = suppliers_save_post(null);
    if (!$errors) {
        flash('success', "Supplier {$values['code']} created.");
        redirect('/pages/suppliers/index.php');
    }
}

$PAGE_TITLE = 'New supplier';
require __DIR__ . '/../../partials/header.php';
?>
<div class="mb-6">
  <a href="/pages/suppliers/index.php" class="text-sm text-gray-500 hover:text-gray-900">&larr; Suppliers</a>
  <h1 class="text-2xl font-semibold text-gray-900 mt-1">New supplier</h1>
</div>
<?php require __DIR__ . '/_form.php'; ?>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
