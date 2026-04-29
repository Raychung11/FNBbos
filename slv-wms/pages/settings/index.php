<?php
// SLV WMS — pages/settings/index.php
// Purpose: Settings landing page. Cards link to each sub-section.
// Roles allowed: super_admin
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role('super_admin');

$PAGE_TITLE     = 'Settings';
$SETTINGS_TAB   = 'index';
require __DIR__ . '/../../partials/header.php';
require __DIR__ . '/../../partials/settings_nav.php';

$cards = [
    ['Branding & company',  'Company name, registration, logo, theme colours.', '/pages/settings/branding.php'],
    ['Document numbering',  'Format templates and counters for INV / DO / SO / GRN / PO / TRF / ADJ / CNT.', '/pages/settings/numbering.php'],
    ['Tax codes',           'Define individual taxes (SST 6%, Service Tax, Tourism, etc.).',  '/pages/settings/tax_codes.php'],
    ['Tax groups',          'Bundle one or more tax codes for application to product lines.', '/pages/settings/tax_groups.php'],
    ['Email (SMTP)',        'Outbound mail server for low-stock alerts and digests.',         '/pages/settings/smtp.php'],
    ['Warehouses',          'Add or edit warehouses. Every operational record lives in one.', '/pages/warehouses/index.php'],
    ['Users & access',      'Create staff accounts, assign roles, grant per-warehouse access.','/pages/users/index.php'],
];
?>
<h1 class="text-2xl font-semibold text-gray-900 mb-6">Settings</h1>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
  <?php foreach ($cards as [$title, $desc, $href]): ?>
    <a href="<?= e_($href) ?>"
       class="block bg-white border border-gray-200 rounded-lg p-5 hover:border-indigo-300 hover:shadow-sm transition">
      <div class="font-semibold text-gray-900"><?= e_($title) ?></div>
      <div class="mt-1 text-sm text-gray-500"><?= e_($desc) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
