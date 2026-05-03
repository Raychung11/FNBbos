<?php
// SLV WMS — partials/header.php
// Purpose: Desktop top-of-page layout. The visible nav is built from a
//          small, role-keyed table so each role sees a clearly distinct
//          set of links — not "the same dropdown with one row missing".
// Roles allowed: any logged-in role
// Last updated: 2026-04-30

if (!isset($PAGE_TITLE)) {
    $PAGE_TITLE = 'SLV WMS';
}
$company   = company_record()['name'] ?? setting('brand.company_name', 'SLV Group Sdn. Bhd.');
$primary   = setting('brand.primary_color', '#6D28D9');
$secondary = setting('brand.secondary_color', '#1F2937');
$accent    = setting('brand.accent_color',  '#F59E0B');
$logoPath  = setting('brand.logo_path', '');
$user      = current_user();
$role      = $user['role'] ?? '';

/**
 * Build the nav for this role. Each entry is either:
 *   ['type' => 'link',     'label' => ..., 'href' => ...]
 *   ['type' => 'dropdown', 'label' => ..., 'items' => [['label'=>...,'href'=>...], ...]]
 */
$NAV = (function(string $role): array {
    switch ($role) {
        case 'super_admin':
            return [
                ['type'=>'link', 'label'=>'Dashboard', 'href'=>'/index.php'],
                ['type'=>'dropdown', 'label'=>'In', 'items'=>[
                    ['label'=>'GRNs',       'href'=>'/pages/grn/index.php'],
                ]],
                ['type'=>'dropdown', 'label'=>'Out', 'items'=>[
                    ['label'=>'Sales orders',    'href'=>'/pages/sales_orders/index.php'],
                    ['label'=>'Pick lists',      'href'=>'/pages/pick_lists/index.php'],
                    ['label'=>'Invoices',        'href'=>'/pages/invoices/index.php'],
                    ['label'=>'Delivery orders', 'href'=>'/pages/delivery_orders/index.php'],
                ]],
                ['type'=>'dropdown', 'label'=>'Master', 'items'=>[
                    ['label'=>'Products / SKUs',     'href'=>'/pages/products/index.php'],
                    ['label'=>'Categories',          'href'=>'/pages/categories/index.php'],
                    ['label'=>'Zones / Racks / Bins','href'=>'/pages/locations/index.php'],
                    ['label'=>'Suppliers',           'href'=>'/pages/suppliers/index.php'],
                    ['label'=>'Customers',           'href'=>'/pages/customers/index.php'],
                    ['_divider'=>true],
                    ['label'=>'CSV imports',         'href'=>'/pages/imports/index.php'],
                ]],
                ['type'=>'link', 'label'=>'Reports',  'href'=>'/pages/reports/index.php'],
                ['type'=>'link', 'label'=>'Settings', 'href'=>'/pages/settings/index.php'],
                ['type'=>'link', 'label'=>'Users',    'href'=>'/pages/users/index.php'],
            ];

        case 'warehouse_manager':
            return [
                ['type'=>'link', 'label'=>'Dashboard', 'href'=>'/index.php'],
                ['type'=>'link', 'label'=>'GRN',       'href'=>'/pages/grn/index.php'],
                ['type'=>'dropdown', 'label'=>'Sales', 'items'=>[
                    ['label'=>'Sales orders',    'href'=>'/pages/sales_orders/index.php'],
                    ['label'=>'Pick lists',      'href'=>'/pages/pick_lists/index.php'],
                    ['label'=>'Invoices',        'href'=>'/pages/invoices/index.php'],
                    ['label'=>'Delivery orders', 'href'=>'/pages/delivery_orders/index.php'],
                ]],
                ['type'=>'dropdown', 'label'=>'Operations', 'items'=>[
                    ['label'=>'Locations',          'href'=>'/pages/locations/index.php'],
                    ['label'=>'Products / SKUs',    'href'=>'/pages/products/index.php'],
                    ['label'=>'Categories',         'href'=>'/pages/categories/index.php'],
                    ['_divider'=>true],
                    ['label'=>'Suppliers',          'href'=>'/pages/suppliers/index.php'],
                    ['label'=>'Customers',          'href'=>'/pages/customers/index.php'],
                ]],
                ['type'=>'link', 'label'=>'Reports', 'href'=>'/pages/reports/index.php'],
            ];

        case 'sales':
            return [
                ['type'=>'link', 'label'=>'Dashboard',     'href'=>'/index.php'],
                ['type'=>'link', 'label'=>'Sales orders',  'href'=>'/pages/sales_orders/index.php'],
                ['type'=>'link', 'label'=>'Invoices',      'href'=>'/pages/invoices/index.php'],
                ['type'=>'link', 'label'=>'Customers',     'href'=>'/pages/customers/index.php'],
                ['type'=>'link', 'label'=>'Products',      'href'=>'/pages/products/index.php'],
                ['type'=>'link', 'label'=>'Reports',       'href'=>'/pages/reports/index.php'],
            ];

        case 'viewer':
            return [
                ['type'=>'link', 'label'=>'Dashboard', 'href'=>'/index.php'],
                ['type'=>'link', 'label'=>'Products',  'href'=>'/pages/products/index.php'],
                ['type'=>'link', 'label'=>'Reports',   'href'=>'/pages/reports/index.php'],
            ];

        // receiver / picker / packer / driver — desktop is a fallback for
        // them, the real surface is /m/home.php. Show the bare minimum.
        default:
            $items = [
                ['type'=>'link', 'label'=>'Dashboard',       'href'=>'/index.php'],
                ['type'=>'link', 'label'=>'Mobile scanner',  'href'=>'/m/home.php'],
            ];
            if ($role === 'receiver') {
                array_splice($items, 1, 0, [
                    ['type'=>'link', 'label'=>'GRN', 'href'=>'/pages/grn/index.php'],
                ]);
            }
            if ($role === 'driver') {
                array_splice($items, 1, 0, [
                    ['type'=>'link', 'label'=>'My deliveries', 'href'=>'/pages/delivery_orders/index.php?mine=1'],
                ]);
            }
            if ($role === 'packer') {
                array_splice($items, 1, 0, [
                    ['type'=>'link', 'label'=>'Pick lists',      'href'=>'/pages/pick_lists/index.php'],
                    ['type'=>'link', 'label'=>'Delivery orders', 'href'=>'/pages/delivery_orders/index.php'],
                ]);
            }
            return $items;
    }
})($role);

// A small chip colour per role so the operator can tell at a glance.
$ROLE_COLOURS = [
    'super_admin'       => 'bg-amber-400  text-amber-950',
    'warehouse_manager' => 'bg-emerald-400 text-emerald-950',
    'sales'             => 'bg-sky-400    text-sky-950',
    'viewer'            => 'bg-gray-300   text-gray-800',
    'picker'            => 'bg-indigo-400 text-indigo-950',
    'packer'            => 'bg-fuchsia-400 text-fuchsia-950',
    'driver'            => 'bg-rose-400   text-rose-950',
    'receiver'          => 'bg-orange-400 text-orange-950',
];
$roleChipClass = $ROLE_COLOURS[$role] ?? 'bg-gray-200 text-gray-700';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?= e_($primary) ?>">
<title><?= e_($PAGE_TITLE) ?> · <?= e_($company) ?></title>
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>
  :root{
    --slv-primary:   <?= e_($primary) ?>;
    --slv-secondary: <?= e_($secondary) ?>;
    --slv-accent:    <?= e_($accent) ?>;
  }
  .slv-bg-primary   { background-color: var(--slv-primary); }
  .slv-bg-secondary { background-color: var(--slv-secondary); }
  .slv-bg-accent    { background-color: var(--slv-accent); }
  .slv-text-primary { color: var(--slv-primary); }
  .slv-border-primary { border-color: var(--slv-primary); }
  .slv-ring-primary:focus { box-shadow: 0 0 0 3px color-mix(in srgb, var(--slv-primary) 35%, transparent); }
  [x-cloak]{display:none !important;}
</style>
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen">
<header class="slv-bg-secondary text-white">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-14 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <a href="/index.php" class="flex items-center gap-2">
        <?php if ($logoPath !== '' && is_file(__DIR__ . '/..' . $logoPath)): ?>
          <img src="<?= e_($logoPath) ?>" alt="<?= e_($company) ?>" class="h-7 w-auto">
        <?php else: ?>
          <span class="inline-flex items-center justify-center w-8 h-8 rounded slv-bg-primary text-white font-bold text-sm">SLV</span>
        <?php endif; ?>
        <span class="font-semibold tracking-tight"><?= e_($company) ?> <span class="opacity-60">WMS</span></span>
      </a>
    </div>

    <nav class="flex items-center gap-4 text-sm">
      <?php foreach ($NAV as $item): ?>
        <?php if ($item['type'] === 'link'): ?>
          <a href="<?= e_($item['href']) ?>" class="hover:text-white/80"><?= e_($item['label']) ?></a>
        <?php else: ?>
          <div x-data="{open:false}" class="relative" @click.outside="open=false">
            <button @click="open=!open" class="hover:text-white/80 inline-flex items-center gap-1">
              <?= e_($item['label']) ?> <span class="opacity-60">▾</span>
            </button>
            <div x-show="open" x-transition x-cloak
                 class="absolute right-0 mt-2 w-56 rounded-md bg-white text-gray-800 shadow-lg ring-1 ring-black/5 z-30">
              <?php foreach ($item['items'] as $sub): ?>
                <?php if (!empty($sub['_divider'])): ?>
                  <hr class="my-1">
                <?php else: ?>
                  <a href="<?= e_($sub['href']) ?>" class="block px-4 py-2 text-sm hover:bg-gray-50"><?= e_($sub['label']) ?></a>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>

      <span class="opacity-30">|</span>

      <!-- Identity chip — bright + role-tinted so you always know who you are. -->
      <div class="flex items-center gap-2">
        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold uppercase tracking-wide <?= $roleChipClass ?>">
          <?= e_($role) ?>
        </span>
        <span class="opacity-80 text-xs"><?= e_($user['name'] ?? '') ?></span>
      </div>

      <a href="/logout.php?next=/login.php" class="hover:text-white/80 underline-offset-2 hover:underline" title="Sign out and pick a different account">
        Switch
      </a>
      <a href="/logout.php" class="hover:text-white/80 underline-offset-2 hover:underline">Sign out</a>
    </nav>
  </div>
</header>
<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
<?php
// Render flash messages once per page.
$_flashes = flash_drain();
foreach ($_flashes as $f):
    $cls = match ($f['type']) {
        'success' => 'bg-green-50 text-green-800 border-green-200',
        'error'   => 'bg-red-50 text-red-800 border-red-200',
        'warn'    => 'bg-amber-50 text-amber-800 border-amber-200',
        default   => 'bg-blue-50 text-blue-800 border-blue-200',
    };
?>
  <div class="mb-4 border <?= $cls ?> rounded px-4 py-3 text-sm">
    <?= e_($f['message']) ?>
  </div>
<?php endforeach; ?>
