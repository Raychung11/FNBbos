<?php
// SLV WMS — pages/dashboard.php
// Purpose: Role-aware dashboard. Mobile-first roles auto-redirect to the
//          scanner. Other roles see a coloured role banner, a tile set,
//          and a quick-action strip that are all distinct enough that
//          you can tell which role you're signed in as without reading.
// Roles allowed: any logged-in role
// Last updated: 2026-04-30

declare(strict_types=1);

if (!function_exists('require_login')) {
    require __DIR__ . '/../lib/bootstrap.php';
}
require_login();

$user = current_user();
$role = $user['role'] ?? 'viewer';

// Mobile-first roles never need this page — bounce them to the scanner.
if (is_mobile_first_role($role) && empty($_GET['stay'])) {
    redirect('/m/home.php');
}

// Handle warehouse-filter change posted from the header form.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'set_warehouse') {
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

// ---- Live counts -------------------------------------------------------------
$cid = company_id();
$accessibleClause = $accessible_ids
    ? 'IN (' . implode(',', array_fill(0, count($accessible_ids), '?')) . ')'
    : '= 0';
$accessibleParams = $accessible_ids ?: [];

$counts = [
    'products'   => (int)db()->query("SELECT COUNT(*) FROM products  WHERE company_id={$cid} AND status='ACTIVE'")->fetchColumn(),
    'customers'  => (int)db()->query("SELECT COUNT(*) FROM customers WHERE company_id={$cid} AND status='ACTIVE'")->fetchColumn(),
    'suppliers'  => (int)db()->query("SELECT COUNT(*) FROM suppliers WHERE company_id={$cid} AND status='ACTIVE'")->fetchColumn(),
];
$binCountStmt = db()->prepare(
    "SELECT COUNT(*) FROM bins b
       JOIN racks r      ON r.id = b.rack_id
       JOIN zones z      ON z.id = r.zone_id
       JOIN warehouses w ON w.id = z.warehouse_id
      WHERE w.company_id = ? AND b.status = 'ACTIVE'
        AND w.id $accessibleClause"
);
$binCountStmt->execute(array_merge([$cid], $accessibleParams));
$counts['bins'] = (int)$binCountStmt->fetchColumn();

// Stock value (FIFO) — wired up in Phase 3.
$valueStmt = db()->prepare(
    "SELECT COALESCE(SUM(qty_remaining * unit_cost), 0)
       FROM stock_layers
      WHERE company_id = ? AND status = 'ACTIVE'
        AND warehouse_id $accessibleClause"
);
$valueStmt->execute(array_merge([$cid], $accessibleParams));
$counts['stock_value'] = (float)$valueStmt->fetchColumn();

// Pending GRN (Phase 4): anything in DRAFT / RECEIVING / RECEIVED / PUTAWAY.
$pendStmt = db()->prepare(
    "SELECT COUNT(*) FROM grn
      WHERE company_id = ?
        AND status IN ('DRAFT','RECEIVING','RECEIVED','PUTAWAY')
        AND warehouse_id $accessibleClause"
);
$pendStmt->execute(array_merge([$cid], $accessibleParams));
$counts['pending_grn'] = (int)$pendStmt->fetchColumn();

// Open SOs (Phase 6): DRAFT / CONFIRMED / PICKING / PICKED.
$soStmt = db()->prepare(
    "SELECT COUNT(*) FROM sales_orders
      WHERE company_id = ?
        AND status IN ('DRAFT','CONFIRMED','PICKING','PICKED')
        AND warehouse_id $accessibleClause"
);
$soStmt->execute(array_merge([$cid], $accessibleParams));
$counts['open_so'] = (int)$soStmt->fetchColumn();

// Pending pick lists (Phase 6): DRAFT / ASSIGNED / IN_PROGRESS.
$plStmt = db()->prepare(
    "SELECT COUNT(*) FROM pick_lists
      WHERE company_id = ?
        AND status IN ('DRAFT','ASSIGNED','IN_PROGRESS')
        AND warehouse_id $accessibleClause"
);
$plStmt->execute(array_merge([$cid], $accessibleParams));
$counts['pending_pick'] = (int)$plStmt->fetchColumn();

// ---- Role-specific copy + tiles ---------------------------------------------

/** @return array{
 *   role_label:string,
 *   sub:string,
 *   banner_class:string,
 *   tiles:array<int,array{0:string,1:mixed,2:string,3:?string}>,
 *   actions:array<int,array{0:string,1:string}>
 * } */
function dashboard_view_for(string $role, array $counts): array
{
    switch ($role) {
        case 'super_admin':
            return [
                'role_label'   => 'Super admin',
                'sub'          => 'Full system access — every warehouse, every operation, every setting.',
                'banner_class' => 'bg-amber-50 border-amber-200 text-amber-900',
                'tiles' => [
                    ['Active SKUs',          $counts['products'],                              'Master → Products',          '/pages/products/index.php'],
                    ['Stock value (FIFO)',   money($counts['stock_value']),                    'Reports → Stock on hand',    '/pages/reports/stock_on_hand.php'],
                    ['Active bins',          $counts['bins'],                                  'Master → Locations',         '/pages/locations/index.php'],
                    ['Customers',            $counts['customers'],                             'Master → Customers',         '/pages/customers/index.php'],
                    ['Pending GRN',          $counts['pending_grn'],                           'Receive → putaway',          '/pages/grn/index.php?status=RECEIVING'],
                    ['Open SOs',             $counts['open_so'],                               'Confirm → pick',             '/pages/sales_orders/index.php?status=CONFIRMED'],
                    ['Pending pick lists',   $counts['pending_pick'],                          'Phase 7 mobile picking',     '/pages/pick_lists/index.php?status=DRAFT'],
                    ['In-transit transfers', '—',                                              'Phase 11',                   null],
                ],
                'actions' => [
                    ['+ New GRN',        '/pages/grn/create.php'],
                    ['+ New SO',         '/pages/sales_orders/create.php'],
                    ['Settings',         '/pages/settings/index.php'],
                    ['Users & access',   '/pages/users/index.php'],
                    ['CSV imports',      '/pages/imports/index.php'],
                ],
            ];

        case 'warehouse_manager':
            return [
                'role_label'   => 'Warehouse manager',
                'sub'          => 'Run the warehouses you have access to: receive, putaway, count, transfer.',
                'banner_class' => 'bg-emerald-50 border-emerald-200 text-emerald-900',
                'tiles' => [
                    ['Stock value (FIFO)',   money($counts['stock_value']),                    'Reports → Stock on hand',    '/pages/reports/stock_on_hand.php'],
                    ['Active bins',          $counts['bins'],                                  'Master → Locations',         '/pages/locations/index.php'],
                    ['Active SKUs',          $counts['products'],                              'Master → Products',          '/pages/products/index.php'],
                    ['Pending GRN',          $counts['pending_grn'],                           'Receive → putaway',          '/pages/grn/index.php?status=RECEIVING'],
                    ['Open SOs',             $counts['open_so'],                               'Sales orders',               '/pages/sales_orders/index.php'],
                    ['Pending pick lists',   $counts['pending_pick'],                          'Pick lists',                 '/pages/pick_lists/index.php?status=DRAFT'],
                ],
                'actions' => [
                    ['+ New GRN',          '/pages/grn/create.php'],
                    ['+ New SO',           '/pages/sales_orders/create.php'],
                    ['Locations',          '/pages/locations/index.php'],
                    ['Stock on hand',      '/pages/reports/stock_on_hand.php'],
                    ['Stock movements',    '/pages/reports/stock_movements.php'],
                ],
            ];

        case 'sales':
            return [
                'role_label'   => 'Sales',
                'sub'          => 'Find customers, check stock availability, raise sales orders.',
                'banner_class' => 'bg-sky-50 border-sky-200 text-sky-900',
                'tiles' => [
                    ['Active customers', $counts['customers'], 'Master → Customers', '/pages/customers/index.php'],
                    ['Active SKUs',      $counts['products'],  'Master → Products',  '/pages/products/index.php'],
                    ['Open SOs',         $counts['open_so'],   'Sales orders',       '/pages/sales_orders/index.php'],
                    ['Stock available',  money($counts['stock_value']), 'Stock on hand', '/pages/reports/stock_on_hand.php'],
                ],
                'actions' => [
                    ['+ New SO',          '/pages/sales_orders/create.php'],
                    ['Find a customer',   '/pages/customers/index.php'],
                    ['Browse SKUs',       '/pages/products/index.php'],
                    ['Check stock',       '/pages/reports/stock_on_hand.php'],
                ],
            ];

        case 'viewer':
            return [
                'role_label'   => 'Read-only viewer',
                'sub'          => 'Reports and master-data lookup. No edit permissions.',
                'banner_class' => 'bg-gray-100 border-gray-200 text-gray-700',
                'tiles' => [
                    ['Active SKUs',      $counts['products'],           'Master → Products',          '/pages/products/index.php'],
                    ['Stock value',      money($counts['stock_value']), 'Reports → Stock on hand',    '/pages/reports/stock_on_hand.php'],
                    ['Active bins',      $counts['bins'],               'Master → Locations',         '/pages/locations/index.php'],
                ],
                'actions' => [
                    ['Stock on hand',     '/pages/reports/stock_on_hand.php'],
                    ['Stock movements',   '/pages/reports/stock_movements.php'],
                ],
            ];

        // receiver/picker/packer/driver — desktop fallback only; the
        // mobile-first redirect above handles the normal flow.
        default:
            return [
                'role_label'   => ucfirst($role ?: 'unknown'),
                'sub'          => 'Your tasks live in the mobile scanner.',
                'banner_class' => 'bg-indigo-50 border-indigo-200 text-indigo-900',
                'tiles'        => [],
                'actions'      => [
                    ['Open mobile scanner', '/m/home.php'],
                ],
            ];
    }
}

$view = dashboard_view_for($role, $counts);

$PAGE_TITLE = 'Dashboard';
require __DIR__ . '/../partials/header.php';
?>
<!-- Role-tinted identity banner. Different colour, copy and width per role. -->
<div class="mb-6 rounded-lg border <?= e_($view['banner_class']) ?> px-4 py-3 flex flex-wrap items-center justify-between gap-3">
  <div>
    <div class="text-xs uppercase tracking-wide opacity-70">
      <?= e_($view['role_label']) ?> view
    </div>
    <div class="text-base font-semibold mt-0.5">
      Welcome, <?= e_(strtok((string)$user['name'], ' ')) ?>
      <span class="opacity-60 font-normal text-sm">· <?= e_($user['email'] ?? '') ?></span>
    </div>
    <div class="text-sm opacity-80 mt-0.5"><?= e_($view['sub']) ?></div>
  </div>
  <div class="text-xs opacity-70">
    <?= $role === 'super_admin'
        ? 'all warehouses'
        : (count($accessible_ids) . ' warehouse' . (count($accessible_ids) === 1 ? '' : 's') . ' assigned') ?>
  </div>
</div>

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
  <h1 class="text-2xl font-semibold text-gray-900">Dashboard</h1>
  <div class="flex items-center gap-3">
    <a href="/m/home.php" class="text-sm text-indigo-700 hover:underline inline-flex items-center gap-1">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
      </svg>
      Mobile scanner
    </a>
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
</div>

<?php if ($view['tiles']): ?>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
  <?php foreach ($view['tiles'] as $tile):
    [$label, $value, $hint, $href] = array_pad($tile, 4, null);
    $valueStr = is_int($value) || (is_string($value) && $value !== '—') ? (string)$value : '—';
  ?>
    <?php $tag = $href ? 'a' : 'div'; ?>
    <<?= $tag ?> <?= $href ? 'href="' . e_($href) . '"' : '' ?>
      class="block bg-white border border-gray-200 rounded-lg p-4 <?= $href ? 'hover:border-indigo-300 hover:shadow-sm' : '' ?> transition">
      <div class="text-sm text-gray-500"><?= e_($label) ?></div>
      <div class="mt-1 text-2xl font-semibold slv-text-primary"><?= e_($valueStr) ?></div>
      <div class="mt-1 text-xs text-gray-400"><?= e_($hint) ?></div>
    </<?= $tag ?>>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($view['actions']): ?>
<div class="mt-8">
  <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">Quick actions</h2>
  <div class="flex flex-wrap gap-2">
    <?php foreach ($view['actions'] as [$label, $href]): ?>
      <a href="<?= e_($href) ?>"
         class="inline-flex items-center px-3 py-2 rounded text-sm bg-white border border-gray-300 hover:border-indigo-300 hover:bg-indigo-50">
        <?= e_($label) ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($role === 'super_admin'): ?>
<div class="mt-8 bg-white border border-gray-200 rounded-lg p-6">
  <h2 class="text-base font-semibold text-gray-900 mb-2">Build status</h2>
  <p class="text-sm text-gray-600">
    Phases shipped so far. Live numbers fill in as operational phases land.
  </p>
  <ul class="mt-3 text-sm text-gray-600 list-disc list-inside space-y-1">
    <li><span class="font-medium">Phase 0:</span> Foundation (auth, sessions, branding, audit log). ✓</li>
    <li><span class="font-medium">Phase 1:</span> Settings backend (branding, doc numbering, tax, SMTP, users). ✓</li>
    <li><span class="font-medium">Phase 2:</span> Master data (categories, products, bins, suppliers, customers + CSV imports). ✓</li>
    <li><span class="font-medium">Phase 3:</span> FIFO engine, opening-stock importer, stock-on-hand &amp; movements reports. ✓</li>
    <li><span class="font-medium">Phase 4:</span> GRN — desktop receive + putaway suggestion engine. ✓</li>
    <li><span class="font-medium">Phase 5:</span> Mobile PWA shell + receive + putaway scan flows. ✓</li>
    <li><span class="font-medium">Phase 6:</span> Sales orders + pick lists desktop (multi-tax line calc, FIFO bin allocation, walk-path). ✓</li>
    <li><span class="font-medium">Phase 7:</span> Mobile picking scan flow (FIFO consume via record_issue, status auto-flip). ✓</li>
    <li><span class="font-medium">Phase 8:</span> Invoicing + Delivery Order A4 PDFs.</li>
    <li><span class="font-medium">Phase 9+:</span> Mobile dispatch + POD, adjustments, counts, transfers.</li>
  </ul>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../partials/footer.php'; ?>
