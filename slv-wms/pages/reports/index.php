<?php
// SLV WMS — pages/reports/index.php
// Purpose: Reports landing.
// Roles allowed: super_admin, warehouse_manager, sales, viewer
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','sales','viewer']);

$cards = [
    ['Stock on hand',     'Per-warehouse SKU stock with FIFO valuation and per-layer drilldown.', '/pages/reports/stock_on_hand.php', true],
    ['Stock movements',   'Append-only ledger of every quantity change. Filterable by warehouse, SKU, date, type.', '/pages/reports/stock_movements.php', true],
    ['Stock valuation',   'Per-layer breakdown for any SKU. Lives in the stock-on-hand drilldown.', '/pages/reports/stock_on_hand.php', true],
    ['Slow movers / dead stock', 'SKUs with no PICK/TRANSFER_OUT activity in N days.', '#', false],
    ['Picker productivity',     'Lines / scans per hour, grouped by user.',                    '#', false],
    ['GRN aging',               'Pending receipts by age bucket.',                              '#', false],
    ['DO status / on-time',     'Delivery state and on-time vs late counts.',                   '#', false],
    ['Tax report (SST)',        'Output tax collected per tax code per period (for SST filing).','#', false],
];

$PAGE_TITLE = 'Reports';
require __DIR__ . '/../../partials/header.php';
?>
<h1 class="text-2xl font-semibold text-gray-900 mb-6">Reports</h1>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
  <?php foreach ($cards as [$title, $desc, $href, $live]): ?>
    <?php if ($live): ?>
      <a href="<?= e_($href) ?>" class="block bg-white border border-gray-200 rounded-lg p-5 hover:border-indigo-300 hover:shadow-sm transition">
        <div class="font-semibold text-gray-900"><?= e_($title) ?></div>
        <div class="mt-1 text-sm text-gray-500"><?= e_($desc) ?></div>
      </a>
    <?php else: ?>
      <div class="bg-gray-50 border border-dashed border-gray-300 rounded-lg p-5">
        <div class="font-semibold text-gray-500"><?= e_($title) ?>
          <span class="ml-1 text-xs font-normal text-amber-700">(coming)</span>
        </div>
        <div class="mt-1 text-sm text-gray-500"><?= e_($desc) ?></div>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
