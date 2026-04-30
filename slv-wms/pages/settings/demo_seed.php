<?php
// SLV WMS — pages/settings/demo_seed.php
// Purpose: One-click idempotent loader for a realistic Malaysian retail
//          distribution demo: 2 warehouses, 7 categories, 8 SKUs (with
//          primary barcodes), 5 zones / 7 racks / 14 bins, 3 suppliers,
//          3 customers, 3 demo user accounts. Safe to re-run; existing
//          rows are preserved.
// Roles allowed: super_admin
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role('super_admin');

$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $summary = demo_seed_run();
        audit_log('demo_seed_run', 'companies', company_id(), $summary);
        flash('success', 'Demo data seeded.');
    } catch (Throwable $e) {
        flash('error', 'Seeding failed: ' . $e->getMessage());
        redirect('/pages/settings/demo_seed.php');
    }
}

/**
 * Run the seed inside one transaction. Returns counts of *newly inserted*
 * rows per entity. Re-runs return zero counts for everything that already
 * existed.
 */
function demo_seed_run(): array
{
    // bcrypt('ChangeMe!2026', PASSWORD_BCRYPT, ['cost'=>12])
    $demoHash = '$2y$12$qH2aqyG5I3UIbWWZpvhR/O10WQ/kSsBpqhY9NvGDOSE5wxp8jBism';
    $cid      = company_id();

    return db_tx(function () use ($cid, $demoHash) {
        $n = ['warehouses'=>0, 'users'=>0, 'access'=>0, 'categories'=>0,
              'products'=>0, 'barcodes'=>0, 'zones'=>0, 'racks'=>0, 'bins'=>0,
              'suppliers'=>0, 'customers'=>0];

        // -------------------- 1. Warehouses --------------------
        $stmt = db()->prepare(
            "INSERT IGNORE INTO warehouses (company_id, code, name, address, contact)
             VALUES (?, 'WH02', ?, ?, ?)"
        );
        $stmt->execute([$cid,
            'KL Distribution Center',
            'Lot 5, Jalan Industri 3, Taman Perindustrian Subang, 47500 Subang Jaya, Selangor',
            '+60 3-5555 1234']);
        $n['warehouses'] += $stmt->rowCount();

        // Backfill WH01 with a real-looking address if unset.
        db()->prepare(
            "UPDATE warehouses
                SET address = ?, contact = ?
              WHERE company_id = ? AND code = 'WH01'
                AND (address IS NULL OR address = '')"
        )->execute([
            'No.1, Jalan Utama, Pusat Bandar, 50450 Kuala Lumpur',
            '+60 3-2222 1111', $cid,
        ]);

        $whIds = demo_lookup_map(
            "SELECT code, id FROM warehouses WHERE company_id = ?", [$cid]
        );

        // -------------------- 2. Categories --------------------
        $catTree = [
            ['Beverages',     null],
            ['Snacks',        null],
            ['Personal Care', null],
            ['Soft Drinks',   'Beverages'],
            ['Juices',        'Beverages'],
            ['Chips',         'Snacks'],
            ['Biscuits',      'Snacks'],
        ];
        $catIds = [];
        $insCat = db()->prepare(
            'INSERT IGNORE INTO categories (company_id, name, parent_id) VALUES (?, ?, ?)'
        );
        foreach ($catTree as [$name, $parent]) {
            $parentId = $parent ? ($catIds[$parent] ?? null) : null;
            $insCat->execute([$cid, $name, $parentId]);
            $n['categories'] += $insCat->rowCount();

            $sql = 'SELECT id FROM categories WHERE company_id = ? AND name = ? AND ';
            $sql .= $parentId === null ? 'parent_id IS NULL' : 'parent_id = ?';
            $look = db()->prepare($sql . ' LIMIT 1');
            $params = $parentId === null ? [$cid, $name] : [$cid, $name, $parentId];
            $look->execute($params);
            $catIds[$name] = (int)$look->fetchColumn();
        }

        // -------------------- 3. Products + primary barcodes --------------------
        $stdTaxId = (int)(db()->query(
            "SELECT id FROM tax_groups WHERE company_id = " . $cid . " AND code = 'STD' LIMIT 1"
        )->fetchColumn() ?: 0);
        $zrTaxId  = (int)(db()->query(
            "SELECT id FROM tax_groups WHERE company_id = " . $cid . " AND code = 'ZR' LIMIT 1"
        )->fetchColumn() ?: 0);

        // sku_code, name, category, uom, pack_size, weight_kg, price,
        // min/max/reorder, tax_group_id, barcode (EAN-13 placeholder)
        $skus = [
            ['SKU-COKE-330',  'Coca-Cola Can 330ml',          'Soft Drinks',   'pcs', 24, 0.350, 2.50,  100, 5000, 200, $stdTaxId, '9551001000017'],
            ['SKU-COKE-1500', 'Coca-Cola PET 1.5L',           'Soft Drinks',   'pcs', 12, 1.600, 5.90,   50, 1500,  80, $stdTaxId, '9551001000024'],
            ['SKU-7UP-330',   '7UP Can 330ml',                'Soft Drinks',   'pcs', 24, 0.350, 2.30,   80, 4000, 160, $stdTaxId, '9556001100039'],
            ['SKU-OJ-1L',     'Tropicana Orange Juice 1L',    'Juices',        'pcs',  6, 1.080, 8.90,   40, 1200,  60, $stdTaxId, '0048500001127'],
            ['SKU-CHIPS-150', "Lay's Classic Chips 150g",      'Chips',         'pcs', 30, 0.155, 6.20,   60, 2400, 100, $stdTaxId, '8888167038152'],
            ['SKU-BIS-200',   'Marie Biscuits 200g',          'Biscuits',      'pcs', 24, 0.220, 3.80,   60, 2400, 120, $stdTaxId, '9555009804111'],
            ['SKU-SHAMP-400', 'Pantene Shampoo 400ml',        'Personal Care', 'pcs', 12, 0.430, 19.90,  20,  600,  40, $stdTaxId, '4902430741712'],
            ['SKU-SOAP-100',  'Dove Soap Bar 100g',           'Personal Care', 'pcs', 48, 0.115, 4.50,  100, 4800, 240, $stdTaxId, '0011111655409'],
        ];

        $insProd = db()->prepare(
            'INSERT IGNORE INTO products
               (company_id, sku_code, name, category_id, uom, pack_size,
                weight_kg, selling_price, min_qty, max_qty, reorder_point,
                default_tax_group_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "ACTIVE")'
        );
        $insBc = db()->prepare(
            'INSERT IGNORE INTO product_barcodes (product_id, barcode, type, is_primary)
             VALUES (?, ?, ?, 1)'
        );
        foreach ($skus as [$sku, $name, $catName, $uom, $pack, $kg, $price, $minQ, $maxQ, $rp, $tgId, $bc]) {
            $insProd->execute([$cid, $sku, $name, $catIds[$catName] ?? null, $uom, $pack, $kg, $price, $minQ, $maxQ, $rp, $tgId ?: null]);
            $n['products'] += $insProd->rowCount();

            $look = db()->prepare("SELECT id FROM products WHERE company_id = ? AND sku_code = ? LIMIT 1");
            $look->execute([$cid, $sku]);
            $pid = (int)$look->fetchColumn();
            if ($pid && $bc) {
                $insBc->execute([$pid, $bc, 'EAN']);
                $n['barcodes'] += $insBc->rowCount();
            }
        }

        // -------------------- 4. Zones / racks / bins --------------------
        // Layout per warehouse: [ZoneCode => [type, name, [RackCode => [BinCode, capacity, pickable], ...]]]
        $layout = [
            'WH01' => [
                'Z-A' => ['DRY',     'Beverages aisle', [
                    'R01' => [['B01', 24, 1], ['B02', 24, 1], ['B03', 24, 1]],
                    'R02' => [['B01', 24, 1], ['B02', 24, 1], ['B03', 24, 1]],
                ]],
                'Z-B' => ['DRY',     'Snacks aisle',    [
                    'R01' => [['B01', 30, 1], ['B02', 30, 1]],
                ]],
                'Z-C' => ['DRY',     'Personal care',   [
                    'R01' => [['B01', 24, 1], ['B02', 24, 1]],
                ]],
                'Z-PACK' => ['PACKING', 'Pack out',     [
                    'R01' => [['B01', null, 0]],
                ]],
            ],
            'WH02' => [
                'Z-A' => ['DRY', 'General storage', [
                    'R01' => [['B01', 50, 1], ['B02', 50, 1]],
                ]],
            ],
        ];

        $insZone = db()->prepare(
            'INSERT IGNORE INTO zones (warehouse_id, code, name, type) VALUES (?, ?, ?, ?)'
        );
        $insRack = db()->prepare(
            'INSERT IGNORE INTO racks (zone_id, code) VALUES (?, ?)'
        );
        $insBin = db()->prepare(
            'INSERT IGNORE INTO bins (rack_id, code, full_code, barcode, capacity_units, pickable, status)
             VALUES (?, ?, ?, ?, ?, ?, "ACTIVE")'
        );

        foreach ($layout as $whCode => $zones) {
            $wid = $whIds[$whCode] ?? null;
            if (!$wid) continue;

            foreach ($zones as $zCode => [$zType, $zName, $racks]) {
                $insZone->execute([$wid, $zCode, $zName, $zType]);
                $n['zones'] += $insZone->rowCount();

                $look = db()->prepare("SELECT id FROM zones WHERE warehouse_id = ? AND code = ? LIMIT 1");
                $look->execute([$wid, $zCode]);
                $zid = (int)$look->fetchColumn();

                foreach ($racks as $rCode => $bins) {
                    $insRack->execute([$zid, $rCode]);
                    $n['racks'] += $insRack->rowCount();

                    $look = db()->prepare("SELECT id FROM racks WHERE zone_id = ? AND code = ? LIMIT 1");
                    $look->execute([$zid, $rCode]);
                    $rid = (int)$look->fetchColumn();

                    foreach ($bins as [$bCode, $cap, $pickable]) {
                        $full = "$whCode/$zCode/$rCode/$bCode";
                        $insBin->execute([$rid, $bCode, $full, $full, $cap, $pickable]);
                        $n['bins'] += $insBin->rowCount();
                    }
                }
            }
        }

        // -------------------- 5. Suppliers --------------------
        $suppliers = [
            ['SUP-COKE',  'Coca-Cola Bottlers (M) Sdn Bhd', 'Tan Wei Ming',  '+60 3-7100 0001', 'orders@cocabottlers.com.my', 'Lot 12, Shah Alam Industrial Park, Selangor', 'Net 30'],
            ['SUP-PEPSI', 'PepsiCo Malaysia Sdn Bhd',       'Lim Hui',       '+60 3-7100 0002', 'sales@pepsico.my',           'Persiaran Industri, 47100 Puchong, Selangor', 'Net 30'],
            ['SUP-PNG',   'Procter & Gamble (M) Sdn Bhd',   'Aishah Razak',  '+60 3-7100 0003', 'orders@pg.com.my',           'Damansara Heights, 50490 Kuala Lumpur',       'Net 45'],
        ];
        $insSup = db()->prepare(
            'INSERT IGNORE INTO suppliers
              (company_id, code, name, contact_person, phone, email, address, payment_terms, status)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, "ACTIVE")'
        );
        foreach ($suppliers as $s) {
            $insSup->execute(array_merge([$cid], $s));
            $n['suppliers'] += $insSup->rowCount();
        }

        // -------------------- 6. Customers --------------------
        $customers = [
            ['CUST-001', 'Hypermarket KL Sdn Bhd',     'Choo Sok Mei',  '+60 3-2200 0001', 'po@hyperkl.com.my',  'No.88, Jalan Bukit Bintang, 55100 Kuala Lumpur',  'No.88, Jalan Bukit Bintang, 55100 Kuala Lumpur',     'Net 30',  50000],
            ['CUST-002', 'Sundry Shop Subang',         'Raj Kumar',     '+60 12-345 6789', 'rajkumar@gmail.com', 'Lot 23, USJ 9, 47620 Subang Jaya, Selangor',      'Lot 23, USJ 9, 47620 Subang Jaya, Selangor',         'COD',         5000],
            ['CUST-003', 'Office Catering Sdn Bhd',    'Nurul Izzah',   '+60 3-9090 0003', 'orders@oc.my',       'Block C, Wisma Damansara, 50490 Kuala Lumpur',    'Block C, Wisma Damansara, 50490 Kuala Lumpur',      'Net 14',     20000],
        ];
        $insCust = db()->prepare(
            'INSERT IGNORE INTO customers
              (company_id, code, name, contact_person, phone, email,
               billing_address, shipping_address, payment_terms, credit_limit,
               default_tax_group_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "ACTIVE")'
        );
        foreach ($customers as $c) {
            $insCust->execute(array_merge([$cid], $c, [$stdTaxId ?: null]));
            $n['customers'] += $insCust->rowCount();
        }

        // -------------------- 7. Demo user accounts --------------------
        // All share password "ChangeMe!2026" — rotate after demo.
        $demoUsers = [
            ['Mei Lin (Manager)',  'manager@slv.local', 'warehouse_manager', ['WH01','WH02']],
            ['Ahmad (Picker)',     'picker@slv.local',  'picker',            ['WH01']],
            ['Siti (Sales)',       'sales@slv.local',   'sales',             ['WH01','WH02']],
        ];
        $insUser = db()->prepare(
            'INSERT IGNORE INTO users
              (company_id, name, email, password_hash, role, status)
              VALUES (?, ?, ?, ?, ?, "ACTIVE")'
        );
        $insAcc = db()->prepare(
            'INSERT IGNORE INTO user_warehouse_access (user_id, warehouse_id) VALUES (?, ?)'
        );
        foreach ($demoUsers as [$name, $email, $role, $whCodes]) {
            $insUser->execute([$cid, $name, $email, $demoHash, $role]);
            $n['users'] += $insUser->rowCount();

            $look = db()->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $look->execute([$email]);
            $uid = (int)$look->fetchColumn();
            if (!$uid) continue;

            foreach ($whCodes as $wc) {
                $wid = $whIds[$wc] ?? null;
                if ($wid) {
                    $insAcc->execute([$uid, $wid]);
                    $n['access'] += $insAcc->rowCount();
                }
            }
        }

        return $n;
    });
}

/**
 * Pull a SQL result into a key→value map.
 * @return array<string,int>
 */
function demo_lookup_map(string $sql, array $params): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $out[$row[0]] = (int)$row[1];
    }
    return $out;
}

// ------------- View ------------------------------------------------------------
$existing = [
    'warehouses' => (int)db()->query("SELECT COUNT(*) FROM warehouses WHERE company_id = " . company_id())->fetchColumn(),
    'users'      => (int)db()->query("SELECT COUNT(*) FROM users      WHERE company_id = " . company_id())->fetchColumn(),
    'categories' => (int)db()->query("SELECT COUNT(*) FROM categories WHERE company_id = " . company_id())->fetchColumn(),
    'products'   => (int)db()->query("SELECT COUNT(*) FROM products   WHERE company_id = " . company_id())->fetchColumn(),
    'bins'       => (int)db()->query("SELECT COUNT(*) FROM bins
                                        JOIN racks ON racks.id = bins.rack_id
                                        JOIN zones ON zones.id = racks.zone_id
                                        JOIN warehouses w ON w.id = zones.warehouse_id
                                        WHERE w.company_id = " . company_id())->fetchColumn(),
    'suppliers'  => (int)db()->query("SELECT COUNT(*) FROM suppliers  WHERE company_id = " . company_id())->fetchColumn(),
    'customers'  => (int)db()->query("SELECT COUNT(*) FROM customers  WHERE company_id = " . company_id())->fetchColumn(),
];

$PAGE_TITLE   = 'Demo seed';
$SETTINGS_TAB = '';
require __DIR__ . '/../../partials/header.php';
require __DIR__ . '/../../partials/settings_nav.php';
?>
<div class="mb-6">
  <a href="/pages/settings/index.php" class="text-sm text-gray-500 hover:text-gray-900">&larr; Settings</a>
  <h1 class="text-2xl font-semibold text-gray-900 mt-1">Seed demo data</h1>
  <p class="text-sm text-gray-500 mt-1">
    Populates a realistic Malaysian retail-distribution scenario so you can
    walk through the app end-to-end. Safe to run multiple times — existing
    rows are preserved.
  </p>
</div>

<?php if ($summary): ?>
  <div class="mb-6 max-w-2xl border border-green-200 bg-green-50 text-green-800 rounded p-4 text-sm">
    <div class="font-semibold mb-1">Seed completed.</div>
    <div class="grid grid-cols-2 gap-x-6 gap-y-1 mt-2">
      <div>Warehouses created: <strong><?= e_((string)$summary['warehouses']) ?></strong></div>
      <div>Categories created: <strong><?= e_((string)$summary['categories']) ?></strong></div>
      <div>Products created: <strong><?= e_((string)$summary['products']) ?></strong></div>
      <div>Barcodes added: <strong><?= e_((string)$summary['barcodes']) ?></strong></div>
      <div>Zones created: <strong><?= e_((string)$summary['zones']) ?></strong></div>
      <div>Racks created: <strong><?= e_((string)$summary['racks']) ?></strong></div>
      <div>Bins created: <strong><?= e_((string)$summary['bins']) ?></strong></div>
      <div>Suppliers created: <strong><?= e_((string)$summary['suppliers']) ?></strong></div>
      <div>Customers created: <strong><?= e_((string)$summary['customers']) ?></strong></div>
      <div>Users created: <strong><?= e_((string)$summary['users']) ?></strong></div>
      <div>Warehouse grants: <strong><?= e_((string)$summary['access']) ?></strong></div>
    </div>
    <p class="mt-3">Counts of zero on a re-run mean those entities were already present (idempotent).</p>
  </div>
<?php endif; ?>

<section class="bg-white border border-gray-200 rounded-lg p-5 max-w-2xl mb-6">
  <h2 class="text-base font-semibold text-gray-900 mb-3">What this seeds</h2>
  <ul class="text-sm text-gray-700 space-y-1 list-disc list-inside">
    <li><strong>Warehouses</strong>: backfills <code>WH01</code>'s address, adds <code>WH02 — KL Distribution Center</code>.</li>
    <li><strong>Categories</strong>: 3 top-level (Beverages, Snacks, Personal Care) + 4 children.</li>
    <li><strong>Products</strong>: 8 SKUs across categories — Coca-Cola, 7UP, OJ, Lay's, Marie biscuits, Pantene, Dove. Each with one EAN-13 primary barcode and STD tax group.</li>
    <li><strong>Locations</strong>: 5 zones / 7 racks / 14 bins (mostly in WH01, two bins in WH02).</li>
    <li><strong>Suppliers</strong>: Coca-Cola Bottlers, PepsiCo Malaysia, P&amp;G Malaysia.</li>
    <li><strong>Customers</strong>: Hypermarket KL, Sundry Shop Subang, Office Catering.</li>
    <li><strong>Users</strong>: <code>manager@slv.local</code> (warehouse_manager, both warehouses), <code>picker@slv.local</code> (picker, WH01), <code>sales@slv.local</code> (sales, both). All share password <code>ChangeMe!2026</code> &mdash; rotate after the demo.</li>
  </ul>
</section>

<section class="bg-white border border-gray-200 rounded-lg p-5 max-w-2xl mb-6">
  <h2 class="text-base font-semibold text-gray-900 mb-3">Current state</h2>
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
    <?php foreach ($existing as $label => $count): ?>
      <div class="border border-gray-200 rounded p-2">
        <div class="text-gray-500 capitalize"><?= e_($label) ?></div>
        <div class="text-xl font-semibold slv-text-primary"><?= e_((string)$count) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<form method="post" class="max-w-2xl"
      onsubmit="return confirm('Run the demo seed now? This will INSERT IGNORE rows; existing data is preserved.');">
  <?= csrf_field() ?>
  <button type="submit" class="slv-bg-primary text-white px-4 py-2 rounded text-sm font-medium">
    Seed demo data
  </button>
  <a href="/pages/settings/index.php" class="ml-3 px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Cancel</a>
</form>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
