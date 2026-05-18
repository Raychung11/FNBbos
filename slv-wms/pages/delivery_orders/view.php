<?php
// SLV WMS — pages/delivery_orders/view.php
// Purpose: View one delivery order. Manager can dispatch (assign driver +
//          vehicle, status READY → IN_TRANSIT) or cancel; manager/driver
//          can mark delivered (IN_TRANSIT → DELIVERED). POD upload is
//          Phase 9 (mobile).
// Roles allowed: super_admin, warehouse_manager, packer, driver
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','packer','driver','viewer']);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Missing DO id.');
    redirect('/pages/delivery_orders/index.php');
}

$role = current_user()['role'] ?? '';
$uid  = (int)(current_user()['id'] ?? 0) ?: null;

$loadDo = function (int $id) {
    $stmt = db()->prepare(
        'SELECT d.*, w.code AS warehouse_code, w.name AS warehouse_name,
                c.code AS customer_code, c.name AS customer_name,
                inv.invoice_no, inv.grand_total, inv.so_id,
                so.so_no,
                u.name AS driver_name
           FROM delivery_orders d
           JOIN warehouses w   ON w.id = d.warehouse_id
           JOIN customers   c  ON c.id = d.customer_id
           JOIN invoices   inv ON inv.id = d.invoice_id
           JOIN sales_orders so ON so.id = inv.so_id
      LEFT JOIN users       u  ON u.id = d.driver_user_id
          WHERE d.id = ? AND d.company_id = ?'
    );
    $stmt->execute([$id, company_id()]);
    $do = $stmt->fetch();
    if (!$do) {
        flash('error', 'Delivery order not found.');
        redirect('/pages/delivery_orders/index.php');
    }
    require_warehouse_access((int)$do['warehouse_id']);
    return $do;
};
$do = $loadDo($id);

$canDispatch = in_array($role, ['super_admin','warehouse_manager','packer'], true);
$canDeliver  = in_array($role, ['super_admin','warehouse_manager','driver'], true);
$canCancel   = in_array($role, ['super_admin','warehouse_manager'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['_action'] ?? '');
    try {
        switch ($act) {
        case 'dispatch':
            if (!$canDispatch) throw new RuntimeException('Read-only role.');
            $driver = ($_POST['driver_user_id'] ?? '') === '' ? null : (int)$_POST['driver_user_id'];
            $vehicle = trim((string)($_POST['vehicle'] ?? ''));
            do_dispatch($id, $driver, $vehicle ?: null, $uid);
            flash('success', 'Dispatched.');
            break;

        case 'deliver':
            if (!$canDeliver) throw new RuntimeException('Not allowed.');
            // Drivers can only mark their own DOs delivered.
            if ($role === 'driver' && (int)($do['driver_user_id'] ?? 0) !== ($uid ?? 0)) {
                throw new RuntimeException('You are not the assigned driver.');
            }
            do_mark_delivered($id, $uid);
            flash('success', 'Marked delivered.');
            break;

        case 'cancel':
            if (!$canCancel) throw new RuntimeException('Not allowed.');
            if (in_array($do['status'], ['DELIVERED','CANCELLED'], true)) {
                throw new RuntimeException("Cannot cancel a {$do['status']} DO.");
            }
            db()->prepare('UPDATE delivery_orders SET status = "CANCELLED" WHERE id = ?')->execute([$id]);
            audit_log('do_cancel', 'delivery_orders', $id);
            flash('success', 'DO cancelled.');
            break;

        default:
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/pages/delivery_orders/view.php?id=' . $id);
}

$do = $loadDo($id);

$lines = db()->prepare(
    'SELECT di.*, p.sku_code, p.name AS product_name, p.uom
       FROM do_items di
       JOIN products p ON p.id = di.product_id
      WHERE di.do_id = ? ORDER BY di.id'
);
$lines->execute([$id]);
$items = $lines->fetchAll();

// Driver picker (active users with driver-ish roles).
$driverStmt = db()->prepare(
    "SELECT id, name, email, role FROM users
      WHERE company_id = ? AND status = 'ACTIVE'
        AND role IN ('driver','warehouse_manager','super_admin','packer')
   ORDER BY FIELD(role,'driver','packer','warehouse_manager','super_admin'), name"
);
$driverStmt->execute([company_id()]);
$drivers = $driverStmt->fetchAll();

$statusClass = match ($do['status']) {
    'READY'      => 'bg-gray-100  text-gray-700',
    'IN_TRANSIT' => 'bg-amber-50  text-amber-800',
    'DELIVERED'  => 'bg-green-50  text-green-700',
    'RETURNED'   => 'bg-orange-50 text-orange-700',
    'CANCELLED'  => 'bg-red-50    text-red-700',
    default      => 'bg-gray-100  text-gray-500',
};

$PAGE_TITLE = 'DO ' . $do['do_no'];
require __DIR__ . '/../../partials/header.php';
?>
<div class="mb-6 flex items-start justify-between gap-3">
  <div>
    <a href="/pages/delivery_orders/index.php" class="text-sm text-gray-500 hover:text-gray-900">&larr; Delivery orders</a>
    <h1 class="text-2xl font-semibold text-gray-900 mt-1">
      <?= e_($do['do_no']) ?>
      <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs <?= $statusClass ?>"><?= e_($do['status']) ?></span>
    </h1>
    <div class="text-sm text-gray-500 mt-1">
      <?= e_($do['warehouse_code']) ?>
      · invoice
      <a class="text-indigo-700 hover:underline font-mono" href="/pages/invoices/view.php?id=<?= e_($do['invoice_id']) ?>"><?= e_($do['invoice_no']) ?></a>
      · SO
      <a class="text-indigo-700 hover:underline font-mono" href="/pages/sales_orders/view.php?id=<?= e_($do['so_id']) ?>"><?= e_($do['so_no']) ?></a>
      · customer <span class="font-mono"><?= e_($do['customer_code']) ?></span> <?= e_($do['customer_name']) ?>
    </div>
  </div>
  <div class="flex items-center gap-2 flex-wrap justify-end">
    <a href="/pages/delivery_orders/print.php?id=<?= e_($id) ?>" target="_blank"
       class="px-3 py-2 rounded text-sm border border-gray-300 hover:bg-gray-50">Print A4</a>

    <?php if ($canDeliver && $do['status'] === 'IN_TRANSIT'): ?>
      <form method="post" onsubmit="return confirm('Mark this DO as delivered?');">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="deliver">
        <input type="hidden" name="id"      value="<?= e_($id) ?>">
        <button class="slv-bg-primary text-white px-3 py-2 rounded text-sm font-medium">Mark delivered ✓</button>
      </form>
    <?php endif; ?>

    <?php if ($canCancel && !in_array($do['status'], ['DELIVERED','CANCELLED'], true)): ?>
      <form method="post" onsubmit="return confirm('Cancel this DO?');">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="cancel">
        <input type="hidden" name="id"      value="<?= e_($id) ?>">
        <button class="px-3 py-2 rounded text-sm border border-red-300 text-red-700 hover:bg-red-50">Cancel DO</button>
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
    <div class="text-xs text-gray-500">Invoice total</div>
    <div class="text-xl font-semibold slv-text-primary"><?= e_(money($do['grand_total'])) ?></div>
  </div>
  <div class="bg-white border border-gray-200 rounded p-3">
    <div class="text-xs text-gray-500">Driver</div>
    <div class="text-base font-semibold text-gray-900"><?= e_($do['driver_name'] ?? '—') ?></div>
    <div class="text-xs text-gray-500"><?= e_($do['vehicle'] ?? '—') ?></div>
  </div>
  <div class="bg-white border border-gray-200 rounded p-3">
    <div class="text-xs text-gray-500">Dispatched / delivered</div>
    <div class="text-xs text-gray-700"><?= e_($do['dispatched_at'] ?? '—') ?></div>
    <div class="text-xs text-emerald-700"><?= e_($do['delivered_at'] ?? '—') ?></div>
  </div>
</div>

<?php if ($canDispatch && $do['status'] === 'READY'): ?>
  <section class="bg-white border border-amber-200 bg-amber-50/40 rounded-lg p-4 mb-4">
    <h2 class="text-sm font-semibold text-amber-900 mb-3">Dispatch</h2>
    <form method="post" class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="_action" value="dispatch">
      <input type="hidden" name="id"      value="<?= e_($id) ?>">
      <div>
        <label class="block text-xs text-gray-500">Driver</label>
        <select name="driver_user_id" class="mt-1 w-full rounded border-gray-300 shadow-sm text-sm">
          <option value="">— pick a driver —</option>
          <?php foreach ($drivers as $u): ?>
            <option value="<?= e_($u['id']) ?>"><?= e_($u['name']) ?> <span class="opacity-50">(<?= e_($u['role']) ?>)</span></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs text-gray-500">Vehicle</label>
        <input type="text" name="vehicle" maxlength="64" placeholder="e.g. WXY1234" class="mt-1 w-full rounded border-gray-300 shadow-sm text-sm">
      </div>
      <button class="slv-bg-primary text-white px-3 py-2 rounded text-sm font-medium">Dispatch →</button>
    </form>
  </section>
<?php endif; ?>

<?php if ($do['notes']): ?>
  <div class="mb-4 bg-amber-50 border border-amber-200 rounded p-3 text-sm text-amber-900">
    <strong>Notes:</strong> <?= e_($do['notes']) ?>
  </div>
<?php endif; ?>

<section class="bg-white border border-gray-200 rounded-lg overflow-hidden">
  <header class="px-4 py-3 bg-gray-50 border-b border-gray-200 text-sm">
    <strong class="text-gray-700">Items being delivered</strong>
  </header>
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 text-gray-600">
      <tr>
        <th class="text-left px-4 py-2 font-medium">SKU</th>
        <th class="text-right px-4 py-2 font-medium">Qty</th>
        <th class="text-left px-4 py-2 font-medium">UoM</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php foreach ($items as $l): ?>
        <tr>
          <td class="px-4 py-2">
            <div class="font-mono"><?= e_($l['sku_code']) ?></div>
            <div class="text-xs text-gray-500"><?= e_($l['product_name']) ?></div>
          </td>
          <td class="px-4 py-2 text-right"><?= e_(qty($l['qty'])) ?></td>
          <td class="px-4 py-2 text-gray-500 text-xs"><?= e_($l['uom']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
