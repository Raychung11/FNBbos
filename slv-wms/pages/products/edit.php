<?php
// SLV WMS — pages/products/edit.php
// Purpose: Edit an existing SKU + replace its barcodes.
// Roles allowed: super_admin, warehouse_manager
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager']);
require __DIR__ . '/_form_data.php';
require __DIR__ . '/_save.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Missing product id.');
    redirect('/pages/products/index.php');
}

$stmt = db()->prepare('SELECT * FROM products WHERE id = ? AND company_id = ?');
$stmt->execute([$id, company_id()]);
$existing = $stmt->fetch();
if (!$existing) {
    flash('error', 'Product not found.');
    redirect('/pages/products/index.php');
}

$bstmt = db()->prepare(
    'SELECT barcode, type, is_primary FROM product_barcodes WHERE product_id = ? ORDER BY is_primary DESC, id'
);
$bstmt->execute([$id]);
$barcodes = $bstmt->fetchAll();

$errors = [];
$values = $existing;
$isEdit = true;
$product_id = $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    [$errors, $values, $barcodes] = products_save_post($existing);
    if (!$errors) {
        flash('success', "Product {$values['sku_code']} updated.");
        redirect('/pages/products/index.php');
    }
}

$PAGE_TITLE = 'Edit product';
require __DIR__ . '/../../partials/header.php';
?>
<div class="mb-6">
  <a href="/pages/products/index.php" class="text-sm text-gray-500 hover:text-gray-900">&larr; Products</a>
  <h1 class="text-2xl font-semibold text-gray-900 mt-1">Edit product</h1>
</div>
<?php require __DIR__ . '/_form.php'; ?>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
