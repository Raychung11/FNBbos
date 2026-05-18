<?php
// SLV WMS — pages/customers/create.php
// Roles allowed: super_admin, warehouse_manager, sales
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','sales']);
require __DIR__ . '/_save.php';

$tstmt = db()->prepare("SELECT id, code, name FROM tax_groups WHERE company_id = ? AND is_active = 1 ORDER BY code");
$tstmt->execute([company_id()]);
$TAX_GROUPS = $tstmt->fetchAll();

$errors = [];
$values = ['status' => 'ACTIVE', 'credit_limit' => '0'];
$isEdit = false;
$customer_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    [$errors, $values] = customers_save_post(null);
    if (!$errors) {
        flash('success', "Customer {$values['code']} created.");
        redirect('/pages/customers/index.php');
    }
}

$PAGE_TITLE = 'New customer';
require __DIR__ . '/../../partials/header.php';
?>
<div class="mb-6">
  <a href="/pages/customers/index.php" class="text-sm text-gray-500 hover:text-gray-900">&larr; Customers</a>
  <h1 class="text-2xl font-semibold text-gray-900 mt-1">New customer</h1>
</div>
<?php require __DIR__ . '/_form.php'; ?>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
