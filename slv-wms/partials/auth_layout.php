<?php
// SLV WMS — partials/auth_layout.php
// Purpose: Shared split-screen shell for login.php / forgot.php / reset.php.
//          The including page sets:
//            $PAGE_TITLE   string
//            $AUTH_HEADING string  (right column heading)
//            $AUTH_SUB     string  (right column sub-heading)
//          and then echos the form HTML between
//            require auth_layout_open.php  ... markup ...  require auth_layout_close.php
// Last updated: 2026-04-30

if (!isset($PAGE_TITLE))   $PAGE_TITLE   = 'Sign in';
if (!isset($AUTH_HEADING)) $AUTH_HEADING = 'Sign in';
if (!isset($AUTH_SUB))     $AUTH_SUB     = '';

$company   = company_record()['name'] ?? setting('brand.company_name', 'SLV Group Sdn. Bhd.');
$primary   = setting('brand.primary_color',   '#6D28D9');
$secondary = setting('brand.secondary_color', '#1F2937');
$accent    = setting('brand.accent_color',    '#F59E0B');
$logoPath  = setting('brand.logo_path', '');
$logoAbs   = $logoPath !== '' ? dirname(__DIR__) . $logoPath : '';
$hasLogo   = $logoPath !== '' && is_file($logoAbs);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e_($primary) ?>">
<title><?= e_($PAGE_TITLE) ?> · <?= e_($company) ?></title>
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
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
  .slv-grad {
    background: linear-gradient(135deg, var(--slv-secondary) 0%,
                color-mix(in srgb, var(--slv-secondary) 60%, var(--slv-primary)) 100%);
  }
  [x-cloak]{display:none !important;}
</style>
</head>
<body class="min-h-screen bg-gray-50 text-gray-900">
<div class="min-h-screen grid grid-cols-1 lg:grid-cols-5">

  <!-- Brand panel -->
  <aside class="hidden lg:flex lg:col-span-2 slv-grad text-white p-10 flex-col justify-between">
    <div>
      <?php if ($hasLogo): ?>
        <img src="<?= e_($logoPath) ?>" alt="<?= e_($company) ?>" class="h-12 w-auto bg-white/10 rounded p-2">
      <?php else: ?>
        <span class="inline-flex items-center justify-center w-14 h-14 rounded-lg slv-bg-primary text-white font-bold text-xl">SLV</span>
      <?php endif; ?>
      <h1 class="mt-6 text-2xl font-semibold leading-tight"><?= e_($company) ?></h1>
      <p class="mt-1 text-white/70 text-sm">Warehouse Management System</p>
    </div>

    <div class="text-white/85 text-sm space-y-3 leading-relaxed">
      <p>Real-time inventory across multiple warehouses, FIFO valuation,
         and one-handed barcode scanning on any phone.</p>
      <ul class="space-y-1 text-white/70">
        <li>• Receive → putaway → pick → pack → ship</li>
        <li>• Inter-warehouse transfers with in-transit state</li>
        <li>• Cycle counts &amp; manager-approved adjustments</li>
        <li>• Multi-tax invoices and A4 PDFs</li>
      </ul>
    </div>

    <div class="text-xs text-white/50">© <?= e_(date('Y')) ?> <?= e_($company) ?></div>
  </aside>

  <!-- Form panel -->
  <main class="col-span-1 lg:col-span-3 flex items-start lg:items-center justify-center p-6 sm:p-10">
    <div class="w-full max-w-md">
      <!-- Mobile-only branding -->
      <div class="lg:hidden text-center mb-6">
        <?php if ($hasLogo): ?>
          <img src="<?= e_($logoPath) ?>" alt="<?= e_($company) ?>" class="h-10 mx-auto">
        <?php else: ?>
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-lg slv-bg-primary text-white font-bold">SLV</div>
        <?php endif; ?>
        <h1 class="mt-3 text-base font-semibold"><?= e_($company) ?></h1>
        <p class="text-xs text-gray-500">Warehouse Management System</p>
      </div>

      <h2 class="text-2xl font-semibold text-gray-900"><?= e_($AUTH_HEADING) ?></h2>
      <?php if ($AUTH_SUB !== ''): ?>
        <p class="mt-1 text-sm text-gray-500"><?= e_($AUTH_SUB) ?></p>
      <?php endif; ?>

      <div class="mt-6">
