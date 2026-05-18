<?php
// SLV WMS — partials/flash.php
// Purpose: Drain & render flash messages once per page. Included by header.php
//          right after <main> opens so messages show above the page heading.
//
// Drained on render — same flash never shows twice.
?>
<?php
$_flashes = flash_drain();
foreach ($_flashes as $f):
    $cls = match ($f['type']) {
        'success' => 'bg-green-50 text-green-800 border-green-200',
        'error'   => 'bg-red-50   text-red-800   border-red-200',
        'warn'    => 'bg-amber-50 text-amber-800 border-amber-200',
        default   => 'bg-blue-50  text-blue-800  border-blue-200',
    };
?>
  <div class="mb-4 border <?= $cls ?> rounded px-4 py-3 text-sm">
    <?= e_($f['message']) ?>
  </div>
<?php endforeach; ?>
