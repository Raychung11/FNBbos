<?php
// SLV WMS — partials/settings_nav.php
// Purpose: Tab strip used by every page under /pages/settings/*.
//          The active tab key is supplied by the including page via
//          $SETTINGS_TAB.
// Last updated: 2026-04-29

$tabs = [
    'branding'   => ['Branding',          '/pages/settings/branding.php'],
    'numbering'  => ['Numbering',         '/pages/settings/numbering.php'],
    'tax_codes'  => ['Tax codes',         '/pages/settings/tax_codes.php'],
    'tax_groups' => ['Tax groups',        '/pages/settings/tax_groups.php'],
    'smtp'       => ['Email (SMTP)',      '/pages/settings/smtp.php'],
];
$active = $SETTINGS_TAB ?? '';
?>
<nav class="mb-6 border-b border-gray-200">
  <ul class="flex flex-wrap gap-1 text-sm">
    <li>
      <a href="/pages/settings/index.php"
         class="inline-block px-3 py-2 rounded-t <?= $active === 'index' ? 'slv-bg-primary text-white' : 'text-gray-600 hover:text-gray-900' ?>">
        Overview
      </a>
    </li>
    <?php foreach ($tabs as $key => [$label, $href]): ?>
      <li>
        <a href="<?= e_($href) ?>"
           class="inline-block px-3 py-2 rounded-t <?= $active === $key ? 'slv-bg-primary text-white' : 'text-gray-600 hover:text-gray-900' ?>">
          <?= e_($label) ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</nav>
