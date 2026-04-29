<?php
// SLV WMS — pages/dashboard.php
// Purpose: Phase 0 dashboard skeleton. Shows the warehouse filter and
//          placeholder tiles. Real numbers wire up in Phase 12.
// Roles allowed: any logged-in role (data scoped to user_warehouse_ids)
// Last updated: 2026-04-29

declare(strict_types=1);

if (!function_exists('require_login')) {
    require __DIR__ . '/../lib/bootstrap.php';
}
require_login();

// Handle warehouse-filter change posted from the header form.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'set_warehouse') {
    verify_csrf();
    $raw = $_POST['warehouse_id'] ?? '';
    $id  = ($raw === '' || $raw === 'all') ? null : (int)$raw;
    try {
        set_selected_warehouse_id($id);
        flash('success', $id === null ? 'Showing all warehouses.' : 'Warehouse filter applied.');
    } catch (Throwable $e) {
        flash('error', 'No access to that warehouse.');
    }
    redirect('/index.php');
}

// Resolve the warehouse list this user can see.
$accessible_ids = user_warehouse_ids();

$warehouses = [];
if ($accessible_ids) {
    $in = implode(',', array_fill(0, count($accessible_ids), '?'));
    $stmt = db()->prepare(
        "SELECT id, code, name FROM warehouses
          WHERE company_id = ? AND id IN ($in)
          ORDER BY code"
    );
    $stmt->execute(array_merge([company_id()], $accessible_ids));
    $warehouses = $stmt->fetchAll();
}

$selected = selected_warehouse_id();

$PAGE_TITLE = 'Dashboard';
require __DIR__ . '/../partials/header.php';
?>
<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-semibold text-gray-900">Dashboard</h1>

  <form method="post" action="/index.php" class="flex items-center gap-2">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="set_warehouse">
    <label class="text-sm text-gray-600">Warehouse:</label>
    <select name="warehouse_id" onchange="this.form.submit()"
            class="rounded border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
      <option value="all" <?= $selected === null ? 'selected' : '' ?>>All warehouses</option>
      <?php foreach ($warehouses as $w): ?>
        <option value="<?= e_($w['id']) ?>" <?= $selected === (int)$w['id'] ? 'selected' : '' ?>>
          <?= e_($w['code']) ?> — <?= e_($w['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
  <?php
  $tiles = [
      ['Total SKUs',         '—', 'Master data not yet loaded'],
      ['Stock value (FIFO)', '—', 'Phase 3 enables FIFO valuation'],
      ['Pending GRN',        '—', 'Phase 4'],
      ['Pending pick lists', '—', 'Phase 6'],
      ['Pending deliveries', '—', 'Phase 8'],
      ['In-transit transfers','—','Phase 11'],
      ['Today receipts',     '—', 'Updates after Phase 4'],
      ['Today shipments',    '—', 'Updates after Phase 6'],
  ];
  foreach ($tiles as [$label, $value, $hint]):
  ?>
    <div class="bg-white border border-gray-200 rounded-lg p-4">
      <div class="text-sm text-gray-500"><?= e_($label) ?></div>
      <div class="mt-1 text-2xl font-semibold slv-text-primary"><?= e_($value) ?></div>
      <div class="mt-1 text-xs text-gray-400"><?= e_($hint) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="mt-8 bg-white border border-gray-200 rounded-lg p-6">
  <h2 class="text-base font-semibold text-gray-900 mb-2">Phase 0 — foundation deployed</h2>
  <p class="text-sm text-gray-600">
    This is a skeleton. Identity, settings, tax, document numbering and the
    audit log tables are live. Subsequent phases will populate this dashboard
    with real numbers.
  </p>
  <ul class="mt-3 text-sm text-gray-600 list-disc list-inside space-y-1">
    <li><span class="font-medium">Phase 1:</span> Settings backend (branding, doc numbering, tax, SMTP, users).</li>
    <li><span class="font-medium">Phase 2:</span> Master data (categories, products, bins, suppliers, customers).</li>
    <li><span class="font-medium">Phase 3:</span> FIFO engine + opening stock import + stock-on-hand report.</li>
    <li><span class="font-medium">Phase 4+:</span> GRN, picking, invoicing, transfers, reports.</li>
  </ul>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
