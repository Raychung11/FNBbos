<?php
// SLV WMS — pages/invoices/view.php
// Purpose: View one invoice. Status transitions (Mark sent / Mark paid /
//          Cancel) are inline POSTs. "Print A4" opens the print view.
//          "Create DO" creates a Delivery Order if none yet.
// Roles allowed: super_admin, warehouse_manager, sales (RW limited), packer
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','sales','packer','viewer']);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Missing invoice id.');
    redirect('/pages/invoices/index.php');
}

$role     = current_user()['role'] ?? '';
$canWrite = in_array($role, ['super_admin','warehouse_manager','sales','packer'], true);

$loadInv = function (int $id): array {
    $stmt = db()->prepare(
        'SELECT inv.*, w.code AS warehouse_code, w.name AS warehouse_name,
                c.code AS customer_code, c.name AS customer_name,
                c.billing_address, c.tax_no AS customer_tax_no,
                so.so_no, so.status AS so_status,
                u.name AS creator_name
           FROM invoices inv
           JOIN warehouses w   ON w.id = inv.warehouse_id
           JOIN customers   c  ON c.id = inv.customer_id
           JOIN sales_orders so ON so.id = inv.so_id
      LEFT JOIN users u        ON u.id = inv.created_by
          WHERE inv.id = ? AND inv.company_id = ?'
    );
    $stmt->execute([$id, company_id()]);
    $inv = $stmt->fetch();
    if (!$inv) {
        flash('error', 'Invoice not found.');
        redirect('/pages/invoices/index.php');
    }
    require_warehouse_access((int)$inv['warehouse_id']);
    return $inv;
};
$inv = $loadInv($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canWrite) { flash('error','Read-only role.'); redirect('/pages/invoices/view.php?id=' . $id); }
    verify_csrf();
    $do = (string)($_POST['_action'] ?? '');
    try {
        switch ($do) {
        case 'mark_sent':
            if ($inv['status'] !== 'DRAFT') throw new RuntimeException("Cannot mark sent from {$inv['status']}.");
            db()->prepare('UPDATE invoices SET status = "SENT" WHERE id = ?')->execute([$id]);
            audit_log('invoice_mark_sent', 'invoices', $id);
            flash('success', 'Invoice marked as sent.');
            break;
        case 'mark_paid':
            if (!in_array($inv['status'], ['DRAFT','SENT'], true)) throw new RuntimeException("Cannot mark paid from {$inv['status']}.");
            db()->prepare('UPDATE invoices SET status = "PAID" WHERE id = ?')->execute([$id]);
            audit_log('invoice_mark_paid', 'invoices', $id);
            flash('success', 'Invoice marked as paid.');
            break;
        case 'cancel':
            if (in_array($inv['status'], ['CANCELLED','VOID','PAID'], true)) {
                throw new RuntimeException("Cannot cancel a {$inv['status']} invoice.");
            }
            // Refuse if a non-cancelled DO exists.
            $dStmt = db()->prepare("SELECT id FROM delivery_orders WHERE invoice_id = ? AND status <> 'CANCELLED' LIMIT 1");
            $dStmt->execute([$id]);
            if ($dStmt->fetchColumn() !== false) {
                throw new RuntimeException('Cancel the active DO first before cancelling the invoice.');
            }
            db()->prepare('UPDATE invoices SET status = "CANCELLED" WHERE id = ?')->execute([$id]);
            audit_log('invoice_cancel', 'invoices', $id);
            flash('success', 'Invoice cancelled.');
            break;
        case 'create_do':
            $newDoId = do_create_from_invoice($id, (int)(current_user()['id'] ?? 0) ?: null);
            flash('success', 'Delivery order created.');
            redirect('/pages/delivery_orders/view.php?id=' . $newDoId);
        default:
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/pages/invoices/view.php?id=' . $id);
}

$inv = $loadInv($id);

$lines = db()->prepare(
    'SELECT ii.*, p.sku_code, p.name AS product_name, p.uom, tg.code AS tg_code
       FROM invoice_items ii
       JOIN products p ON p.id = ii.product_id
  LEFT JOIN tax_groups tg ON tg.id = ii.tax_group_id
      WHERE ii.invoice_id = ? ORDER BY ii.id'
);
$lines->execute([$id]);
$items = $lines->fetchAll();

$tStmt = db()->prepare(
    'SELECT it.*, tc.code, tc.name
       FROM invoice_taxes it
       JOIN tax_codes tc ON tc.id = it.tax_code_id
      WHERE it.invoice_id = ? ORDER BY tc.code'
);
$tStmt->execute([$id]);
$taxes = $tStmt->fetchAll();

$dStmt = db()->prepare(
    "SELECT id, do_no, status, dispatched_at, delivered_at FROM delivery_orders
      WHERE invoice_id = ? ORDER BY id DESC LIMIT 1"
);
$dStmt->execute([$id]);
$do = $dStmt->fetch() ?: null;

$statusClass = match ($inv['status']) {
    'DRAFT'     => 'bg-gray-100  text-gray-700',
    'SENT'      => 'bg-blue-50   text-blue-700',
    'PAID'      => 'bg-green-50  text-green-700',
    'VOID'      => 'bg-red-50    text-red-700',
    'CANCELLED' => 'bg-red-50    text-red-700',
    default     => 'bg-gray-100  text-gray-500',
};

$PAGE_TITLE = 'Invoice ' . $inv['invoice_no'];
require __DIR__ . '/../../partials/header.php';
?>
<div class="mb-6 flex items-start justify-between gap-3">
  <div>
    <a href="/pages/invoices/index.php" class="text-sm text-gray-500 hover:text-gray-900">&larr; Invoices</a>
    <h1 class="text-2xl font-semibold text-gray-900 mt-1">
      <?= e_($inv['invoice_no']) ?>
      <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs <?= $statusClass ?>"><?= e_($inv['status']) ?></span>
    </h1>
    <div class="text-sm text-gray-500 mt-1">
      <?= e_($inv['warehouse_code']) ?> · for SO
      <a class="text-indigo-700 hover:underline font-mono" href="/pages/sales_orders/view.php?id=<?= e_($inv['so_id']) ?>"><?= e_($inv['so_no']) ?></a>
      · customer <span class="font-mono"><?= e_($inv['customer_code']) ?></span> <?= e_($inv['customer_name']) ?>
    </div>
  </div>
  <div class="flex items-center gap-2 flex-wrap justify-end">
    <a href="/pages/invoices/print.php?id=<?= e_($id) ?>" target="_blank"
       class="px-3 py-2 rounded text-sm border border-gray-300 hover:bg-gray-50">Print A4</a>

    <?php if ($canWrite && $inv['status'] === 'DRAFT'): ?>
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="_action" value="mark_sent">
        <input type="hidden" name="id"      value="<?= e_($id) ?>">
        <button class="px-3 py-2 rounded text-sm border border-blue-300 text-blue-700 hover:bg-blue-50">Mark sent</button>
      </form>
    <?php endif; ?>

    <?php if ($canWrite && in_array($inv['status'], ['DRAFT','SENT'], true)): ?>
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="_action" value="mark_paid">
        <input type="hidden" name="id"      value="<?= e_($id) ?>">
        <button class="px-3 py-2 rounded text-sm border border-green-300 text-green-700 hover:bg-green-50">Mark paid</button>
      </form>
    <?php endif; ?>

    <?php if ($canWrite && $inv['status'] !== 'CANCELLED' && !$do): ?>
      <form method="post" onsubmit="return confirm('Create a delivery order from this invoice?');">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="create_do">
        <input type="hidden" name="id"      value="<?= e_($id) ?>">
        <button class="slv-bg-primary text-white px-3 py-2 rounded text-sm font-medium">Create DO →</button>
      </form>
    <?php elseif ($do): ?>
      <a href="/pages/delivery_orders/view.php?id=<?= e_($do['id']) ?>"
         class="px-3 py-2 rounded text-sm border border-indigo-300 text-indigo-700 hover:bg-indigo-50">
        Open DO <?= e_($do['do_no']) ?>
      </a>
    <?php endif; ?>

    <?php if ($canWrite && !in_array($inv['status'], ['PAID','CANCELLED','VOID'], true)): ?>
      <form method="post" onsubmit="return confirm('Cancel this invoice? Logged in audit trail.');">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="cancel">
        <input type="hidden" name="id"      value="<?= e_($id) ?>">
        <button class="px-3 py-2 rounded text-sm border border-red-300 text-red-700 hover:bg-red-50">Cancel</button>
      </form>
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
    <div class="text-xs text-gray-500">Subtotal</div>
    <div class="text-xl font-semibold slv-text-primary"><?= e_(money($inv['subtotal'])) ?></div>
  </div>
  <div class="bg-white border border-gray-200 rounded p-3">
    <div class="text-xs text-gray-500">Tax</div>
    <div class="text-xl font-semibold slv-text-primary"><?= e_(money($inv['tax_total'])) ?></div>
  </div>
  <div class="bg-white border border-gray-200 rounded p-3">
    <div class="text-xs text-gray-500">Grand total</div>
    <div class="text-2xl font-semibold slv-text-primary"><?= e_(money($inv['grand_total'])) ?></div>
  </div>
</div>

<?php if ($taxes): ?>
<section class="bg-white border border-gray-200 rounded-lg p-4 mb-4">
  <h3 class="text-sm font-semibold text-gray-700 mb-2">Tax breakdown (stored for SST filing)</h3>
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
      <?php foreach ($taxes as $t): ?>
        <tr class="border-t border-gray-100">
          <td class="pr-4 py-1 font-mono"><?= e_($t['code']) ?></td>
          <td class="pr-4 py-1 text-gray-600"><?= e_($t['name']) ?></td>
          <td class="pr-4 py-1 text-right"><?= e_(number_format((float)$t['rate'] * 100, 2)) ?>%</td>
          <td class="pr-4 py-1 text-right"><?= e_(money($t['taxable_amount'])) ?></td>
          <td class="py-1 text-right font-semibold"><?= e_(money($t['tax_amount'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<section class="bg-white border border-gray-200 rounded-lg overflow-hidden">
  <header class="px-4 py-3 bg-gray-50 border-b border-gray-200 text-sm">
    <strong class="text-gray-700">Lines</strong>
    <span class="text-xs text-gray-500 ml-2">— qty taken from <code>so_items.qty_picked</code> at generation time.</span>
  </header>
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 text-gray-600">
      <tr>
        <th class="text-left px-4 py-2 font-medium">SKU</th>
        <th class="text-right px-4 py-2 font-medium">Qty</th>
        <th class="text-right px-4 py-2 font-medium">Unit price</th>
        <th class="text-left px-4 py-2 font-medium">Tax</th>
        <th class="text-right px-4 py-2 font-medium">Subtotal</th>
        <th class="text-right px-4 py-2 font-medium">Tax</th>
        <th class="text-right px-4 py-2 font-medium">Total</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php foreach ($items as $l): ?>
        <tr>
          <td class="px-4 py-2">
            <div><span class="font-mono"><?= e_($l['sku_code']) ?></span></div>
            <div class="text-xs text-gray-500"><?= e_($l['product_name']) ?> · <?= e_($l['uom']) ?></div>
          </td>
          <td class="px-4 py-2 text-right"><?= e_(qty($l['qty'])) ?></td>
          <td class="px-4 py-2 text-right"><?= e_(money($l['unit_price'])) ?></td>
          <td class="px-4 py-2 font-mono text-xs"><?= e_($l['tg_code'] ?: '—') ?></td>
          <td class="px-4 py-2 text-right font-mono"><?= e_(money($l['line_subtotal'])) ?></td>
          <td class="px-4 py-2 text-right font-mono"><?= e_(money($l['line_tax'])) ?></td>
          <td class="px-4 py-2 text-right font-mono font-semibold"><?= e_(money($l['line_total'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
