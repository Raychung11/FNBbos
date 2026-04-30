<?php
// SLV WMS — pages/imports/run.php
// Purpose: Run the next chunk of an import job. AJAX-friendly: when called
//          with ?ajax=1 returns JSON; otherwise serves an HTML status page
//          that auto-advances by reloading itself.
// Roles allowed: super_admin, warehouse_manager
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager']);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Missing import id.');
    redirect('/pages/imports/index.php');
}

$ajax = !empty($_GET['ajax']);

try {
    $job = csv_run_chunk($id);
} catch (Throwable $e) {
    if ($ajax) {
        json_error(500, $e->getMessage());
    }
    flash('error', $e->getMessage());
    redirect('/pages/imports/index.php');
}

$pct = (int)$job['total_rows'] > 0
    ? round(((int)$job['processed_rows'] / (int)$job['total_rows']) * 100, 1)
    : 100;

if ($ajax) {
    json_ok([
        'id'             => (int)$job['id'],
        'status'         => $job['status'],
        'total_rows'     => (int)$job['total_rows'],
        'processed_rows' => (int)$job['processed_rows'],
        'success_rows'   => (int)$job['success_rows'],
        'error_rows'     => (int)$job['error_rows'],
        'percent'        => $pct,
        'done'           => in_array($job['status'], ['COMPLETED','FAILED'], true),
    ]);
}

$PAGE_TITLE = 'Running import';
require __DIR__ . '/../../partials/header.php';
?>
<div class="mb-6">
  <a href="/pages/imports/index.php" class="text-sm text-gray-500 hover:text-gray-900">&larr; CSV imports</a>
  <h1 class="text-2xl font-semibold text-gray-900 mt-1">Running import #<?= e_((string)$job['id']) ?> · <?= e_($job['type']) ?></h1>
</div>

<div class="bg-white border border-gray-200 rounded-lg p-5 max-w-2xl"
     x-data='{
       state: <?= json_encode([
         "status"         => $job["status"],
         "total"          => (int)$job["total_rows"],
         "processed"      => (int)$job["processed_rows"],
         "success"        => (int)$job["success_rows"],
         "errors"         => (int)$job["error_rows"],
         "percent"        => $pct,
       ], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>,
       jobId: <?= (int)$job["id"] ?>,
       loop: false,
       async tick() {
         try {
           const res = await fetch("/pages/imports/run.php?ajax=1&id=" + this.jobId, {credentials:"same-origin"});
           const j   = await res.json();
           if (!j.ok) throw new Error(j.error || "request failed");
           this.state.status    = j.status;
           this.state.processed = j.processed_rows;
           this.state.success   = j.success_rows;
           this.state.errors    = j.error_rows;
           this.state.percent   = j.percent;
           if (!j.done && this.loop) setTimeout(()=>this.tick(), 200);
         } catch (e) {
           this.loop = false;
           alert(e.message);
         }
       },
       start() { this.loop = true; this.tick(); },
       pause() { this.loop = false; }
     }'>
  <div class="flex items-center justify-between mb-3">
    <div class="text-sm text-gray-500">Status</div>
    <div class="text-sm font-semibold" x-text="state.status"></div>
  </div>

  <div class="w-full h-3 bg-gray-100 rounded overflow-hidden mb-2">
    <div class="h-full slv-bg-primary transition-all" :style="`width:${state.percent}%`"></div>
  </div>
  <div class="text-xs text-gray-500 mb-4 flex justify-between">
    <span><span x-text="state.processed"></span> / <span x-text="state.total"></span> rows</span>
    <span><span x-text="state.percent"></span>%</span>
  </div>

  <div class="grid grid-cols-2 gap-3 text-sm mb-4">
    <div class="border border-gray-200 rounded p-3">
      <div class="text-gray-500">Successful</div>
      <div class="text-2xl font-semibold text-green-700" x-text="state.success"></div>
    </div>
    <div class="border border-gray-200 rounded p-3">
      <div class="text-gray-500">Errors</div>
      <div class="text-2xl font-semibold" :class="state.errors>0?'text-red-600':'text-gray-400'" x-text="state.errors"></div>
    </div>
  </div>

  <div class="flex gap-2">
    <button type="button" @click="start()" :disabled="loop || state.status==='COMPLETED' || state.status==='FAILED'"
            class="slv-bg-primary text-white px-4 py-2 rounded text-sm font-medium disabled:opacity-40">
      Process all chunks
    </button>
    <button type="button" @click="pause()" :disabled="!loop"
            class="px-4 py-2 rounded text-sm border border-gray-300 hover:bg-gray-50 disabled:opacity-40">
      Pause
    </button>
    <button type="button" @click="tick()" :disabled="loop || state.status==='COMPLETED' || state.status==='FAILED'"
            class="px-4 py-2 rounded text-sm border border-gray-300 hover:bg-gray-50 disabled:opacity-40">
      Run one chunk
    </button>
    <a href="/pages/imports/index.php" class="ml-auto px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Back</a>
  </div>

  <p class="mt-4 text-xs text-gray-500" x-show="state.status==='COMPLETED'">
    Done. <span x-show="state.errors>0">Some rows failed —
      <a class="underline text-red-600" :href="`/pages/imports/errors.php?id=${jobId}`">download the errors CSV</a>.</span>
  </p>
</div>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
