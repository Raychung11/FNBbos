<?php
// SLV WMS — pages/products/create.php
// Purpose: Create a new SKU + barcodes.
// Roles allowed: super_admin, warehouse_manager
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager']);
require __DIR__ . '/_form_data.php';
require __DIR__ . '/_save.php';

$errors   = [];
$values   = ['status' => 'ACTIVE', 'uom' => 'pcs', 'pack_size' => '1', 'selling_price' => '0',
             'min_qty' => '0', 'max_qty' => '0', 'reorder_point' => '0'];
$barcodes = [];
$isEdit   = false;
$product_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    [$errors, $values, $barcodes] = products_save_post(null);
    if (!$errors) {
        flash('success', "Product {$values['sku_code']} created.");
        redirect('/pages/products/index.php');
    }
}

$PAGE_TITLE = 'New product';
require __DIR__ . '/../../partials/header.php';
?>
<div class="mb-6">
  <a href="/pages/products/index.php" class="text-sm text-gray-500 hover:text-gray-900">&larr; Products</a>
  <h1 class="text-2xl font-semibold text-gray-900 mt-1">New product</h1>
</div>
<?php require __DIR__ . '/_form.php'; ?>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
