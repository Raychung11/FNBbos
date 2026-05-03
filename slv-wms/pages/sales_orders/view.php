<?php
// SLV WMS — pages/sales_orders/view.php
// Purpose: View one SO with its lines + tax breakdown + per-line stock
//          availability. POSTs handled inline: add/remove line (DRAFT
//          only), confirm (DRAFT → CONFIRMED), generate pick list
//          (CONFIRMED → PICKING), cancel (any non-terminal → CANCELLED).
// Roles allowed: super_admin, warehouse_manager, sales (RW), viewer (RO)
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','sales','viewer']);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Missing SO id.');
    redirect('/pages/sales_orders/index.php');
}

$role     = current_user()['role'] ?? '';
$user_id  = (int)(current_user()['id'] ?? 0) ?: null;

$loadSO = function (int $id): array {
    $stmt = db()->prepare(
        'SELECT so.*, w.code AS warehouse_code, w.name AS warehouse_name,
                c.code AS customer_code, c.name AS customer_name,
                c.billing_address, c.shipping_address,
                tg.code AS customer_tg_code,
                u.name AS creator_name
           FROM sales_orders so
           JOIN warehouses w   ON w.id = so.warehouse_id
           JOIN customers  c   ON c.id = so.customer_id
      LEFT JOIN tax_groups tg  ON tg.id = c.default_tax_group_id
      LEFT JOIN users      u   ON u.id = so.created_by
          WHERE so.id = ? AND so.company_id = ?'
    );
    $stmt->execute([$id, company_id()]);
    $so = $stmt->fetch();
    if (!$so) {
        flash('error', 'Sales order not found.');
        redirect('/pages/sales_orders/index.php');
    }
    require_warehouse_access((int)$so['warehouse_id']);
    return $so;
};
$so = $loadSO($id);

$canWrite = in_array($role, ['super_admin','warehouse_manager','sales'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canWrite) {
        flash('error', 'Read-only role.');
        redirect('/pages/sales_orders/view.php?id=' . $id);
    }
    verify_csrf();
    $do = (string)($_POST['_action'] ?? '');

    try {
        switch ($do) {

        case 'cancel':
            if (in_array($so['status'], ['CLOSED','CANCELLED','DELIVERED','INVOICED'], true)) {
                throw new RuntimeException("Cannot cancel an SO in {$so['status']}.");
            }
            db_tx(function () use ($id) {
                db()->prepare('UPDATE sales_orders SET status = "CANCELLED" WHERE id = ?')->execute([$id]);
                db()->prepare("UPDATE pick_lists SET status = 'CANCELLED' WHERE so_id = ? AND status IN ('DRAFT','ASSIGNED','IN_PROGRESS')")->execute([$id]);
            });
            audit_log('so_cancel', 'sales_orders', $id);
            flash('success', 'SO cancelled.');
            break;

        case 'add_line': {
            if ($so['status'] !== 'DRAFT') throw new RuntimeException('Lines can only be added to a DRAFT SO.');
            $pid   = (int)($_POST['product_id'] ?? 0);
            $qty   = (float)($_POST['qty_ordered'] ?? 0);
            $price = (float)($_POST['unit_price']  ?? 0);
            $tg    = ($_POST['tax_group_id'] ?? '') === '' ? null : (int)$_POST['tax_group_id'];
            if ($pid <= 0 || $qty <= 0 || $price < 0) throw new RuntimeException('Invalid line.');
            db_tx(function () use ($id, $pid, $qty, $price, $tg) {
                db()->prepare(
                    'INSERT INTO so_items (so_id, product_id, qty_ordered, unit_price, tax_group_id) VALUES (?, ?, ?, ?, ?)'
                )->execute([$id, $pid, $qty, $price, $tg]);
                so_recompute_totals($id);
            });
            audit_log('so_line_add', 'so_items', (int)db()->lastInsertId(), [
                'so_id'=>$id,'product_id'=>$pid,'qty'=>$qty,'unit_price'=>$price,
            ]);
            flash('success', 'Line added.');
            break;
        }

        case 'remove_line': {
            if ($so['status'] !== 'DRAFT') throw new RuntimeException('Lines can only be removed from a DRAFT SO.');
            $lineId = (int)($_POST['line_id'] ?? 0);
            db_tx(function () use ($lineId, $id) {
                db()->prepare('DELETE FROM so_items WHERE id = ? AND so_id = ?')->execute([$lineId, $id]);
                so_recompute_totals($id);
            });
            audit_log('so_line_remove', 'so_items', $lineId, ['so_id' => $id]);
            flash('success', 'Line removed.');
            break;
        }

        case 'confirm':
            if ($so['status'] !== 'DRAFT') throw new RuntimeException('Only DRAFT SOs can be confirmed.');
            db()->prepare('UPDATE sales_orders SET status = "CONFIRMED" WHERE id = ?')->execute([$id]);
            audit_log('so_confirm', 'sales_orders', $id);
            flash('success', 'SO confirmed. You can now generate a pick list.');
            break;

        case 'generate_pick':
            if ($so['status'] !== 'CONFIRMED') throw new RuntimeException('Generate pick list only from a CONFIRMED SO.');
            $pick_id = so_generate_pick_list($id, null);
            flash('success', 'Pick list generated.');
            redirect('/pages/pick_lists/view.php?id=' . $pick_id);
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/pages/sales_orders/view.php?id=' . $id);
}

// Reload (after potential POST).
$so = $loadSO($id);

// Lines + per-line tax breakdown.
$lstmt = db()->prepare(
    'SELECT si.*, p.sku_code, p.name AS product_name, p.uom, tg.code AS tg_code, tg.name AS tg_name
       FROM so_items si
       JOIN products  p ON p.id = si.product_id
  LEFT JOIN tax_groups tg ON tg.id = si.tax_group_id
      WHERE si.so_id = ?
   ORDER BY si.id'
);
$lstmt->execute([$id]);
$lines = $lstmt->fetchAll();

// Compute per-line breakdown live for display + aggregate totals for the
// header tile. (Stored values remain authoritative — this is just a view.)
$lineComputes = [];
foreach ($lines as $l) {
    $sub = (float)$l['qty_ordered'] * (float)$l['unit_price'];
    $lineComputes[(int)$l['id']] = tax_compute_line($sub, $l['tax_group_id'] === null ? null : (int)$l['tax_group_id']);
}
$agg = tax_aggregate(array_values($lineComputes));

// Stock availability per line.
$avail = [];
foreach (so_stock_availability($id) as $row) {
    $avail[(int)$row['so_item_id']] = $row;
}

// Pick list (if any).
$plStmt = db()->prepare(
    "SELECT id, pick_no, status, created_at, completed_at FROM pick_lists
      WHERE so_id = ? ORDER BY id DESC LIMIT 1"
);
$plStmt->execute([$id]);
$pickList = $plStmt->fetch() ?: null;

// Dropdowns for the inline add-line form.
$products = [];
$taxGroups = [];
if ($so['status'] === 'DRAFT' && $canWrite) {
    $pStmt = db()->prepare("SELECT id, sku_code, name FROM products WHERE company_id = ? AND status='ACTIVE' ORDER BY sku_code");
    $pStmt->execute([company_id()]);
    $products = $pStmt->fetchAll();
    $tgStmt = db()->prepare("SELECT id, code, name FROM tax_groups WHERE company_id = ? AND is_active=1 ORDER BY code");
    $tgStmt->execute([company_id()]);
    $taxGroups = $tgStmt->fetchAll();
}

$statusClasses = [
    'DRAFT'      => 'bg-gray-100  text-gray-700',
    'CONFIRMED'  => 'bg-blue-50   text-blue-700',
    'PICKING'    => 'bg-amber-50  text-amber-800',
    'PICKED'     => 'bg-cyan-50   text-cyan-700',
    'INVOICED'   => 'bg-violet-50 text-violet-700',
    'DELIVERED'  => 'bg-green-50  text-green-700',
    'CANCELLED'  => 'bg-red-50    text-red-700',
];
$statusClass = $statusClasses[$so['status']] ?? 'bg-gray-100 text-gray-500';

$shortageCount = 0;
foreach ($avail as $a) if ($a['shortfall'] > 0) $shortageCount++;

$PAGE_TITLE = 'SO ' . $so['so_no'];
require __DIR__ . '/../../partials/header.php';
?>
<div class="mb-6 flex items-start justify-between gap-3">
  <div>
    <a href="/pages/sales_orders/index.php" class="text-sm text-gray-500 hover:text-gray-900">&larr; Sales orders</a>
    <h1 class="text-2xl font-semibold text-gray-900 mt-1">
      <?= e_($so['so_no']) ?>
      <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs <?= $statusClass ?>"><?= e_($so['status']) ?></span>
    </h1>
    <div class="text-sm text-gray-500 mt-1">
      <?= e_($so['warehouse_code']) ?> — <?= e_($so['warehouse_name']) ?>
      · for <span class="font-mono"><?= e_($so['customer_code']) ?></span> <?= e_($so['customer_name']) ?>
      · ordered <?= e_($so['order_date']) ?>
    </div>
  </div>
  <div class="flex items-center gap-2 flex-wrap justify-end">
    <?php if ($canWrite && $so['status'] === 'DRAFT'): ?>
      <form method="post" onsubmit="return confirm('Confirm this SO? After confirming you can generate a pick list.');">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="confirm">
        <input type="hidden" name="id"      value="<?= e_($id) ?>">
        <button class="slv-bg-primary text-white px-3 py-2 rounded text-sm font-medium">Confirm SO →</button>
      </form>
    <?php endif; ?>

    <?php if ($canWrite && $so['status'] === 'CONFIRMED'): ?>
      <form method="post" onsubmit="return confirm('Generate a pick list now?');">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="generate_pick">
        <input type="hidden" name="id"      value="<?= e_($id) ?>">
        <button class="slv-bg-primary text-white px-3 py-2 rounded text-sm font-medium">Generate pick list →</button>
      </form>
    <?php endif; ?>

    <?php if ($pickList): ?>
      <a href="/pages/pick_lists/view.php?id=<?= e_($pickList['id']) ?>"
         class="px-3 py-2 rounded text-sm border border-indigo-300 text-indigo-700 hover:bg-indigo-50">
        Open pick list <?= e_($pickList['pick_no']) ?>
      </a>
    <?php endif; ?>

    <?php if ($canWrite && !in_array($so['status'], ['CLOSED','CANCELLED','DELIVERED','INVOICED'], true)): ?>
      <form method="post" onsubmit="return confirm('Cancel this SO?');">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="cancel">
        <input type="hidden" name="id"      value="<?= e_($id) ?>">
        <button class="px-3 py-2 rounded text-sm border border-red-300 text-red-700 hover:bg-red-50">Cancel SO</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- Header tiles -->
<div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-6">
  <div class="bg-white border border-gray-200 rounded p-3">
    <div class="text-xs text-gray-500">Lines</div>
    <div class="text-xl font-semibold slv-text-primary"><?= e_((string)count($lines)) ?></div>
  </div>
  <div class="bg-white border border-gray-200 rounded p-3">
    <div class="text-xs text-gray-500">Subtotal</div>
    <div class="text-xl font-semibold slv-text-primary"><?= e_(money($agg['subtotal'])) ?></div>
  </div>
  <div class="bg-white border border-gray-200 rounded p-3">
    <div class="text-xs text-gray-500">Tax</div>
    <div class="text-xl font-semibold slv-text-primary"><?= e_(money($agg['tax_total'])) ?></div>
  </div>
  <div class="bg-white border border-gray-200 rounded p-3">
    <div class="text-xs text-gray-500">Grand total</div>
    <div class="text-xl font-semibold slv-text-primary"><?= e_(money($agg['grand_total'])) ?></div>
  </div>
  <div class="bg-white border <?= $shortageCount > 0 ? 'border-amber-300 bg-amber-50' : 'border-gray-200' ?> rounded p-3">
    <div class="text-xs text-gray-500">Stock shortages</div>
    <div class="text-xl font-semibold <?= $shortageCount > 0 ? 'text-amber-800' : 'text-emerald-700' ?>"><?= e_((string)$shortageCount) ?></div>
  </div>
</div>

<?php if ($shortageCount > 0 && $so['status'] === 'DRAFT'): ?>
  <div class="mb-4 border border-amber-200 bg-amber-50 text-amber-900 rounded p-3 text-sm">
    Some lines exceed available stock in <?= e_($so['warehouse_code']) ?>.
    You can still confirm — pick-list generation will allocate FIFO and short-pick lines will appear with a "no suggested bin" tag.
  </div>
<?php endif; ?>

<!-- Tax breakdown card -->
<?php if ($agg['per_code']): ?>
<section class="bg-white border border-gray-200 rounded-lg p-4 mb-4">
  <h3 class="text-sm font-semibold text-gray-700 mb-2">Tax breakdown</h3>
  <table class="text-sm">
    <thead class="text-gray-500">
      <tr>
        <th class="text-left pr-4 py-1">Code</th>
        <th class="text-left pr-4 py-1">Name</th>
        <th class="text-right pr-4 py-1">Rate</th>
        <th class="text-right pr-4 py-1">Taxable</th>
        <th class="text-right py-1">Tax</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($agg['per_code'] as $b): ?>
        <tr class="border-t border-gray-100">
          <td class="pr-4 py-1 font-mono"><?= e_($b['code']) ?></td>
          <td class="pr-4 py-1 text-gray-600"><?= e_($b['name']) ?></td>
          <td class="pr-4 py-1 text-right"><?= e_(number_format($b['rate'] * 100, 2)) ?>%</td>
          <td class="pr-4 py-1 text-right"><?= e_(money($b['taxable_amount'])) ?></td>
          <td class="py-1 text-right font-semibold"><?= e_(money($b['tax_amount'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<!-- Lines table -->
<section class="bg-white border border-gray-200 rounded-lg overflow-hidden">
  <header class="px-4 py-3 bg-gray-50 border-b border-gray-200 text-sm">
    <strong class="text-gray-700">Lines</strong>
    <span class="text-xs text-gray-500 ml-2">— stored amounts. Click Confirm to lock the SO.</span>
  </header>
  <?php if (!$lines): ?>
    <div class="p-6 text-center text-gray-500 text-sm">No lines yet.</div>
  <?php else: ?>
    <table class="min-w-full text-sm">
      <thead class="bg-gray-50 text-gray-600">
        <tr>
          <th class="text-left  px-4 py-2 font-medium">SKU</th>
          <th class="text-right px-4 py-2 font-medium">Qty</th>
          <th class="text-right px-4 py-2 font-medium">Unit price</th>
          <th class="text-left  px-4 py-2 font-medium">Tax group</th>
          <th class="text-right px-4 py-2 font-medium">Subtotal</th>
          <th class="text-right px-4 py-2 font-medium">Tax</th>
          <th class="text-right px-4 py-2 font-medium">Total</th>
          <th class="text-left  px-4 py-2 font-medium">Stock</th>
          <th class="px-4 py-2"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php foreach ($lines as $l):
          $a = $avail[(int)$l['id']] ?? null;
          $shortfall = $a ? (float)$a['shortfall'] : 0.0;
        ?>
          <tr>
            <td class="px-4 py-2">
              <div><span class="font-mono"><?= e_($l['sku_code']) ?></span></div>
              <div class="text-xs text-gray-500"><?= e_($l['product_name']) ?> · <?= e_($l['uom']) ?></div>
            </td>
            <td class="px-4 py-2 text-right"><?= e_(qty($l['qty_ordered'])) ?></td>
            <td class="px-4 py-2 text-right"><?= e_(money($l['unit_price'])) ?></td>
            <td class="px-4 py-2 font-mono text-xs">
              <?= $l['tg_code'] ? e_($l['tg_code']) : '<span class="text-gray-400">—</span>' ?>
            </td>
            <td class="px-4 py-2 text-right font-mono"><?= e_(money($l['line_subtotal'])) ?></td>
            <td class="px-4 py-2 text-right font-mono"><?= e_(money($l['line_tax'])) ?></td>
            <td class="px-4 py-2 text-right font-mono font-semibold"><?= e_(money($l['line_total'])) ?></td>
            <td class="px-4 py-2 text-xs">
              <?php if ($a): ?>
                <?php if ($shortfall > 0): ?>
                  <span class="text-amber-700">short by <strong><?= e_(qty($shortfall)) ?></strong></span>
                  <div class="text-gray-400">avail <?= e_(qty($a['qty_available'])) ?></div>
                <?php else: ?>
                  <span class="text-emerald-700">ok</span>
                  <div class="text-gray-400">avail <?= e_(qty($a['qty_available'])) ?></div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="px-4 py-2 text-sm text-right">
              <?php if ($canWrite && $so['status'] === 'DRAFT'): ?>
                <form method="post" onsubmit="return confirm('Remove this line?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="_action" value="remove_line">
                  <input type="hidden" name="id"      value="<?= e_($id) ?>">
                  <input type="hidden" name="line_id" value="<?= e_($l['id']) ?>">
                  <button class="text-red-600 text-xs hover:underline">remove</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<?php if ($canWrite && $so['status'] === 'DRAFT'): ?>
  <section class="bg-white border border-gray-200 rounded-lg p-5 mt-4 max-w-3xl">
    <h2 class="text-base font-semibold text-gray-900 mb-3">Add a line</h2>
    <form method="post" class="grid grid-cols-1 sm:grid-cols-5 gap-3 items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="_action" value="add_line">
      <input type="hidden" name="id"      value="<?= e_($id) ?>">
      <div class="sm:col-span-2">
        <label class="block text-xs text-gray-500">SKU</label>
        <select name="product_id" required class="mt-1 w-full rounded border-gray-300 shadow-sm text-sm font-mono">
          <option value="">— pick —</option>
          <?php foreach ($products as $p): ?>
            <option value="<?= e_($p['id']) ?>"><?= e_($p['sku_code']) ?> — <?= e_($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs text-gray-500">Qty</label>
        <input type="number" step="0.0001" min="0" name="qty_ordered" required class="mt-1 w-full rounded border-gray-300 text-sm">
      </div>
      <div>
        <label class="block text-xs text-gray-500">Unit price</label>
        <input type="number" step="0.0001" min="0" name="unit_price" required class="mt-1 w-full rounded border-gray-300 text-sm">
      </div>
      <div>
        <label class="block text-xs text-gray-500">Tax group</label>
        <select name="tax_group_id" class="mt-1 w-full rounded border-gray-300 text-xs">
          <option value="">(none)</option>
          <?php foreach ($taxGroups as $tg): ?>
            <option value="<?= e_($tg['id']) ?>"><?= e_($tg['code']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="sm:col-span-5 text-right">
        <button class="slv-bg-primary text-white px-3 py-2 rounded text-sm">+ Add line</button>
      </div>
    </form>
  </section>
<?php endif; ?>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
