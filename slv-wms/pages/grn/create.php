<?php
// SLV WMS — pages/grn/create.php
// Purpose: Create a new GRN with header + line items. Saves as DRAFT;
//          actual receiving and putaway happen on view.php.
// Roles allowed: super_admin, warehouse_manager, receiver
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','receiver']);

$role          = current_user()['role'] ?? '';
$accessibleIds = user_warehouse_ids();

// Warehouse list this user can target.
$whWhere  = ['company_id = ?', "status='ACTIVE'"]; $whParams = [company_id()];
if ($role !== 'super_admin' && $accessibleIds) {
    $whWhere[]  = 'id IN (' . implode(',', array_fill(0, count($accessibleIds), '?')) . ')';
    $whParams   = array_merge($whParams, $accessibleIds);
} elseif ($role !== 'super_admin') {
    $whWhere[] = '1 = 0';
}
$whStmt = db()->prepare('SELECT id, code, name FROM warehouses WHERE ' . implode(' AND ', $whWhere) . ' ORDER BY code');
$whStmt->execute($whParams);
$warehouses = $whStmt->fetchAll();

$sStmt = db()->prepare("SELECT id, code, name FROM suppliers WHERE company_id = ? AND status='ACTIVE' ORDER BY code");
$sStmt->execute([company_id()]);
$suppliers = $sStmt->fetchAll();

$pStmt = db()->prepare(
    "SELECT id, sku_code, name, uom FROM products
      WHERE company_id = ? AND status='ACTIVE' ORDER BY sku_code"
);
$pStmt->execute([company_id()]);
$products = $pStmt->fetchAll();

$errors = [];
$values = [
    'warehouse_id' => $accessibleIds[0] ?? ($warehouses[0]['id'] ?? ''),
    'supplier_id'  => '',
    'ref_po'       => '',
    'notes'        => '',
];
$lines = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $values = [
        'warehouse_id' => (int)($_POST['warehouse_id'] ?? 0),
        'supplier_id'  => ($_POST['supplier_id'] ?? '') === '' ? null : (int)$_POST['supplier_id'],
        'ref_po'       => trim((string)($_POST['ref_po'] ?? '')),
        'notes'        => trim((string)($_POST['notes']  ?? '')),
    ];
    $lines = [];
    foreach (($_POST['lines'] ?? []) as $line) {
        $pid = (int)($line['product_id'] ?? 0);
        if ($pid === 0) continue;
        $lines[] = [
            'product_id'   => $pid,
            'qty_expected' => (string)($line['qty_expected'] ?? '0'),
            'unit_cost'    => (string)($line['unit_cost']    ?? '0'),
            'notes'        => trim((string)($line['notes']   ?? '')),
        ];
    }

    // Validate.
    if ($values['warehouse_id'] <= 0)                                    $errors[] = 'Pick a warehouse.';
    if ($values['warehouse_id'] > 0) {
        try { require_warehouse_access((int)$values['warehouse_id']); }
        catch (Throwable $e) { $errors[] = 'No access to that warehouse.'; }
    }
    if (mb_strlen($values['ref_po']) > 64)                                $errors[] = 'PO reference too long (max 64).';
    if (!$lines)                                                          $errors[] = 'Add at least one line.';
    foreach ($lines as $i => $l) {
        $idx = $i + 1;
        if (!is_numeric($l['qty_expected']) || (float)$l['qty_expected'] <= 0)
                                                                          $errors[] = "Line $idx: qty must be > 0.";
        if (!is_numeric($l['unit_cost']) || (float)$l['unit_cost'] < 0)
                                                                          $errors[] = "Line $idx: unit cost must be >= 0.";
    }

    if (!$errors) {
        try {
            $newId = db_tx(function () use ($values, $lines) {
                $grnNo = next_doc_no('GRN');
                $stmt = db()->prepare(
                    'INSERT INTO grn
                       (company_id, warehouse_id, grn_no, supplier_id, ref_po, status,
                        notes, created_by)
                     VALUES (?, ?, ?, ?, ?, "DRAFT", ?, ?)'
                );
                $stmt->execute([
                    company_id(),
                    $values['warehouse_id'],
                    $grnNo,
                    $values['supplier_id'],
                    $values['ref_po'] ?: null,
                    $values['notes']  ?: null,
                    (int)(current_user()['id'] ?? 0) ?: null,
                ]);
                $grn_id = (int)db()->lastInsertId();

                $insLine = db()->prepare(
                    'INSERT INTO grn_items
                       (grn_id, product_id, qty_expected, unit_cost, notes)
                     VALUES (?, ?, ?, ?, ?)'
                );
                foreach ($lines as $l) {
                    $insLine->execute([
                        $grn_id, $l['product_id'],
                        (float)$l['qty_expected'], (float)$l['unit_cost'],
                        $l['notes'] ?: null,
                    ]);
                }
                grn_recompute_status($grn_id);
                audit_log('grn_create', 'grn', $grn_id, [
                    'grn_no' => $grnNo, 'warehouse_id' => $values['warehouse_id'],
                    'supplier_id' => $values['supplier_id'], 'lines' => count($lines),
                ]);
                return $grn_id;
            });
            flash('success', 'GRN created in DRAFT.');
            redirect('/pages/grn/view.php?id=' . $newId);
        } catch (Throwable $e) {
            $errors[] = 'Could not save: ' . $e->getMessage();
        }
    }
}

$PAGE_TITLE = 'New GRN';
require __DIR__ . '/../../partials/header.php';
?>
<div class="mb-6">
  <a href="/pages/grn/index.php" class="text-sm text-gray-500 hover:text-gray-900">&larr; GRNs</a>
  <h1 class="text-2xl font-semibold text-gray-900 mt-1">New goods receipt</h1>
  <p class="text-sm text-gray-500 mt-1">
    Record what's expected. After saving you can mark items as received
    and putaway from the GRN page.
  </p>
</div>

<?php if ($errors): ?>
  <div class="mb-4 border border-red-200 bg-red-50 text-red-800 rounded p-3 text-sm max-w-3xl">
    <ul class="list-disc list-inside">
      <?php foreach ($errors as $err): ?><li><?= e_($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" class="space-y-6 max-w-4xl"
      x-data='{
        lines: <?= json_encode($lines ?: [["product_id"=>"","qty_expected"=>"","unit_cost"=>"","notes"=>""]], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>,
        addLine(){ this.lines.push({product_id:"",qty_expected:"",unit_cost:"",notes:""}); },
        rmLine(i){ this.lines.splice(i,1); if(!this.lines.length) this.addLine(); },
        get total() { return this.lines.reduce((s,l)=> s + (parseFloat(l.qty_expected)||0)*(parseFloat(l.unit_cost)||0), 0); }
      }'>
  <?= csrf_field() ?>

  <section class="bg-white border border-gray-200 rounded-lg p-5">
    <h2 class="text-base font-semibold text-gray-900 mb-4">Header</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700">Warehouse</label>
        <select name="warehouse_id" required class="mt-1 w-full rounded border-gray-300 shadow-sm">
          <?php if (!$warehouses): ?>
            <option value="">(no warehouse access)</option>
          <?php endif; ?>
          <?php foreach ($warehouses as $w): ?>
            <option value="<?= e_($w['id']) ?>" <?= (int)$values['warehouse_id'] === (int)$w['id'] ? 'selected' : '' ?>>
              <?= e_($w['code']) ?> — <?= e_($w['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Supplier</label>
        <select name="supplier_id" class="mt-1 w-full rounded border-gray-300 shadow-sm">
          <option value="">(none)</option>
          <?php foreach ($suppliers as $s): ?>
            <option value="<?= e_($s['id']) ?>" <?= (int)$values['supplier_id'] === (int)$s['id'] ? 'selected' : '' ?>>
              <?= e_($s['code']) ?> — <?= e_($s['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">PO reference (optional)</label>
        <input type="text" name="ref_po" value="<?= e_($values['ref_po']) ?>" maxlength="64"
               class="mt-1 w-full rounded border-gray-300 shadow-sm font-mono text-sm">
      </div>
      <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Notes</label>
        <textarea name="notes" rows="2" class="mt-1 w-full rounded border-gray-300 shadow-sm"><?= e_($values['notes']) ?></textarea>
      </div>
    </div>
  </section>

  <section class="bg-white border border-gray-200 rounded-lg p-5">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-semibold text-gray-900">Line items</h2>
      <button type="button" @click="addLine()" class="text-indigo-700 text-sm hover:underline">+ Add line</button>
    </div>
    <table class="min-w-full text-sm">
      <thead class="text-gray-500">
        <tr>
          <th class="text-left py-1 pr-2">SKU</th>
          <th class="text-right py-1 px-2 w-32">Expected qty</th>
          <th class="text-right py-1 px-2 w-32">Unit cost</th>
          <th class="text-left py-1 px-2">Notes</th>
          <th class="py-1"></th>
        </tr>
      </thead>
      <tbody>
        <template x-for="(l, i) in lines" :key="i">
          <tr class="border-t border-gray-100">
            <td class="py-1 pr-2">
              <select :name="`lines[${i}][product_id]`" x-model="l.product_id" required
                      class="w-full rounded border-gray-300 shadow-sm font-mono text-xs">
                <option value="">— pick a SKU —</option>
                <?php foreach ($products as $p): ?>
                  <option value="<?= e_($p['id']) ?>"><?= e_($p['sku_code']) ?> — <?= e_($p['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="py-1 px-2">
              <input type="number" step="0.0001" min="0" :name="`lines[${i}][qty_expected]`" x-model="l.qty_expected"
                     class="w-full rounded border-gray-300 shadow-sm text-right text-sm">
            </td>
            <td class="py-1 px-2">
              <input type="number" step="0.0001" min="0" :name="`lines[${i}][unit_cost]`" x-model="l.unit_cost"
                     class="w-full rounded border-gray-300 shadow-sm text-right text-sm">
            </td>
            <td class="py-1 px-2">
              <input type="text" maxlength="255" :name="`lines[${i}][notes]`" x-model="l.notes"
                     class="w-full rounded border-gray-300 shadow-sm text-sm">
            </td>
            <td class="py-1 pl-2 text-right">
              <button type="button" @click="rmLine(i)" class="text-red-600 text-sm hover:underline">remove</button>
            </td>
          </tr>
        </template>
      </tbody>
      <tfoot>
        <tr class="border-t-2 border-gray-200">
          <td colspan="2" class="py-2 text-right text-sm text-gray-500">Estimated total value:</td>
          <td colspan="2" class="py-2 px-2 font-semibold slv-text-primary text-right text-base">
            RM <span x-text="total.toLocaleString('en-MY', {minimumFractionDigits:2, maximumFractionDigits:2})"></span>
          </td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </section>

  <div class="flex justify-end gap-3 max-w-4xl">
    <a href="/pages/grn/index.php" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    <button type="submit" class="slv-bg-primary text-white px-4 py-2 rounded text-sm font-medium">Save GRN as draft</button>
  </div>
</form>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
