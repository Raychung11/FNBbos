<?php
// SLV WMS — pages/products/index.php
// Purpose: List + search + filter products.
// Roles allowed: super_admin, warehouse_manager, sales, viewer (read-only)
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','sales','viewer']);

$canEdit = in_array(current_user()['role'] ?? '', ['super_admin','warehouse_manager'], true);

$q          = trim((string)($_GET['q']       ?? ''));
$categoryId = (string)($_GET['category_id']  ?? '');
$status     = (string)($_GET['status']       ?? '');

$where  = ['p.company_id = ?'];
$params = [company_id()];
if ($q !== '') {
    $where[] = '(p.sku_code LIKE ? OR p.name LIKE ? OR EXISTS (
                 SELECT 1 FROM product_barcodes pb WHERE pb.product_id = p.id AND pb.barcode LIKE ?))';
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($categoryId !== '') {
    $where[]  = 'p.category_id = ?';
    $params[] = (int)$categoryId;
}
if ($status !== '' && in_array($status, ['ACTIVE','INACTIVE'], true)) {
    $where[]  = 'p.status = ?';
    $params[] = $status;
}
$wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT p.id, p.sku_code, p.name, p.uom, p.selling_price, p.status,
               c.name AS category_name,
               (SELECT pb.barcode FROM product_barcodes pb WHERE pb.product_id = p.id AND pb.is_primary = 1 LIMIT 1) AS primary_barcode,
               (SELECT COUNT(*) FROM product_barcodes pb WHERE pb.product_id = p.id) AS barcode_count
          FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
        $wsql
      ORDER BY p.sku_code
        LIMIT 500";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$cstmt = db()->prepare('SELECT id, name FROM categories WHERE company_id = ? ORDER BY name');
$cstmt->execute([company_id()]);
$categories = $cstmt->fetchAll();

$PAGE_TITLE = 'Products';
require __DIR__ . '/../../partials/header.php';
?>
<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-semibold text-gray-900">Products / SKUs</h1>
  <?php if ($canEdit): ?>
    <div class="flex gap-2">
      <a href="/pages/imports/upload.php?type=products" class="px-3 py-2 rounded text-sm border border-gray-300 hover:bg-gray-50">Import CSV</a>
      <a href="/pages/products/create.php" class="slv-bg-primary text-white px-3 py-2 rounded text-sm font-medium">+ Add product</a>
    </div>
  <?php endif; ?>
</div>

<form method="get" class="bg-white border border-gray-200 rounded-lg p-3 mb-4 flex flex-wrap gap-3 items-end text-sm">
  <div>
    <label class="block text-xs font-medium text-gray-500">Search</label>
    <input type="text" name="q" value="<?= e_($q) ?>" placeholder="SKU, name, or barcode"
           class="mt-1 rounded border-gray-300 shadow-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-gray-500">Category</label>
    <select name="category_id" class="mt-1 rounded border-gray-300 shadow-sm">
      <option value="">All</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= e_($c['id']) ?>" <?= $categoryId === (string)$c['id'] ? 'selected' : '' ?>><?= e_($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-gray-500">Status</label>
    <select name="status" class="mt-1 rounded border-gray-300 shadow-sm">
      <option value="">All</option>
      <?php foreach (['ACTIVE','INACTIVE'] as $s): ?>
        <option value="<?= e_($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e_($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="flex gap-2">
    <button class="slv-bg-primary text-white px-3 py-2 rounded">Filter</button>
    <a href="/pages/products/index.php" class="px-3 py-2 text-gray-600 hover:text-gray-900">Reset</a>
  </div>
</form>

<div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 text-gray-600">
      <tr>
        <th class="text-left px-4 py-2 font-medium">SKU</th>
        <th class="text-left px-4 py-2 font-medium">Name</th>
        <th class="text-left px-4 py-2 font-medium">Category</th>
        <th class="text-left px-4 py-2 font-medium">UoM</th>
        <th class="text-right px-4 py-2 font-medium">Price</th>
        <th class="text-left px-4 py-2 font-medium">Primary barcode</th>
        <th class="text-left px-4 py-2 font-medium">Status</th>
        <?php if ($canEdit): ?><th class="px-4 py-2"></th><?php endif; ?>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$products): ?>
        <tr><td colspan="<?= $canEdit ? 8 : 7 ?>" class="px-4 py-6 text-center text-gray-400">No products match.</td></tr>
      <?php endif; ?>
      <?php foreach ($products as $p): ?>
        <tr>
          <td class="px-4 py-2 font-mono"><?= e_($p['sku_code']) ?></td>
          <td class="px-4 py-2"><?= e_($p['name']) ?></td>
          <td class="px-4 py-2 text-gray-500"><?= e_($p['category_name'] ?: '—') ?></td>
          <td class="px-4 py-2 text-gray-500"><?= e_($p['uom']) ?></td>
          <td class="px-4 py-2 text-right font-mono"><?= e_(money($p['selling_price'])) ?></td>
          <td class="px-4 py-2 font-mono text-xs">
            <?= e_($p['primary_barcode'] ?: '—') ?>
            <?php if ((int)$p['barcode_count'] > 1): ?>
              <span class="text-gray-400">+<?= (int)$p['barcode_count'] - 1 ?></span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-2">
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs <?= $p['status'] === 'ACTIVE' ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
              <?= e_($p['status']) ?>
            </span>
          </td>
          <?php if ($canEdit): ?>
            <td class="px-4 py-2 text-right whitespace-nowrap">
              <a href="/pages/products/edit.php?id=<?= e_($p['id']) ?>" class="text-indigo-700 hover:underline">Edit</a>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (count($products) >= 500): ?>
    <p class="px-4 py-2 text-xs text-gray-500 bg-gray-50 border-t">Showing the first 500 results. Refine the search.</p>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
