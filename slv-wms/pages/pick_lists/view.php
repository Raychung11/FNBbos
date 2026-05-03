<?php
// SLV WMS — pages/pick_lists/view.php
// Purpose: Read-only desktop view of a pick list with its walk-path.
//          Mobile picking (which actually consumes stock via record_issue)
//          ships in Phase 7.
// Roles allowed: super_admin, warehouse_manager, picker, packer
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','picker','packer']);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Missing pick list id.');
    redirect('/pages/pick_lists/index.php');
}

$stmt = db()->prepare(
    'SELECT pl.*, w.code AS warehouse_code, w.name AS warehouse_name,
            so.so_no, so.customer_id, so.grand_total, so.status AS so_status,
            c.code AS customer_code, c.name AS customer_name,
            u.name AS assigned_name,
            cu.name AS creator_name
       FROM pick_lists pl
       JOIN warehouses w   ON w.id = pl.warehouse_id
       JOIN sales_orders so ON so.id = pl.so_id
       JOIN customers   c  ON c.id = so.customer_id
  LEFT JOIN users       u  ON u.id = pl.assigned_user_id
  LEFT JOIN users      cu  ON cu.id = pl.created_by
      WHERE pl.id = ? AND pl.company_id = ?'
);
$stmt->execute([$id, company_id()]);
$pl = $stmt->fetch();
if (!$pl) {
    flash('error', 'Pick list not found.');
    redirect('/pages/pick_lists/index.php');
}
require_warehouse_access((int)$pl['warehouse_id']);

$lstmt = db()->prepare(
    'SELECT pi.*,
            p.sku_code, p.name AS product_name, p.uom,
            (SELECT pb.barcode FROM product_barcodes pb
              WHERE pb.product_id = pi.product_id AND pb.is_primary = 1
              LIMIT 1) AS primary_barcode,
            sb.full_code AS suggested_full,
            sb.code      AS suggested_code,
            ab.full_code AS picked_from_full,
            up.name      AS picker_name
       FROM pick_items pi
       JOIN products p ON p.id = pi.product_id
  LEFT JOIN bins  sb ON sb.id = pi.suggested_bin_id
  LEFT JOIN bins  ab ON ab.id = pi.picked_from_bin_id
  LEFT JOIN users up ON up.id = pi.picked_by
      WHERE pi.pick_list_id = ?
   ORDER BY pi.walk_order, pi.id'
);
$lstmt->execute([$id]);
$items = $lstmt->fetchAll();

$qtyTotal = 0.0;
$qtyDone  = 0.0;
$shortNoBin = 0;
foreach ($items as $i) {
    $qtyTotal += (float)$i['qty_to_pick'];
    $qtyDone  += (float)$i['qty_picked'];
    if ($i['suggested_bin_id'] === null) $shortNoBin++;
}

$statusClass = match ($pl['status']) {
    'DRAFT'       => 'bg-gray-100 text-gray-700',
    'ASSIGNED'    => 'bg-blue-50  text-blue-700',
    'IN_PROGRESS' => 'bg-amber-50 text-amber-800',
    'COMPLETED'   => 'bg-green-50 text-green-700',
    'CANCELLED'   => 'bg-red-50   text-red-700',
    default       => 'bg-gray-100 text-gray-500',
};

$PAGE_TITLE = 'Pick ' . $pl['pick_no'];
require __DIR__ . '/../../partials/header.php';
?>
<div class="mb-6 flex items-start justify-between gap-3">
  <div>
    <a href="/pages/pick_lists/index.php" class="text-sm text-gray-500 hover:text-gray-900">&larr; Pick lists</a>
    <h1 class="text-2xl font-semibold text-gray-900 mt-1">
      <?= e_($pl['pick_no']) ?>
      <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs <?= $statusClass ?>"><?= e_($pl['status']) ?></span>
    </h1>
    <div class="text-sm text-gray-500 mt-1">
      <?= e_($pl['warehouse_code']) ?> — <?= e_($pl['warehouse_name']) ?>
      · for SO
      <a class="text-indigo-700 hover:underline font-mono" href="/pages/sales_orders/view.php?id=<?= e_($pl['so_id']) ?>">
        <?= e_($pl['so_no']) ?>
      </a>
      · customer <span class="font-mono"><?= e_($pl['customer_code']) ?></span> <?= e_($pl['customer_name']) ?>
    </div>
  </div>
  <div class="text-sm text-gray-500 text-right">
    <div>created <?= e_($pl['created_at']) ?></div>
    <?php if ($pl['creator_name']): ?>
      <div class="text-xs">by <?= e_($pl['creator_name']) ?></div>
    <?php endif; ?>
  </div>
</div>

<!-- Header tiles -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
  <div class="bg-white border border-gray-200 rounded p-3">
    <div class="text-xs text-gray-500">Lines</div>
    <div class="text-xl font-semibold slv-text-primary"><?= e_((string)count($items)) ?></div>
  </div>
  <div class="bg-white border border-gray-200 rounded p-3">
    <div class="text-xs text-gray-500">Qty to pick</div>
    <div class="text-xl font-semibold slv-text-primary"><?= e_(qty($qtyTotal)) ?></div>
  </div>
  <div class="bg-white border border-gray-200 rounded p-3">
    <div class="text-xs text-gray-500">Qty picked</div>
    <div class="text-xl font-semibold <?= $qtyDone >= $qtyTotal && $qtyTotal > 0 ? 'text-emerald-700' : 'slv-text-primary' ?>">
      <?= e_(qty($qtyDone)) ?>
    </div>
  </div>
  <div class="bg-white border <?= $shortNoBin > 0 ? 'border-amber-300 bg-amber-50' : 'border-gray-200' ?> rounded p-3">
    <div class="text-xs text-gray-500">Short-stock lines</div>
    <div class="text-xl font-semibold <?= $shortNoBin > 0 ? 'text-amber-800' : 'text-emerald-700' ?>"><?= e_((string)$shortNoBin) ?></div>
  </div>
</div>

<?php if ($pl['status'] === 'DRAFT'): ?>
  <div class="mb-4 border border-blue-200 bg-blue-50 text-blue-800 text-sm rounded p-3">
    Pick list is generated. Mobile picking (Phase 7) will consume FIFO stock and flip status through IN_PROGRESS → COMPLETED.
  </div>
<?php endif; ?>

<section class="bg-white border border-gray-200 rounded-lg overflow-hidden">
  <header class="px-4 py-3 bg-gray-50 border-b border-gray-200 text-sm">
    <strong class="text-gray-700">Walk path</strong>
    <span class="text-xs text-gray-500 ml-2">— ordered by zone &rarr; rack &rarr; bin so the picker visits each location once.</span>
  </header>
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 text-gray-600">
      <tr>
        <th class="text-left px-4 py-2 font-medium w-12">#</th>
        <th class="text-left px-4 py-2 font-medium">Bin (suggested)</th>
        <th class="text-left px-4 py-2 font-medium">SKU</th>
        <th class="text-left px-4 py-2 font-medium">Barcode</th>
        <th class="text-right px-4 py-2 font-medium">To pick</th>
        <th class="text-right px-4 py-2 font-medium">Picked</th>
        <th class="text-left px-4 py-2 font-medium">From bin (actual)</th>
        <th class="text-left px-4 py-2 font-medium">When / by</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php foreach ($items as $i):
        $isShort = $i['suggested_bin_id'] === null;
        $isDone  = (float)$i['qty_picked'] >= (float)$i['qty_to_pick'] && (float)$i['qty_picked'] > 0;
      ?>
        <tr class="<?= $isDone ? 'bg-emerald-50/40' : ($isShort ? 'bg-amber-50/40' : '') ?>">
          <td class="px-4 py-2 text-gray-400 text-xs"><?= e_((string)$i['walk_order']) ?></td>
          <td class="px-4 py-2">
            <?php if ($isShort): ?>
              <span class="text-xs text-amber-700">(no stock — pick when available)</span>
            <?php else: ?>
              <span class="font-mono text-xs"><?= e_($i['suggested_full']) ?></span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-2">
            <div class="font-mono"><?= e_($i['sku_code']) ?></div>
            <div class="text-xs text-gray-500"><?= e_($i['product_name']) ?> · <?= e_($i['uom']) ?></div>
          </td>
          <td class="px-4 py-2 font-mono text-xs text-gray-500"><?= e_($i['primary_barcode'] ?: '—') ?></td>
          <td class="px-4 py-2 text-right"><?= e_(qty($i['qty_to_pick'])) ?></td>
          <td class="px-4 py-2 text-right font-semibold"><?= e_(qty($i['qty_picked'])) ?></td>
          <td class="px-4 py-2 font-mono text-xs">
            <?= $i['picked_from_full'] ? e_($i['picked_from_full']) : '<span class="text-gray-400">—</span>' ?>
          </td>
          <td class="px-4 py-2 text-xs text-gray-500">
            <?= $i['picked_at'] ? e_($i['picked_at']) : '<span class="text-gray-400">—</span>' ?>
            <?php if ($i['picker_name']): ?><br><span class="opacity-70"><?= e_($i['picker_name']) ?></span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
