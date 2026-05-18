<?php
// SLV WMS — pages/sales_orders/create.php
// Purpose: Create a new sales order with header + line items. Saves as
//          DRAFT; confirmation + pick-list generation happen on view.php.
// Roles allowed: super_admin, warehouse_manager, sales
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','sales']);

$role          = current_user()['role'] ?? '';
$accessibleIds = user_warehouse_ids();

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

$cStmt = db()->prepare("SELECT id, code, name, default_tax_group_id FROM customers WHERE company_id = ? AND status='ACTIVE' ORDER BY code");
$cStmt->execute([company_id()]);
$customers = $cStmt->fetchAll();

$pStmt = db()->prepare(
    "SELECT id, sku_code, name, uom, selling_price, default_tax_group_id
       FROM products WHERE company_id = ? AND status='ACTIVE' ORDER BY sku_code"
);
$pStmt->execute([company_id()]);
$products = $pStmt->fetchAll();

$tgStmt = db()->prepare("SELECT id, code, name FROM tax_groups WHERE company_id = ? AND is_active = 1 ORDER BY code");
$tgStmt->execute([company_id()]);
$taxGroups = $tgStmt->fetchAll();

$errors = [];
$values = [
    'warehouse_id'   => $accessibleIds[0] ?? ($warehouses[0]['id'] ?? ''),
    'customer_id'    => '',
    'order_date'     => date('Y-m-d'),
    'requested_date' => '',
    'notes'          => '',
];
$lines = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $values = [
        'warehouse_id'   => (int)($_POST['warehouse_id'] ?? 0),
        'customer_id'    => (int)($_POST['customer_id']  ?? 0),
        'order_date'     => trim((string)($_POST['order_date']     ?? date('Y-m-d'))),
        'requested_date' => trim((string)($_POST['requested_date'] ?? '')),
        'notes'          => trim((string)($_POST['notes']          ?? '')),
    ];
    $lines = [];
    foreach (($_POST['lines'] ?? []) as $line) {
        $pid = (int)($line['product_id'] ?? 0);
        if ($pid === 0) continue;
        $lines[] = [
            'product_id'   => $pid,
            'qty_ordered'  => (string)($line['qty_ordered'] ?? '0'),
            'unit_price'   => (string)($line['unit_price']  ?? '0'),
            'tax_group_id' => ($line['tax_group_id'] ?? '') === '' ? null : (int)$line['tax_group_id'],
            'notes'        => trim((string)($line['notes'] ?? '')),
        ];
    }

    if ($values['warehouse_id'] <= 0)                               $errors[] = 'Pick a warehouse.';
    if ($values['warehouse_id'] > 0) {
        try { require_warehouse_access((int)$values['warehouse_id']); }
        catch (Throwable $e) { $errors[] = 'No access to that warehouse.'; }
    }
    if ($values['customer_id'] <= 0)                                 $errors[] = 'Pick a customer.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $values['order_date'])) $errors[] = 'Order date is invalid.';
    if ($values['requested_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $values['requested_date'])) $errors[] = 'Requested date is invalid.';
    if (!$lines)                                                     $errors[] = 'Add at least one line.';
    foreach ($lines as $i => $l) {
        $idx = $i + 1;
        if (!is_numeric($l['qty_ordered']) || (float)$l['qty_ordered'] <= 0) $errors[] = "Line $idx: qty must be > 0.";
        if (!is_numeric($l['unit_price'])  || (float)$l['unit_price']  < 0)  $errors[] = "Line $idx: unit price must be >= 0.";
    }

    if (!$errors) {
        try {
            $newId = db_tx(function () use ($values, $lines) {
                $soNo = next_doc_no('SO');
                $stmt = db()->prepare(
                    'INSERT INTO sales_orders
                       (company_id, warehouse_id, so_no, customer_id, status,
                        order_date, requested_date, notes, created_by)
                     VALUES (?, ?, ?, ?, "DRAFT", ?, ?, ?, ?)'
                );
                $stmt->execute([
                    company_id(),
                    $values['warehouse_id'],
                    $soNo,
                    $values['customer_id'],
                    $values['order_date'],
                    $values['requested_date'] ?: null,
                    $values['notes'] ?: null,
                    (int)(current_user()['id'] ?? 0) ?: null,
                ]);
                $so_id = (int)db()->lastInsertId();

                $insLine = db()->prepare(
                    'INSERT INTO so_items
                       (so_id, product_id, qty_ordered, unit_price, tax_group_id, notes)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                foreach ($lines as $l) {
                    $insLine->execute([
                        $so_id, $l['product_id'],
                        (float)$l['qty_ordered'], (float)$l['unit_price'],
                        $l['tax_group_id'],
                        $l['notes'] ?: null,
                    ]);
                }
                so_recompute_totals($so_id);
                audit_log('so_create', 'sales_orders', $so_id, [
                    'so_no' => $soNo, 'customer_id' => $values['customer_id'],
                    'lines' => count($lines),
                ]);
                return $so_id;
            });
            flash('success', 'Sales order created in DRAFT.');
            redirect('/pages/sales_orders/view.php?id=' . $newId);
        } catch (Throwable $e) {
            $errors[] = 'Could not save: ' . $e->getMessage();
        }
    }
}

$PAGE_TITLE = 'New sales order';
require __DIR__ . '/../../partials/header.php';
?>
<div class="mb-6">
  <a href="/pages/sales_orders/index.php" class="text-sm text-gray-500 hover:text-gray-900">&larr; Sales orders</a>
  <h1 class="text-2xl font-semibold text-gray-900 mt-1">New sales order</h1>
</div>

<?php if ($errors): ?>
  <div class="mb-4 border border-red-200 bg-red-50 text-red-800 rounded p-3 text-sm max-w-4xl">
    <ul class="list-disc list-inside">
      <?php foreach ($errors as $err): ?><li><?= e_($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" class="space-y-6 max-w-5xl"
      x-data='{
        products: <?= json_encode(array_map(fn($p) => [
          "id" => (int)$p["id"], "sku" => $p["sku_code"], "name" => $p["name"],
          "uom" => $p["uom"],     "price" => (float)$p["selling_price"],
          "tg"  => $p["default_tax_group_id"] === null ? null : (int)$p["default_tax_group_id"],
        ], $products), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>,
        customerTg: <?= json_encode(array_column($customers, "default_tax_group_id", "id"), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>,
        customerId: <?= (int)$values["customer_id"] ?>,
        lines: <?= json_encode($lines ?: [["product_id"=>"","qty_ordered"=>"","unit_price"=>"","tax_group_id"=>"","notes"=>""]], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>,
        addLine(){ this.lines.push({product_id:"",qty_ordered:"",unit_price:"",tax_group_id:"",notes:""}); },
        rmLine(i){ this.lines.splice(i,1); if(!this.lines.length) this.addLine(); },
        productPicked(i) {
          const p = this.products.find(p => String(p.id) === String(this.lines[i].product_id));
          if (!p) return;
          if (this.lines[i].unit_price === "" || +this.lines[i].unit_price === 0) this.lines[i].unit_price = p.price;
          if (this.lines[i].tax_group_id === "") {
            const cTg = this.customerTg[this.customerId];
            this.lines[i].tax_group_id = (cTg !== null && cTg !== undefined && cTg !== "") ? cTg : (p.tg || "");
          }
        },
        get subtotal() { return this.lines.reduce((s,l) => s + (parseFloat(l.qty_ordered)||0) * (parseFloat(l.unit_price)||0), 0); }
      }'>
  <?= csrf_field() ?>

  <section class="bg-white border border-gray-200 rounded-lg p-5">
    <h2 class="text-base font-semibold text-gray-900 mb-4">Header</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700">Warehouse</label>
        <select name="warehouse_id" required class="mt-1 w-full rounded border-gray-300 shadow-sm">
          <?php foreach ($warehouses as $w): ?>
            <option value="<?= e_($w['id']) ?>" <?= (int)$values['warehouse_id'] === (int)$w['id'] ? 'selected' : '' ?>>
              <?= e_($w['code']) ?> — <?= e_($w['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Customer</label>
        <select name="customer_id" required x-model="customerId" class="mt-1 w-full rounded border-gray-300 shadow-sm">
          <option value="">— pick a customer —</option>
          <?php foreach ($customers as $c): ?>
            <option value="<?= e_($c['id']) ?>" <?= (int)$values['customer_id'] === (int)$c['id'] ? 'selected' : '' ?>>
              <?= e_($c['code']) ?> — <?= e_($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Order date</label>
        <input type="date" name="order_date" value="<?= e_($values['order_date']) ?>" required class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Requested date (optional)</label>
        <input type="date" name="requested_date" value="<?= e_($values['requested_date']) ?>" class="mt-1 w-full rounded border-gray-300 shadow-sm">
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
          <th class="text-right py-1 px-2 w-28">Qty</th>
          <th class="text-right py-1 px-2 w-32">Unit price</th>
          <th class="text-left  py-1 px-2 w-40">Tax group</th>
          <th class="py-1"></th>
        </tr>
      </thead>
      <tbody>
        <template x-for="(l, i) in lines" :key="i">
          <tr class="border-t border-gray-100">
            <td class="py-1 pr-2">
              <select :name="`lines[${i}][product_id]`" x-model="l.product_id" @change="productPicked(i)" required
                      class="w-full rounded border-gray-300 shadow-sm font-mono text-xs">
                <option value="">— pick —</option>
                <?php foreach ($products as $p): ?>
                  <option value="<?= e_($p['id']) ?>"><?= e_($p['sku_code']) ?> — <?= e_($p['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="py-1 px-2">
              <input type="number" step="0.0001" min="0" :name="`lines[${i}][qty_ordered]`" x-model="l.qty_ordered"
                     class="w-full rounded border-gray-300 text-right text-sm">
            </td>
            <td class="py-1 px-2">
              <input type="number" step="0.0001" min="0" :name="`lines[${i}][unit_price]`" x-model="l.unit_price"
                     class="w-full rounded border-gray-300 text-right text-sm">
            </td>
            <td class="py-1 px-2">
              <select :name="`lines[${i}][tax_group_id]`" x-model="l.tax_group_id"
                      class="w-full rounded border-gray-300 text-xs">
                <option value="">(none)</option>
                <?php foreach ($taxGroups as $tg): ?>
                  <option value="<?= e_($tg['id']) ?>"><?= e_($tg['code']) ?> — <?= e_($tg['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="py-1 pl-2 text-right">
              <button type="button" @click="rmLine(i)" class="text-red-600 text-sm hover:underline">remove</button>
            </td>
          </tr>
        </template>
      </tbody>
      <tfoot>
        <tr class="border-t-2 border-gray-200">
          <td colspan="2" class="py-2 text-right text-sm text-gray-500">Subtotal (before tax):</td>
          <td colspan="2" class="py-2 px-2 font-semibold slv-text-primary text-right text-base">
            RM <span x-text="subtotal.toLocaleString('en-MY', {minimumFractionDigits:2, maximumFractionDigits:2})"></span>
          </td>
          <td></td>
        </tr>
      </tfoot>
    </table>
    <p class="text-xs text-gray-500 mt-2">Tax is computed on save and shown on the SO view.</p>
  </section>

  <div class="flex justify-end gap-3 max-w-5xl">
    <a href="/pages/sales_orders/index.php" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    <button type="submit" class="slv-bg-primary text-white px-4 py-2 rounded text-sm font-medium">Save SO as draft</button>
  </div>
</form>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
