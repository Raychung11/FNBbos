<?php
// SLV WMS — partials/header.php
// Purpose: Desktop top-of-page layout. Tailwind + Alpine via CDN. Branding
//          colours read from app_settings.
// Roles allowed: any logged-in role
// Last updated: 2026-04-29

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
      <a href="/index.php" class="hover:text-white/80">Dashboard</a>

      <div x-data="{open:false}" class="relative" @click.outside="open=false">
        <button @click="open=!open" class="hover:text-white/80 inline-flex items-center gap-1">
          Master <span class="opacity-60">▾</span>
        </button>
        <div x-show="open" x-transition x-cloak
             class="absolute right-0 mt-2 w-56 rounded-md bg-white text-gray-800 shadow-lg ring-1 ring-black/5 z-30">
          <a href="/pages/products/index.php"   class="block px-4 py-2 text-sm hover:bg-gray-50">Products / SKUs</a>
          <a href="/pages/categories/index.php" class="block px-4 py-2 text-sm hover:bg-gray-50">Categories</a>
          <a href="/pages/locations/index.php"  class="block px-4 py-2 text-sm hover:bg-gray-50">Zones / Racks / Bins</a>
          <a href="/pages/suppliers/index.php"  class="block px-4 py-2 text-sm hover:bg-gray-50">Suppliers</a>
          <a href="/pages/customers/index.php"  class="block px-4 py-2 text-sm hover:bg-gray-50">Customers</a>
          <?php if ($role === 'super_admin'): ?>
            <hr class="my-1">
            <a href="/pages/imports/index.php"  class="block px-4 py-2 text-sm hover:bg-gray-50">CSV imports</a>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($role === 'super_admin'): ?>
        <a href="/pages/settings/index.php" class="hover:text-white/80">Settings</a>
        <a href="/pages/users/index.php" class="hover:text-white/80">Users</a>
      <?php endif; ?>
      <span class="opacity-40">|</span>
      <span class="opacity-80"><?= e_($user['name'] ?? '') ?> <span class="opacity-60">(<?= e_($role) ?>)</span></span>
      <a href="/logout.php" class="hover:text-white/80 underline-offset-2 hover:underline">Sign out</a>
    </nav>
    <style>[x-cloak]{display:none !important;}</style>
  </div>
</header>
<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
<?php
// Render flash messages once per page
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
