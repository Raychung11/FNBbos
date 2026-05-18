<?php
// SLV WMS — pages/reports/stock_on_hand.php
// Purpose: Per-(SKU, warehouse) stock summary with FIFO valuation. Click
//          a row to drill into its individual layers (received_at, bin,
//          unit_cost, qty_remaining).
// Roles allowed: super_admin, warehouse_manager, sales (RO), viewer (RO)
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','sales','viewer']);

$role = current_user()['role'] ?? '';

// Honour the user's warehouse access.
$accessibleIds = user_warehouse_ids();

// Filters from the query string.
$wid     = isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '' ? (int)$_GET['warehouse_id'] : null;
$pid     = isset($_GET['product_id'])   && $_GET['product_id']   !== '' ? (int)$_GET['product_id']   : null;
$drill   = isset($_GET['drill'])        && $_GET['drill']        !== '' ? (int)$_GET['drill']        : null;
$drillWh = isset($_GET['drill_wh'])     && $_GET['drill_wh']     !== '' ? (int)$_GET['drill_wh']     : null;

if ($wid !== null && !in_array($wid, $accessibleIds, true) && $role !== 'super_admin') {
    flash('error', 'No access to that warehouse.');
    redirect('/pages/reports/stock_on_hand.php');
}

// Build the WH dropdown.
$whWhere = ['company_id = ?'];
$whParams = [company_id()];
if ($role !== 'super_admin' && $accessibleIds) {
    $whWhere[]  = 'id IN (' . implode(',', array_fill(0, count($accessibleIds), '?')) . ')';
    $whParams   = array_merge($whParams, $accessibleIds);
} elseif ($role !== 'super_admin' && !$accessibleIds) {
    $whWhere[] = '1 = 0';
}
$whStmt = db()->prepare('SELECT id, code, name FROM warehouses WHERE ' . implode(' AND ', $whWhere) . ' ORDER BY code');
$whStmt->execute($whParams);
$warehouses = $whStmt->fetchAll();

// Build the product dropdown (active SKUs only — keeps the menu short).
$prodStmt = db()->prepare(
    "SELECT id, sku_code, name FROM products
      WHERE company_id = ? AND status = 'ACTIVE' ORDER BY sku_code"
);
$prodStmt->execute([company_id()]);
$products = $prodStmt->fetchAll();

// Pull the summary, narrowing if filters are set.
$summary = stock_on_hand_summary(company_id(), $wid, $pid);

// Aggregate totals across the visible rows.
$totalQty   = 0.0;
$totalValue = 0.0;
foreach ($summary as $r) {
    $totalQty   += (float)$r['qty_total'];
    $totalValue += (float)$r['value_total'];
}

// Drilldown rows (only when ?drill=<product_id>&drill_wh=<warehouse_id>).
$drillRows = [];
$drillProduct = null;
$drillWarehouse = null;
if ($drill && $drillWh) {
    $p = db()->prepare('SELECT id, sku_code, name FROM products WHERE id = ? AND company_id = ?');
    $p->execute([$drill, company_id()]);
    $drillProduct = $p->fetch();
    $w = db()->prepare('SELECT id, code, name FROM warehouses WHERE id = ? AND company_id = ?');
    $w->execute([$drillWh, company_id()]);
    $drillWarehouse = $w->fetch();
    if ($drillProduct && $drillWarehouse) {
        $drillRows = stock_layer_drilldown($drill, $drillWh);
    }
}

$PAGE_TITLE = 'Stock on hand';
require __DIR__ . '/../../partials/header.php';
?>
<div class="mb-6">
  <a href="/pages/reports/index.php" class="text-sm text-gray-500 hover:text-gray-900">&larr; Reports</a>
  <h1 class="text-2xl font-semibold text-gray-900 mt-1">Stock on hand · FIFO valuation</h1>
  <p class="text-sm text-gray-500 mt-1">
    Each row aggregates active FIFO layers for one (SKU, warehouse) pair.
    Click any row to see its individual layers and bins.
  </p>
</div>

<form method="get" class="bg-white border border-gray-200 rounded-lg p-3 mb-4 flex flex-wrap items-end gap-3 text-sm">
  <div>
    <label class="block text-xs font-medium text-gray-500">Warehouse</label>
    <select name="warehouse_id" class="mt-1 rounded border-gray-300 shadow-sm">
      <option value="">All accessible</option>
      <?php foreach ($warehouses as $w): ?>
        <option value="<?= e_($w['id']) ?>" <?= $wid === (int)$w['id'] ? 'selected' : '' ?>>
          <?= e_($w['code']) ?> — <?= e_($w['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-gray-500">SKU</label>
    <select name="product_id" class="mt-1 rounded border-gray-300 shadow-sm">
      <option value="">All</option>
      <?php foreach ($products as $p): ?>
        <option value="<?= e_($p['id']) ?>" <?= $pid === (int)$p['id'] ? 'selected' : '' ?>>
          <?= e_($p['sku_code']) ?> — <?= e_($p['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="slv-bg-primary text-white px-3 py-2 rounded">Filter</button>
  <a href="/pages/reports/stock_on_hand.php" class="px-3 py-2 text-gray-600 hover:text-gray-900">Reset</a>
</form>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
  <div class="bg-white border border-gray-200 rounded-lg p-4">
    <div class="text-xs text-gray-500">Rows shown</div>
    <div class="text-2xl font-semibold slv-text-primary"><?= e_((string)count($summary)) ?></div>
  </div>
  <div class="bg-white border border-gray-200 rounded-lg p-4">
    <div class="text-xs text-gray-500">Total qty</div>
    <div class="text-2xl font-semibold slv-text-primary"><?= e_(qty($totalQty)) ?></div>
  </div>
  <div class="bg-white border border-gray-200 rounded-lg p-4">
    <div class="text-xs text-gray-500">Total value (FIFO)</div>
    <div class="text-2xl font-semibold slv-text-primary"><?= e_(money($totalValue)) ?></div>
  </div>
</div>

<div class="bg-white border border-gray-200 rounded-lg overflow-hidden mb-6">
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 text-gray-600">
      <tr>
        <th class="text-left px-4 py-2 font-medium">SKU</th>
        <th class="text-left px-4 py-2 font-medium">Name</th>
        <th class="text-left px-4 py-2 font-medium">Warehouse</th>
        <th class="text-right px-4 py-2 font-medium">Qty</th>
        <th class="text-right px-4 py-2 font-medium">Avg cost</th>
        <th class="text-right px-4 py-2 font-medium">Value (FIFO)</th>
        <th class="text-right px-4 py-2 font-medium">Layers</th>
        <th class="px-4 py-2"></th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$summary): ?>
        <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No active stock in scope. Run an opening-stock import in Master → CSV imports.</td></tr>
      <?php endif; ?>
      <?php foreach ($summary as $r):
        $isDrilled = $drill === (int)$r['product_id'] && $drillWh === (int)$r['warehouse_id'];
        $drillUrl  = '/pages/reports/stock_on_hand.php?drill=' . (int)$r['product_id'] . '&drill_wh=' . (int)$r['warehouse_id']
                   . ($wid !== null ? '&warehouse_id=' . $wid : '')
                   . ($pid !== null ? '&product_id='   . $pid : '');
      ?>
        <tr class="<?= $isDrilled ? 'bg-indigo-50' : '' ?>">
          <td class="px-4 py-2 font-mono"><?= e_($r['sku_code']) ?></td>
          <td class="px-4 py-2"><?= e_($r['name']) ?></td>
          <td class="px-4 py-2 font-mono text-xs"><?= e_($r['warehouse_code']) ?></td>
          <td class="px-4 py-2 text-right"><?= e_(qty($r['qty_total'])) ?></td>
          <td class="px-4 py-2 text-right text-gray-500"><?= e_(money($r['avg_cost'])) ?></td>
          <td class="px-4 py-2 text-right font-semibold"><?= e_(money($r['value_total'])) ?></td>
          <td class="px-4 py-2 text-right text-gray-500"><?= e_((string)$r['layer_count']) ?></td>
          <td class="px-4 py-2 text-right">
            <a href="<?= e_($drillUrl) ?>" class="text-indigo-700 hover:underline"><?= $isDrilled ? 'Hide' : 'Drilldown' ?></a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($drillRows): ?>
  <section class="bg-white border border-gray-200 rounded-lg overflow-hidden">
    <header class="px-4 py-3 border-b border-gray-200 bg-indigo-50">
      <div class="text-xs uppercase tracking-wide text-indigo-700">Layer drilldown</div>
      <div class="font-semibold text-gray-900">
        <?= e_($drillProduct['sku_code']) ?> · <?= e_($drillProduct['name']) ?>
        <span class="text-sm text-gray-500 font-normal">in <?= e_($drillWarehouse['code']) ?> — <?= e_($drillWarehouse['name']) ?></span>
      </div>
    </header>
    <table class="min-w-full text-sm">
      <thead class="bg-gray-50 text-gray-600">
        <tr>
          <th class="text-left px-4 py-2 font-medium">Layer</th>
          <th class="text-left px-4 py-2 font-medium">Bin</th>
          <th class="text-left px-4 py-2 font-medium">Received</th>
          <th class="text-left px-4 py-2 font-medium">Source</th>
          <th class="text-right px-4 py-2 font-medium">Qty rcvd</th>
          <th class="text-right px-4 py-2 font-medium">Qty remaining</th>
          <th class="text-right px-4 py-2 font-medium">Unit cost</th>
          <th class="text-right px-4 py-2 font-medium">Layer value</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php foreach ($drillRows as $l):
          $val = (float)$l['qty_remaining'] * (float)$l['unit_cost']; ?>
          <tr>
            <td class="px-4 py-2 font-mono text-xs">#<?= e_((string)$l['id']) ?></td>
            <td class="px-4 py-2 font-mono text-xs"><?= e_($l['bin_full_code']) ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= e_($l['received_at']) ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= e_($l['source_type']) ?><?= $l['source_ref_id'] ? ' #' . e_((string)$l['source_ref_id']) : '' ?></td>
            <td class="px-4 py-2 text-right"><?= e_(qty($l['qty_received'])) ?></td>
            <td class="px-4 py-2 text-right font-semibold"><?= e_(qty($l['qty_remaining'])) ?></td>
            <td class="px-4 py-2 text-right"><?= e_(money($l['unit_cost'])) ?></td>
            <td class="px-4 py-2 text-right font-semibold"><?= e_(money($val)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php elseif ($drill && $drillWh): ?>
  <p class="text-sm text-gray-500">No active layers for that selection.</p>
<?php endif; ?>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
