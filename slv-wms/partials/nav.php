<?php
// SLV WMS — partials/nav.php
// Purpose: The role-keyed top navigation. The ONLY partial that contains
//          role-specific markup. Keeping it isolated means a regression in
//          one role's nav can't bleed into another role's cached HTML —
//          which was the chain that produced the v0.7.0 stale-cache bug.
//
// Expects $user + $role + $roleChipClass to be set by the caller (header.php).
?>
<nav class="flex items-center gap-4 text-sm">
  <?php
  /**
   * Build the nav for this role. Each entry is either:
   *   ['type' => 'link',     'label' => ..., 'href' => ...]
   *   ['type' => 'dropdown', 'label' => ..., 'items' => [['label'=>...,'href'=>...], ...]]
   */
  $NAV = (function (string $role): array {
      switch ($role) {
          case 'super_admin':
              return [
                  ['type' => 'link', 'label' => 'Dashboard', 'href' => '/index.php'],
                  ['type' => 'dropdown', 'label' => 'In', 'items' => [
                      ['label' => 'GRNs',       'href' => '/pages/grn/index.php'],
                  ]],
                  ['type' => 'dropdown', 'label' => 'Out', 'items' => [
                      ['label' => 'Sales orders',    'href' => '/pages/sales_orders/index.php'],
                      ['label' => 'Pick lists',      'href' => '/pages/pick_lists/index.php'],
                      ['label' => 'Invoices',        'href' => '/pages/invoices/index.php'],
                      ['label' => 'Delivery orders', 'href' => '/pages/delivery_orders/index.php'],
                  ]],
                  ['type' => 'dropdown', 'label' => 'Master', 'items' => [
                      ['label' => 'Products / SKUs',     'href' => '/pages/products/index.php'],
                      ['label' => 'Categories',          'href' => '/pages/categories/index.php'],
                      ['label' => 'Zones / Racks / Bins','href' => '/pages/locations/index.php'],
                      ['label' => 'Suppliers',           'href' => '/pages/suppliers/index.php'],
                      ['label' => 'Customers',           'href' => '/pages/customers/index.php'],
                      ['_divider' => true],
                      ['label' => 'CSV imports',         'href' => '/pages/imports/index.php'],
                  ]],
                  ['type' => 'link', 'label' => 'Reports',  'href' => '/pages/reports/index.php'],
                  ['type' => 'link', 'label' => 'Settings', 'href' => '/pages/settings/index.php'],
                  ['type' => 'link', 'label' => 'Users',    'href' => '/pages/users/index.php'],
              ];

          case 'warehouse_manager':
              return [
                  ['type' => 'link', 'label' => 'Dashboard', 'href' => '/index.php'],
                  ['type' => 'link', 'label' => 'GRN',       'href' => '/pages/grn/index.php'],
                  ['type' => 'dropdown', 'label' => 'Sales', 'items' => [
                      ['label' => 'Sales orders',    'href' => '/pages/sales_orders/index.php'],
                      ['label' => 'Pick lists',      'href' => '/pages/pick_lists/index.php'],
                      ['label' => 'Invoices',        'href' => '/pages/invoices/index.php'],
                      ['label' => 'Delivery orders', 'href' => '/pages/delivery_orders/index.php'],
                  ]],
                  ['type' => 'dropdown', 'label' => 'Operations', 'items' => [
                      ['label' => 'Locations',       'href' => '/pages/locations/index.php'],
                      ['label' => 'Products / SKUs', 'href' => '/pages/products/index.php'],
                      ['label' => 'Categories',      'href' => '/pages/categories/index.php'],
                      ['_divider' => true],
                      ['label' => 'Suppliers',       'href' => '/pages/suppliers/index.php'],
                      ['label' => 'Customers',       'href' => '/pages/customers/index.php'],
                  ]],
                  ['type' => 'link', 'label' => 'Reports', 'href' => '/pages/reports/index.php'],
              ];

          case 'sales':
              return [
                  ['type' => 'link', 'label' => 'Dashboard',     'href' => '/index.php'],
                  ['type' => 'link', 'label' => 'Sales orders',  'href' => '/pages/sales_orders/index.php'],
                  ['type' => 'link', 'label' => 'Invoices',      'href' => '/pages/invoices/index.php'],
                  ['type' => 'link', 'label' => 'Customers',     'href' => '/pages/customers/index.php'],
                  ['type' => 'link', 'label' => 'Products',      'href' => '/pages/products/index.php'],
                  ['type' => 'link', 'label' => 'Reports',       'href' => '/pages/reports/index.php'],
              ];

          case 'viewer':
              return [
                  ['type' => 'link', 'label' => 'Dashboard', 'href' => '/index.php'],
                  ['type' => 'link', 'label' => 'Products',  'href' => '/pages/products/index.php'],
                  ['type' => 'link', 'label' => 'Reports',   'href' => '/pages/reports/index.php'],
              ];

          // receiver / picker / packer / driver — desktop is a fallback for
          // them; the real surface is /m/home.php. Show only what they need.
          default:
              $items = [
                  ['type' => 'link', 'label' => 'Dashboard',      'href' => '/index.php'],
                  ['type' => 'link', 'label' => 'Mobile scanner', 'href' => '/m/home.php'],
              ];
              if ($role === 'receiver') {
                  array_splice($items, 1, 0, [
                      ['type' => 'link', 'label' => 'GRN', 'href' => '/pages/grn/index.php'],
                  ]);
              }
              if ($role === 'driver') {
                  array_splice($items, 1, 0, [
                      ['type' => 'link', 'label' => 'My deliveries', 'href' => '/pages/delivery_orders/index.php?mine=1'],
                  ]);
              }
              if ($role === 'packer') {
                  array_splice($items, 1, 0, [
                      ['type' => 'link', 'label' => 'Pick lists',      'href' => '/pages/pick_lists/index.php'],
                      ['type' => 'link', 'label' => 'Delivery orders', 'href' => '/pages/delivery_orders/index.php'],
                  ]);
              }
              return $items;
      }
  })($role);
  ?>

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
