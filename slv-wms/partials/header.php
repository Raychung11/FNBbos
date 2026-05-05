<?php
// SLV WMS — partials/header.php
// Purpose: Page chrome — doctype, theme variables, app bar with brand on
//          the left, role-keyed nav on the right (delegated to nav.php),
//          opens <main> and renders flash messages.
//
// File split (so a regression can't bleed across roles via cached HTML):
//   partials/header.php  → this file (brand + theme + opens main)
//   partials/nav.php     → role-keyed top nav, identity chip, sign-out links
//   partials/flash.php   → flash-message rendering
//   partials/footer.php  → closes main + tiny footer line + closes html
//
// Roles allowed: any logged-in role
// Last updated: 2026-04-30

if (!isset($PAGE_TITLE)) {
    $PAGE_TITLE = 'SLV WMS';
}

$company   = company_record()['name'] ?? setting('brand.company_name', 'SLV Group Sdn. Bhd.');
$primary   = setting('brand.primary_color',   '#6D28D9');
$secondary = setting('brand.secondary_color', '#1F2937');
$accent    = setting('brand.accent_color',    '#F59E0B');
$logoPath  = setting('brand.logo_path', '');
$user      = current_user();
$role      = $user['role'] ?? '';

// Role chip colour mapping — keeps each role visually unmistakable.
$ROLE_COLOURS = [
    'super_admin'       => 'bg-amber-400   text-amber-950',
    'warehouse_manager' => 'bg-emerald-400 text-emerald-950',
    'sales'             => 'bg-sky-400     text-sky-950',
    'viewer'            => 'bg-gray-300    text-gray-800',
    'picker'            => 'bg-indigo-400  text-indigo-950',
    'packer'            => 'bg-fuchsia-400 text-fuchsia-950',
    'driver'            => 'bg-rose-400    text-rose-950',
    'receiver'          => 'bg-orange-400  text-orange-950',
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
  :root {
    --slv-primary:   <?= e_($primary) ?>;
    --slv-secondary: <?= e_($secondary) ?>;
    --slv-accent:    <?= e_($accent) ?>;
  }
  .slv-bg-primary    { background-color: var(--slv-primary); }
  .slv-bg-secondary  { background-color: var(--slv-secondary); }
  .slv-bg-accent     { background-color: var(--slv-accent); }
  .slv-text-primary  { color: var(--slv-primary); }
  .slv-border-primary{ border-color: var(--slv-primary); }
  .slv-ring-primary:focus { box-shadow: 0 0 0 3px color-mix(in srgb, var(--slv-primary) 35%, transparent); }
  [x-cloak] { display: none !important; }
</style>
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen">
<header class="slv-bg-secondary text-white">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-14 flex items-center justify-between">

    <!-- Brand (left) -->
    <a href="/index.php" class="flex items-center gap-2">
      <?php if ($logoPath !== '' && is_file(__DIR__ . '/..' . $logoPath)): ?>
        <img src="<?= e_($logoPath) ?>" alt="<?= e_($company) ?>" class="h-7 w-auto">
      <?php else: ?>
        <span class="inline-flex items-center justify-center w-8 h-8 rounded slv-bg-primary text-white font-bold text-sm">SLV</span>
      <?php endif; ?>
      <span class="font-semibold tracking-tight">
        <?= e_($company) ?> <span class="opacity-60">WMS</span>
      </span>
    </a>

    <!-- Role-keyed nav (right) — delegated to its own partial. -->
    <?php require __DIR__ . '/nav.php'; ?>

  </div>
</header>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
<?php require __DIR__ . '/flash.php'; ?>
