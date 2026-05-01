<?php
// SLV WMS — pages/grn/view.php
// Purpose: View one GRN. Renders an edit / receive / putaway form
//          depending on status. All POSTs target this file via _action.
// Roles allowed: super_admin, warehouse_manager, receiver
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','receiver']);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Missing GRN id.');
    redirect('/pages/grn/index.php');
}

$user_id = (int)(current_user()['id'] ?? 0) ?: null;

// Load GRN + warehouse-access guard.
$loadGrn = function (int $id): array {
    $stmt = db()->prepare(
        'SELECT g.*, w.code AS warehouse_code, w.name AS warehouse_name,
                s.code AS supplier_code, s.name AS supplier_name,
                u.name AS creator_name
           FROM grn g
           JOIN warehouses w ON w.id = g.warehouse_id
      LEFT JOIN suppliers  s ON s.id = g.supplier_id
      LEFT JOIN users      u ON u.id = g.created_by
          WHERE g.id = ? AND g.company_id = ?'
    );
    $stmt->execute([$id, company_id()]);
    $grn = $stmt->fetch();
    if (!$grn) {
        flash('error', 'GRN not found.');
        redirect('/pages/grn/index.php');
    }
    require_warehouse_access((int)$grn['warehouse_id']);
    return $grn;
};
$grn = $loadGrn($id);

// ---------------------------------------------------------------------------
// POST dispatcher.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = (string)($_POST['_action'] ?? '');

    try {
        switch ($do) {

        case 'cancel':
            if (in_array($grn['status'], ['CLOSED', 'CANCELLED'], true)) {
                throw new RuntimeException('GRN already in terminal state.');
            }
            // Don't allow cancel once anything has been put away — require an
            // explicit adjustment to pull stock back out. Phase 10.
            if ((float)$grn['total_value'] > 0 && $grn['status'] === 'PUTAWAY') {
                throw new RuntimeException('Cannot cancel a GRN with putaway lines. Use a stock adjustment.');
            }
            db()->prepare('UPDATE grn SET status="CANCELLED" WHERE id = ?')->execute([$id]);
            audit_log('grn_cancel', 'grn', $id);
            flash('success', 'GRN cancelled.');
            break;

        case 'add_line': {
            if ($grn['status'] !== 'DRAFT') throw new RuntimeException('Lines can only be added to a DRAFT GRN.');
            $pid = (int)($_POST['product_id'] ?? 0);
            $qty = (float)($_POST['qty_expected'] ?? 0);
            $cost = (float)($_POST['unit_cost'] ?? 0);
            if ($pid <= 0 || $qty <= 0 || $cost < 0) throw new RuntimeException('Invalid line.');
            db()->prepare(
                'INSERT INTO grn_items (grn_id, product_id, qty_expected, unit_cost) VALUES (?, ?, ?, ?)'
            )->execute([$id, $pid, $qty, $cost]);
            audit_log('grn_line_add', 'grn_items', (int)db()->lastInsertId(), [
                'grn_id'=>$id,'product_id'=>$pid,'qty'=>$qty,'unit_cost'=>$cost,
            ]);
            grn_recompute_status($id);
            flash('success', 'Line added.');
            break;
        }

        case 'remove_line': {
            if ($grn['status'] !== 'DRAFT') throw new RuntimeException('Lines can only be removed from a DRAFT GRN.');
            $lineId = (int)($_POST['line_id'] ?? 0);
            $del = db()->prepare('DELETE FROM grn_items WHERE id = ? AND grn_id = ?');
            $del->execute([$lineId, $id]);
            audit_log('grn_line_remove', 'grn_items', $lineId, ['grn_id'=>$id]);
            grn_recompute_status($id);
            flash('success', 'Line removed.');
            break;
        }

        case 'start_receiving': {
            if ($grn['status'] !== 'DRAFT') throw new RuntimeException('Only DRAFT GRNs can move to RECEIVING.');
            // Check there's at least one line.
            $cnt = db()->prepare('SELECT COUNT(*) FROM grn_items WHERE grn_id = ?');
            $cnt->execute([$id]);
            if ((int)$cnt->fetchColumn() === 0) throw new RuntimeException('Add at least one line first.');
            db()->prepare('UPDATE grn SET status="RECEIVING" WHERE id = ?')->execute([$id]);
            audit_log('grn_start_receiving', 'grn', $id);
            flash('success', 'GRN moved to RECEIVING.');
            break;
        }

        case 'receive': {
            if (!in_array($grn['status'], ['RECEIVING','RECEIVED'], true)) {
                throw new RuntimeException('Receive only allowed in RECEIVING/RECEIVED.');
            }
            $lineId = (int)($_POST['line_id'] ?? 0);
            $qty    = (float)($_POST['qty_received'] ?? 0);
            $cost   = (float)($_POST['unit_cost']    ?? 0);
            if ($lineId <= 0 || $qty < 0 || $cost < 0) throw new RuntimeException('Invalid receive input.');
            // Cannot receive less than what's already been put away.
            $row = db()->prepare('SELECT qty_putaway FROM grn_items WHERE id = ? AND grn_id = ?');
            $row->execute([$lineId, $id]);
            $put = (float)$row->fetchColumn();
            if ($qty + 1e-9 < $put) {
                throw new RuntimeException("qty_received ($qty) cannot be less than qty already putaway ($put).");
            }
            db()->prepare(
                'UPDATE grn_items SET qty_received = ?, unit_cost = ? WHERE id = ? AND grn_id = ?'
            )->execute([$qty, $cost, $lineId, $id]);
            audit_log('grn_receive_line', 'grn_items', $lineId, ['qty'=>$qty,'unit_cost'=>$cost]);
            grn_recompute_status($id);

            // Stamp received_at / received_by once any line is received.
            db()->prepare(
                'UPDATE grn SET received_at = COALESCE(received_at, NOW()),
                                received_by = COALESCE(received_by, ?)
                  WHERE id = ?'
            )->execute([$user_id, $id]);
            flash('success', 'Line updated.');
            break;
        }

        case 'putaway': {
            if (!in_array($grn['status'], ['RECEIVED','PUTAWAY'], true)) {
                throw new RuntimeException('Putaway only allowed once a line is received.');
            }
            $lineId = (int)($_POST['line_id'] ?? 0);
            $bin    = (int)($_POST['bin_id']  ?? 0);
            $qty    = (float)($_POST['qty']   ?? 0);
            $cost   = trim((string)($_POST['unit_cost'] ?? ''));
            $costOverride = $cost === '' ? null : (float)$cost;
            if ($lineId <= 0 || $bin <= 0 || $qty <= 0) throw new RuntimeException('Invalid putaway input.');
            grn_putaway_execute($lineId, $bin, $qty, $costOverride, null, $user_id);
            flash('success', "Putaway of {$qty} units recorded.");
            break;
        }

        default:
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/pages/grn/view.php?id=' . $id);
}

// Reload after potential POST.
$grn = $loadGrn($id);

// Lines + per-line putaway log.
$lstmt = db()->prepare(
    "SELECT gi.*, p.sku_code, p.name AS product_name, p.uom
       FROM grn_items gi
       JOIN products p ON p.id = gi.product_id
      WHERE gi.grn_id = ?
   ORDER BY gi.id"
);
$lstmt->execute([$id]);
$lines = $lstmt->fetchAll();

$putawayLog = [];
if ($lines) {
    $ids = array_map(fn($l) => (int)$l['id'], $lines);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $pls = db()->prepare(
        "SELECT gp.*, b.full_code AS bin_code, u.name AS user_name
           FROM grn_putaway gp
           JOIN bins b  ON b.id = gp.bin_id
      LEFT JOIN users u ON u.id = gp.user_id
          WHERE gp.grn_item_id IN ($in)
       ORDER BY gp.id"
    );
    $pls->execute($ids);
    foreach ($pls->fetchAll() as $g) {
        $putawayLog[(int)$g['grn_item_id']][] = $g;
    }
}

// Products + bins for the line/putaway forms.
$pStmt = db()->prepare(
    "SELECT id, sku_code, name FROM products
      WHERE company_id = ? AND status='ACTIVE' ORDER BY sku_code"
);
$pStmt->execute([company_id()]);
$products = $pStmt->fetchAll();

$bStmt = db()->prepare(
    "SELECT b.id, b.full_code, b.code, b.pickable, b.status, z.code AS zone_code
       FROM bins b
       JOIN racks r ON r.id = b.rack_id
       JOIN zones z ON z.id = r.zone_id
      WHERE z.warehouse_id = ? AND b.status = 'ACTIVE'
   ORDER BY z.code, b.full_code"
);
$bStmt->execute([(int)$grn['warehouse_id']]);
$allBins = $bStmt->fetchAll();

$statusClasses = [
    'DRAFT'     => 'bg-gray-100   text-gray-700',
    'RECEIVING' => 'bg-blue-50    text-blue-700',
    'RECEIVED'  => 'bg-cyan-50    text-cyan-700',
    'PUTAWAY'   => 'bg-amber-50   text-amber-800',
    'CLOSED'    => 'bg-green-50   text-green-700',
    'CANCELLED' => 'bg-red-50     text-red-700',
];
$statusClass = $statusClasses[$grn['status']] ?? 'bg-gray-100 text-gray-500';

// Sums for the header tile row.
$sumExp = $sumRcv = $sumPut = 0.0;
foreach ($lines as $l) {
    $sumExp += (float)$l['qty_expected'];
    $sumRcv += (float)$l['qty_received'];
    $sumPut += (float)$l['qty_putaway'];
}

$canEdit     = $grn['status'] === 'DRAFT';
$canReceive  = in_array($grn['status'], ['RECEIVING', 'RECEIVED'], true);
$canPutaway  = in_array($grn['status'], ['RECEIVED',  'PUTAWAY'],  true);
$canCancel   = !in_array($grn['status'], ['CLOSED', 'CANCELLED'], true);

$PAGE_TITLE = 'GRN ' . $grn['grn_no'];
require __DIR__ . '/../../partials/header.php';
?>
<div class="mb-6 flex items-start justify-between gap-3">
  <div>
    <a href="/pages/grn/index.php" class="text-sm text-gray-500 hover:text-gray-900">&larr; GRNs</a>
    <h1 class="text-2xl font-semibold text-gray-900 mt-1">
      <?= e_($grn['grn_no']) ?>
      <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs <?= $statusClass ?>"><?= e_($grn['status']) ?></span>
    </h1>
    <div class="text-sm text-gray-500 mt-1">
      <?= e_($grn['warehouse_code']) ?> — <?= e_($grn['warehouse_name']) ?>
      <?php if ($grn['supplier_name']): ?>
        · supplier <span class="font-mono"><?= e_($grn['supplier_code']) ?></span> <?= e_($grn['supplier_name']) ?>
      <?php endif; ?>
      <?php if ($grn['ref_po']): ?> · PO <?= e_($grn['ref_po']) ?><?php endif; ?>
    </div>
  </div>
  <div class="flex items-center gap-2">
    <?php if ($grn['status'] === 'DRAFT'): ?>
      <form method="post" onsubmit="return confirm('Confirm GRN and start receiving?');">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="start_receiving">
        <input type="hidden" name="id" value="<?= e_($id) ?>">
        <button class="slv-bg-primary text-white px-3 py-2 rounded text-sm font-medium">Confirm &amp; start receiving →</button>
      </form>
    <?php endif; ?>

    <?php if ($canCancel): ?>
      <form method="post" onsubmit="return confirm('Cancel this GRN? This is logged.');">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="cancel">
        <input type="hidden" name="id" value="<?= e_($id) ?>">
        <button class="px-3 py-2 rounded text-sm border border-red-300 text-red-700 hover:bg-red-50">Cancel GRN</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- Header tiles -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
  <div class="bg-white border border-gray-200 rounded p-3">
    <div class="text-xs text-gray-500">Lines</div>
    <div class="text-xl font-semibold slv-text-primary"><?= e_((string)count($lines)) ?></div>
  </div>
  <div class="bg-white border border-gray-200 rounded p-3">
    <div class="text-xs text-gray-500">Expected qty</div>
    <div class="text-xl font-semibold slv-text-primary"><?= e_(qty($sumExp)) ?></div>
  </div>
  <div class="bg-white border border-gray-200 rounded p-3">
    <div class="text-xs text-gray-500">Received qty</div>
    <div class="text-xl font-semibold <?= $sumRcv >= $sumExp && $sumExp > 0 ? 'text-emerald-700' : 'slv-text-primary' ?>"><?= e_(qty($sumRcv)) ?></div>
  </div>
  <div class="bg-white border border-gray-200 rounded p-3">
    <div class="text-xs text-gray-500">Putaway qty</div>
    <div class="text-xl font-semibold <?= $sumPut >= $sumRcv && $sumRcv > 0 ? 'text-emerald-700' : 'slv-text-primary' ?>"><?= e_(qty($sumPut)) ?></div>
  </div>
</div>

<?php if ($grn['notes']): ?>
  <div class="mb-4 bg-amber-50 border border-amber-200 rounded p-3 text-sm text-amber-900">
    <strong>Notes:</strong> <?= e_($grn['notes']) ?>
  </div>
<?php endif; ?>

<!-- Lines table — same shell, content adapts by status -->
<section class="bg-white border border-gray-200 rounded-lg overflow-hidden">
  <header class="px-4 py-3 bg-gray-50 border-b border-gray-200 text-sm">
    <strong class="text-gray-700">Lines</strong>
    <?php if ($canEdit): ?>
      <span class="text-xs text-gray-500 ml-2">— edit lines below, then confirm to start receiving.</span>
    <?php elseif ($canReceive && !$canPutaway): ?>
      <span class="text-xs text-gray-500 ml-2">— enter received qty + actual unit cost per line.</span>
    <?php elseif ($canPutaway): ?>
      <span class="text-xs text-gray-500 ml-2">— putaway received qty into the suggested or chosen bin.</span>
    <?php endif; ?>
  </header>

  <?php if (!$lines): ?>
    <div class="p-6 text-center text-gray-500 text-sm">No lines yet.</div>
  <?php else: ?>
    <table class="min-w-full text-sm">
      <thead class="bg-gray-50 text-gray-600">
        <tr>
          <th class="text-left px-4 py-2 font-medium">SKU</th>
          <th class="text-right px-4 py-2 font-medium">Expected</th>
          <th class="text-right px-4 py-2 font-medium">Received</th>
          <th class="text-right px-4 py-2 font-medium">Unit cost</th>
          <th class="text-right px-4 py-2 font-medium">Putaway</th>
          <th class="text-left px-4 py-2 font-medium">Action</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php foreach ($lines as $l):
          $remainingPutaway = (float)$l['qty_received'] - (float)$l['qty_putaway'];
          $suggested = $canPutaway && $remainingPutaway > 0
              ? grn_suggest_bin((int)$l['product_id'], (int)$grn['warehouse_id'], $remainingPutaway)
              : null;
        ?>
          <tr>
            <td class="px-4 py-2">
              <div><span class="font-mono"><?= e_($l['sku_code']) ?></span></div>
              <div class="text-xs text-gray-500"><?= e_($l['product_name']) ?> · <?= e_($l['uom']) ?></div>
              <?php if ($l['notes']): ?>
                <div class="text-xs text-amber-700 mt-1"><?= e_($l['notes']) ?></div>
              <?php endif; ?>
            </td>
            <td class="px-4 py-2 text-right"><?= e_(qty($l['qty_expected'])) ?></td>
            <td class="px-4 py-2 text-right">
              <?php if ($canReceive): ?>
                <form method="post" class="flex items-center justify-end gap-2">
                  <?= csrf_field() ?>
                  <input type="hidden" name="_action" value="receive">
                  <input type="hidden" name="id" value="<?= e_($id) ?>">
                  <input type="hidden" name="line_id" value="<?= e_($l['id']) ?>">
                  <input type="number" step="0.0001" min="0" name="qty_received"
                         value="<?= e_((string)$l['qty_received']) ?>"
                         class="w-24 rounded border-gray-300 shadow-sm text-right text-sm">
              <?php else: ?>
                <?= e_(qty($l['qty_received'])) ?>
              <?php endif; ?>
            </td>
            <td class="px-4 py-2 text-right">
              <?php if ($canReceive): ?>
                  <input type="number" step="0.0001" min="0" name="unit_cost"
                         value="<?= e_((string)$l['unit_cost']) ?>"
                         class="w-24 rounded border-gray-300 shadow-sm text-right text-sm">
                  <button class="ml-1 text-xs text-indigo-700 hover:underline">save</button>
                </form>
              <?php else: ?>
                <?= e_(money($l['unit_cost'])) ?>
              <?php endif; ?>
            </td>
            <td class="px-4 py-2 text-right">
              <?= e_(qty($l['qty_putaway'])) ?>
              <?php if ($remainingPutaway > 0 && (float)$l['qty_received'] > 0): ?>
                <span class="text-xs text-amber-700">(<?= e_(qty($remainingPutaway)) ?> left)</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-2 text-sm">
              <?php if ($canEdit): ?>
                <form method="post" onsubmit="return confirm('Remove this line?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="_action" value="remove_line">
                  <input type="hidden" name="id" value="<?= e_($id) ?>">
                  <input type="hidden" name="line_id" value="<?= e_($l['id']) ?>">
                  <button class="text-red-600 text-xs hover:underline">remove</button>
                </form>
              <?php elseif ($canPutaway && $remainingPutaway > 0): ?>
                <form method="post" class="flex items-end gap-1 flex-wrap">
                  <?= csrf_field() ?>
                  <input type="hidden" name="_action" value="putaway">
                  <input type="hidden" name="id" value="<?= e_($id) ?>">
                  <input type="hidden" name="line_id" value="<?= e_($l['id']) ?>">
                  <select name="bin_id" required class="rounded border-gray-300 text-xs">
                    <?php foreach ($allBins as $b):
                      $sel = $suggested && (int)$suggested['bin_id'] === (int)$b['id'] ? 'selected' : '';
                    ?>
                      <option value="<?= e_($b['id']) ?>" <?= $sel ?>><?= e_($b['full_code']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input type="number" step="0.0001" min="0" name="qty"
                         value="<?= e_(rtrim(rtrim(number_format($remainingPutaway, 4, '.', ''),'0'),'.')) ?>"
                         class="w-20 rounded border-gray-300 text-right text-xs">
                  <input type="number" step="0.0001" min="0" name="unit_cost" placeholder="cost"
                         value="<?= e_((string)$l['unit_cost']) ?>"
                         class="w-20 rounded border-gray-300 text-right text-xs">
                  <button class="slv-bg-primary text-white px-2 py-1 rounded text-xs font-medium">putaway</button>
                </form>
                <?php if ($suggested): ?>
                  <div class="text-[11px] text-gray-500 mt-1">
                    Suggested: <span class="font-mono"><?= e_($suggested['full_code']) ?></span>
                    <span class="text-amber-700">(<?= e_($suggested['reason']) ?>)</span>
                  </div>
                <?php endif; ?>
              <?php endif; ?>

              <?php if (!empty($putawayLog[(int)$l['id']])): ?>
                <details class="mt-1">
                  <summary class="cursor-pointer text-xs text-gray-500">history (<?= count($putawayLog[(int)$l['id']]) ?>)</summary>
                  <ul class="mt-1 text-xs text-gray-600 space-y-0.5">
                    <?php foreach ($putawayLog[(int)$l['id']] as $g): ?>
                      <li>
                        <?= e_(qty($g['qty'])) ?> → <span class="font-mono"><?= e_($g['bin_code']) ?></span>
                        <span class="text-gray-400">@ <?= e_(money($g['unit_cost'])) ?> · <?= e_($g['created_at']) ?> by <?= e_($g['user_name'] ?? '—') ?></span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </details>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<?php if ($canEdit): ?>
  <section class="bg-white border border-gray-200 rounded-lg p-5 mt-4 max-w-2xl">
    <h2 class="text-base font-semibold text-gray-900 mb-3">Add a line</h2>
    <form method="post" class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="_action" value="add_line">
      <input type="hidden" name="id" value="<?= e_($id) ?>">
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
        <label class="block text-xs text-gray-500">Expected qty</label>
        <input type="number" step="0.0001" min="0" name="qty_expected" required class="mt-1 w-full rounded border-gray-300 shadow-sm text-sm">
      </div>
      <div>
        <label class="block text-xs text-gray-500">Unit cost</label>
        <input type="number" step="0.0001" min="0" name="unit_cost" required class="mt-1 w-full rounded border-gray-300 shadow-sm text-sm">
      </div>
      <div class="sm:col-span-4 text-right">
        <button class="slv-bg-primary text-white px-3 py-2 rounded text-sm">+ Add line</button>
      </div>
    </form>
  </section>
<?php endif; ?>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
