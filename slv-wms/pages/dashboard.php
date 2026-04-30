<?php
// SLV WMS — pages/dashboard.php
// Purpose: Role-aware dashboard. Mobile-first roles auto-redirect to
//          the scanner. Other roles see tiles + quick-actions tailored
//          to what they actually do.
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
// They can still navigate here manually if they ever need to.
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

// ---- Live counts from the master data we already have -----------------------
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

// ---- Role-tailored tiles + quick actions ------------------------------------
/**
 * Each tile: [label, value, hint, optional href].
 * Hints reference the phase that lights the value up beyond the placeholder.
 */
$tiles = role_dashboard_tiles($role, $counts);
$quick = role_dashboard_actions($role);

function role_dashboard_tiles(string $role, array $counts): array
{
    $admin = ['super_admin', 'warehouse_manager'];

    if (in_array($role, $admin, true)) {
        return [
            ['Active SKUs',          $counts['products'],  'Master → Products',                 '/pages/products/index.php'],
            ['Stock value (FIFO)',   '—',                   'Phase 3 wires FIFO valuation',     null],
            ['Active bins',          $counts['bins'],       'Master → Locations',               '/pages/locations/index.php'],
            ['Suppliers',            $counts['suppliers'],  'Master → Suppliers',               '/pages/suppliers/index.php'],
            ['Pending GRN',          '—',                   'Phase 4',                          null],
            ['Pending pick lists',   '—',                   'Phase 6',                          null],
            ['Pending deliveries',   '—',                   'Phase 8',                          null],
            ['In-transit transfers', '—',                   'Phase 11',                         null],
        ];
    }
    if ($role === 'sales') {
        return [
            ['Active SKUs',     $counts['products'],  'Master → Products',  '/pages/products/index.php'],
            ['Active customers',$counts['customers'], 'Master → Customers', '/pages/customers/index.php'],
            ['Open SOs',        '—',                  'Phase 6',            null],
            ['Stock available', '—',                  'Phase 3',            null],
        ];
    }
    // viewer (and any other read-only role)
    return [
        ['Active SKUs',          $counts['products'], 'Read-only',        '/pages/products/index.php'],
        ['Stock value (FIFO)',   '—',                  'Phase 3',         null],
        ['Pending pick lists',   '—',                  'Phase 6',         null],
        ['Today shipments',      '—',                  'Phase 6',         null],
    ];
}

function role_dashboard_actions(string $role): array
{
    if ($role === 'super_admin') {
        return [
            ['Settings',         '/pages/settings/index.php'],
            ['Users & access',   '/pages/users/index.php'],
            ['Seed demo data',   '/pages/settings/demo_seed.php'],
            ['CSV imports',      '/pages/imports/index.php'],
        ];
    }
    if ($role === 'warehouse_manager') {
        return [
            ['Locations',        '/pages/locations/index.php'],
            ['Products',         '/pages/products/index.php'],
            ['CSV imports',      '/pages/imports/index.php'],
        ];
    }
    if ($role === 'sales') {
        return [
            ['Customers',        '/pages/customers/index.php'],
            ['Products',         '/pages/products/index.php'],
        ];
    }
    return [
        ['Products',         '/pages/products/index.php'],
    ];
}

$PAGE_TITLE = 'Dashboard';
require __DIR__ . '/../partials/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
  <div>
    <h1 class="text-2xl font-semibold text-gray-900">
      Welcome, <?= e_(strtok((string)$user['name'], ' ')) ?>.
    </h1>
    <p class="text-sm text-gray-500 mt-1">
      Signed in as <span class="font-mono"><?= e_($role) ?></span>
      <?php if ($accessible_ids || $role === 'super_admin'): ?>
        · <?= $role === 'super_admin' ? 'all warehouses' : count($accessible_ids) . ' warehouse' . (count($accessible_ids) === 1 ? '' : 's') ?>
      <?php endif; ?>
    </p>
  </div>

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

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
  <?php foreach ($tiles as $tile):
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

<?php if ($quick): ?>
<div class="mt-8">
  <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">Quick actions</h2>
  <div class="flex flex-wrap gap-2">
    <?php foreach ($quick as [$label, $href]): ?>
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
    Phases shipped so far. The dashboard will fill with live numbers as
    the operational phases land.
  </p>
  <ul class="mt-3 text-sm text-gray-600 list-disc list-inside space-y-1">
    <li><span class="font-medium">Phase 0:</span> Foundation (auth, sessions, branding, audit log). ✓</li>
    <li><span class="font-medium">Phase 1:</span> Settings backend (branding, doc numbering, tax, SMTP, users). ✓</li>
    <li><span class="font-medium">Phase 2:</span> Master data (categories, products, bins, suppliers, customers + CSV imports). ✓</li>
    <li><span class="font-medium">Phase 3:</span> FIFO engine + opening-stock importer + stock-on-hand report.</li>
    <li><span class="font-medium">Phase 4+:</span> GRN, picking, invoicing, transfers, reports.</li>
  </ul>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../partials/footer.php'; ?>
