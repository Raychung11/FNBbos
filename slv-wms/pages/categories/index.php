<?php
// SLV WMS — pages/categories/index.php
// Purpose: List + inline create/edit for product categories.
// Roles allowed: super_admin, warehouse_manager (RW)
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager']);

$errors = [];
$action = $_REQUEST['action'] ?? 'list';
$id     = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
$editing = null;

if ($action === 'edit' && $id > 0) {
    $stmt = db()->prepare('SELECT * FROM categories WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, company_id()]);
    $editing = $stmt->fetch() ?: null;
    if (!$editing) {
        flash('error', 'Category not found.');
        redirect('/pages/categories/index.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = (string)($_POST['_action'] ?? 'save');

    if ($do === 'delete') {
        $cid = (int)($_POST['id'] ?? 0);
        $used = db()->prepare('SELECT COUNT(*) FROM products WHERE category_id = ?');
        $used->execute([$cid]);
        if ((int)$used->fetchColumn() > 0) {
            flash('error', 'Cannot delete: products are in this category.');
        } else {
            db()->prepare('DELETE FROM categories WHERE id = ? AND company_id = ?')->execute([$cid, company_id()]);
            audit_log('category_delete', 'categories', $cid);
            flash('success', 'Category deleted.');
        }
        redirect('/pages/categories/index.php');
    }

    $editing_id = (int)($_POST['id'] ?? 0);
    $name       = trim((string)($_POST['name'] ?? ''));
    $parent_raw = (string)($_POST['parent_id'] ?? '');
    $parent_id  = $parent_raw === '' ? null : (int)$parent_raw;

    if ($name === '' || mb_strlen($name) > 191) $errors[] = 'Name is required (max 191).';
    if ($parent_id !== null) {
        $vrf = db()->prepare('SELECT id FROM categories WHERE id = ? AND company_id = ?');
        $vrf->execute([$parent_id, company_id()]);
        if (!$vrf->fetch())                     $errors[] = 'Parent category invalid.';
        if ($editing_id > 0 && $parent_id === $editing_id) $errors[] = 'A category cannot be its own parent.';
    }

    if (!$errors) {
        try {
            if ($editing_id > 0) {
                db()->prepare('UPDATE categories SET name = ?, parent_id = ? WHERE id = ? AND company_id = ?')
                    ->execute([$name, $parent_id, $editing_id, company_id()]);
                audit_log('category_update', 'categories', $editing_id, compact('name','parent_id'));
                flash('success', 'Category updated.');
            } else {
                db()->prepare('INSERT INTO categories (company_id, name, parent_id) VALUES (?, ?, ?)')
                    ->execute([company_id(), $name, $parent_id]);
                audit_log('category_create', 'categories', (int)db()->lastInsertId(), compact('name','parent_id'));
                flash('success', 'Category created.');
            }
            redirect('/pages/categories/index.php');
        } catch (PDOException $e) {
            $errors[] = (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062)
                ? 'A category with that name already exists at this level.' : 'Database error.';
            $editing = ['id' => $editing_id, 'name' => $name, 'parent_id' => $parent_id];
            $action = 'edit';
        }
    } else {
        $editing = ['id' => $editing_id, 'name' => $name, 'parent_id' => $parent_id];
        $action  = 'edit';
    }
}

// Load tree for display + parent dropdown
$stmt = db()->prepare(
    "SELECT c.id, c.name, c.parent_id, p.name AS parent_name,
            (SELECT COUNT(*) FROM products pr WHERE pr.category_id = c.id) AS product_count
       FROM categories c
  LEFT JOIN categories p ON p.id = c.parent_id
      WHERE c.company_id = ?
   ORDER BY COALESCE(p.name, c.name), c.name"
);
$stmt->execute([company_id()]);
$categories = $stmt->fetchAll();

$PAGE_TITLE = 'Categories';
require __DIR__ . '/../../partials/header.php';
?>
<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-semibold text-gray-900">Product categories</h1>
  <a href="?action=new" class="slv-bg-primary text-white px-3 py-2 rounded text-sm font-medium">+ Add category</a>
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
        <th class="text-left px-4 py-2 font-medium">Name</th>
        <th class="text-left px-4 py-2 font-medium">Parent</th>
        <th class="text-right px-4 py-2 font-medium">Products</th>
        <th class="px-4 py-2"></th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$categories): ?>
        <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No categories yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($categories as $c): ?>
        <tr>
          <td class="px-4 py-2"><?= e_($c['name']) ?></td>
          <td class="px-4 py-2 text-gray-500"><?= e_($c['parent_name'] ?: '—') ?></td>
          <td class="px-4 py-2 text-right text-gray-500"><?= e_((string)$c['product_count']) ?></td>
          <td class="px-4 py-2 text-right whitespace-nowrap">
            <a href="?action=edit&id=<?= e_($c['id']) ?>" class="text-indigo-700 hover:underline">Edit</a>
            <form method="post" class="inline" onsubmit="return confirm('Delete category <?= e_($c['name']) ?>?');">
              <?= csrf_field() ?>
              <input type="hidden" name="_action" value="delete">
              <input type="hidden" name="id" value="<?= e_($c['id']) ?>">
              <button class="ml-3 text-red-600 hover:underline">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($action === 'new' || $action === 'edit' || $editing !== null): ?>
  <h2 class="text-lg font-semibold text-gray-900 mb-3"><?= $editing && !empty($editing['id']) ? 'Edit category' : 'New category' ?></h2>
  <form method="post" class="bg-white border border-gray-200 rounded-lg p-5 max-w-xl space-y-4">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="save">
    <input type="hidden" name="id" value="<?= e_($editing['id'] ?? '') ?>">
    <div>
      <label class="block text-sm font-medium text-gray-700">Name</label>
      <input type="text" name="name" value="<?= e_($editing['name'] ?? '') ?>" required maxlength="191"
             class="mt-1 w-full rounded border-gray-300 shadow-sm">
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Parent</label>
      <select name="parent_id" class="mt-1 w-full rounded border-gray-300 shadow-sm">
        <option value="">— top level —</option>
        <?php foreach ($categories as $c):
          if ($editing && (int)$c['id'] === (int)($editing['id'] ?? 0)) continue; ?>
          <option value="<?= e_($c['id']) ?>" <?= (int)($editing['parent_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
            <?= e_($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="flex justify-end gap-3 pt-2">
      <a href="/pages/categories/index.php" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Cancel</a>
      <button type="submit" class="slv-bg-primary text-white px-4 py-2 rounded text-sm font-medium">
        <?= $editing && !empty($editing['id']) ? 'Update' : 'Create' ?>
      </button>
    </div>
  </form>
<?php endif; ?>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
