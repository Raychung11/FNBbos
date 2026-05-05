<?php
// SLV WMS — m/receive.php
// Mobile (PWA) goods-receipt scan flow:
//   1. Pick a GRN from the list
//   2. Scan a SKU (camera or manual)
//   3. Enter qty + actual unit cost
//   4. Submit → POST /api/v1/scan/grn_receive.php
//   5. Repeat for the next SKU; line list shows live progress.
//
// Roles allowed: super_admin, warehouse_manager, receiver
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','receiver']);

$user      = current_user();
$company   = company_record()['name'] ?? setting('brand.company_name', 'SLV Group Sdn. Bhd.');
$primary   = setting('brand.primary_color', '#6D28D9');
$secondary = setting('brand.secondary_color', '#1F2937');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e_($primary) ?>">
<title>Receive · <?= e_($company) ?></title>
<link rel="manifest" href="/manifest.json">
<script src="https://cdn.tailwindcss.com"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>window.SLV_CSRF = "<?= e_(csrf_token()) ?>";</script>
<script src="/assets/js/scanner.js"></script>
<style>
  :root{--slv-primary:<?= e_($primary) ?>;--slv-secondary:<?= e_($secondary) ?>;}
  .slv-bg-primary{background:var(--slv-primary);}
  .slv-bg-secondary{background:var(--slv-secondary);}
  [x-cloak]{display:none !important;}
  #scanner-area video {object-fit:cover;}
</style>
</head>
<body class="min-h-screen bg-gray-50 text-gray-900"
      x-data="receiveApp()" x-init="loadGrns()">

<!-- Top bar -->
<header class="slv-bg-secondary text-white px-4 py-2 flex items-center justify-between sticky top-0 z-20">
  <div class="flex items-center gap-2 min-w-0">
    <a href="/m/home.php" class="text-xl leading-none">←</a>
    <div class="min-w-0">
      <div class="text-xs opacity-70">Receive</div>
      <div class="text-sm font-semibold truncate" x-text="grn ? grn.grn_no : 'Pick a GRN'"></div>
    </div>
  </div>
  <div class="text-xs opacity-80"><?= e_($user['name']) ?></div>
</header>

<!-- Error/info banner -->
<div x-show="msg" x-cloak class="px-4 py-2 text-sm"
     :class="msgKind === 'error' ? 'bg-red-50 text-red-800 border-b border-red-200' : 'bg-emerald-50 text-emerald-800 border-b border-emerald-200'"
     x-text="msg" @click="msg=''"></div>

<!-- ─── State 1: GRN picker ─── -->
<main class="p-4 space-y-3" x-show="!grn" x-cloak>
  <p class="text-xs text-gray-500">Pick a GRN to receive against. List refreshes when you arrive on this page.</p>
  <button @click="loadGrns()" class="w-full text-xs text-indigo-700 underline">Refresh</button>
  <template x-for="g in grns" :key="g.id">
    <button @click="openGrn(g.id)"
            class="block w-full bg-white rounded-lg border border-gray-200 p-3 text-left active:bg-gray-100">
      <div class="flex items-center justify-between">
        <div class="font-mono text-sm" x-text="g.grn_no"></div>
        <span class="text-[11px] uppercase font-semibold px-2 py-0.5 rounded"
              :class="{
                'bg-gray-100 text-gray-700': g.status==='DRAFT',
                'bg-blue-50  text-blue-700': g.status==='RECEIVING',
                'bg-cyan-50  text-cyan-700': g.status==='RECEIVED',
              }" x-text="g.status"></span>
      </div>
      <div class="text-xs text-gray-500 mt-1">
        <span x-text="g.warehouse_code"></span>
        <template x-if="g.supplier_code"> · <span x-text="g.supplier_code"></span></template>
        · <span x-text="g.line_count"></span> line(s)
      </div>
      <div class="mt-2 h-1 rounded bg-gray-100 overflow-hidden">
        <div class="h-full slv-bg-primary"
             :style="`width: ${g.qty_expected > 0 ? Math.min(100, (g.qty_received / g.qty_expected) * 100) : 0}%`"></div>
      </div>
      <div class="text-[11px] text-gray-500 mt-1">
        Received <span x-text="g.qty_received"></span> / <span x-text="g.qty_expected"></span>
      </div>
    </button>
  </template>
  <div x-show="grns.length === 0 && !loading" x-cloak class="text-center text-gray-500 text-sm py-8">
    No GRNs available to receive. Create one from the desktop.
  </div>
</main>

<!-- ─── State 2: working a GRN ─── -->
<main class="p-4 space-y-3" x-show="grn" x-cloak>
  <!-- Header card -->
  <div class="bg-white border border-gray-200 rounded-lg p-3 flex items-center justify-between text-sm">
    <div>
      <div class="text-xs text-gray-500">Warehouse</div>
      <div class="font-mono" x-text="grn?.warehouse_code"></div>
    </div>
    <button @click="closeGrn()" class="text-xs text-indigo-700 underline">Switch GRN</button>
  </div>

  <!-- Scan / lookup row -->
  <div class="bg-white border border-gray-200 rounded-lg p-3 space-y-2">
    <div class="flex gap-2">
      <button @click="toggleScanner()"
              class="flex-1 slv-bg-primary text-white rounded-lg py-3 text-base font-semibold"
              x-text="scannerOn ? 'Stop camera' : 'Scan SKU'"></button>
      <button @click="openManual = !openManual"
              class="px-3 rounded-lg border border-gray-300 text-sm">⌨</button>
    </div>
    <div id="scanner-area" class="rounded overflow-hidden" :class="scannerOn ? 'h-56' : 'h-0'"></div>
    <form x-show="openManual" x-cloak @submit.prevent="lookupSku(manualEntry); manualEntry=''"
          class="flex gap-2">
      <input x-model="manualEntry" placeholder="Type or paste a barcode / SKU"
             class="flex-1 rounded border-gray-300 text-sm" autofocus>
      <button class="slv-bg-primary text-white px-3 rounded text-sm">Find</button>
    </form>
  </div>

  <!-- Active line card -->
  <template x-if="activeLine">
    <div class="bg-white border-2 border-indigo-300 rounded-lg p-3">
      <div class="flex items-start justify-between gap-2">
        <div>
          <div class="font-mono text-sm" x-text="activeLine.sku_code"></div>
          <div class="text-xs text-gray-500" x-text="activeLine.product_name"></div>
        </div>
        <button @click="activeLine = null" class="text-xs text-gray-500 underline">change</button>
      </div>
      <div class="grid grid-cols-3 gap-2 text-xs text-gray-600 my-2">
        <div>Expected<br><span class="text-base font-semibold text-gray-900" x-text="activeLine.qty_expected"></span></div>
        <div>Received<br><span class="text-base font-semibold text-gray-900" x-text="activeLine.qty_received"></span></div>
        <div>UoM<br><span class="text-base font-semibold text-gray-900" x-text="activeLine.uom"></span></div>
      </div>
      <form @submit.prevent="submitReceive()" class="grid grid-cols-2 gap-2 mt-2">
        <div>
          <label class="block text-xs text-gray-500">New received qty</label>
          <input type="number" step="0.0001" min="0" x-model="entryQty" required
                 class="mt-1 w-full rounded border-gray-300 text-base text-right" autofocus>
        </div>
        <div>
          <label class="block text-xs text-gray-500">Unit cost (RM)</label>
          <input type="number" step="0.0001" min="0" x-model="entryCost" required
                 class="mt-1 w-full rounded border-gray-300 text-base text-right">
        </div>
        <button type="submit"
                class="col-span-2 slv-bg-primary text-white rounded-lg py-3 text-base font-semibold"
                :disabled="submitting" x-text="submitting ? 'Saving…' : 'Confirm received'"></button>
      </form>
    </div>
  </template>

  <!-- Lines list (read-only progress) -->
  <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mt-4">All lines</h3>
  <template x-for="l in lines" :key="l.id">
    <div class="bg-white border border-gray-200 rounded-lg p-3 text-sm">
      <div class="flex items-start justify-between">
        <div>
          <div class="font-mono" x-text="l.sku_code"></div>
          <div class="text-xs text-gray-500" x-text="l.product_name"></div>
        </div>
        <button @click="setActive(l)" class="text-xs text-indigo-700 underline">edit</button>
      </div>
      <div class="mt-1 h-1 rounded bg-gray-100 overflow-hidden">
        <div class="h-full"
             :class="l.qty_received >= l.qty_expected ? 'bg-emerald-500' : 'slv-bg-primary'"
             :style="`width: ${l.qty_expected > 0 ? Math.min(100, (l.qty_received / l.qty_expected) * 100) : 0}%`"></div>
      </div>
      <div class="text-[11px] text-gray-500 mt-1">
        <span x-text="l.qty_received"></span> / <span x-text="l.qty_expected"></span>
        <span x-text="l.uom"></span>
        · @ RM <span x-text="(l.unit_cost || 0).toFixed(4)"></span>
      </div>
    </div>
  </template>

  <p x-show="grn?.status === 'RECEIVED'" x-cloak
     class="text-center text-emerald-700 text-sm font-medium pt-2">
    All lines fully received. Switch to Putaway when ready.
  </p>
</main>

<script>
function receiveApp() {
  return {
    loading: false,
    msg: '', msgKind: 'info',
    grns: [],
    grn: null,        // currently-open GRN object (header)
    lines: [],        // its lines
    activeLine: null,
    entryQty: '',
    entryCost: '',
    submitting: false,
    scannerOn: false,
    openManual: false,
    manualEntry: '',

    notify(text, kind) {
      this.msgKind = kind || 'info';
      this.msg = text;
      setTimeout(() => { if (this.msg === text) this.msg = ''; }, 4000);
    },

    async loadGrns() {
      this.loading = true;
      try {
        const data = await SLVScanner.api('/api/v1/scan/grn_list.php?mode=receive');
        this.grns = data.grns || [];
      } catch (e) { this.notify(e.message, 'error'); }
      this.loading = false;
    },

    async openGrn(id) {
      try {
        const data = await SLVScanner.api('/api/v1/scan/grn_get.php?id=' + id);
        this.grn   = data.grn;
        this.lines = data.lines || [];
        this.activeLine = null;
        this.openManual = false;
      } catch (e) { this.notify(e.message, 'error'); }
    },

    closeGrn() {
      this.stopScanner();
      this.grn = null; this.lines = []; this.activeLine = null;
      this.loadGrns();
    },

    setActive(line) {
      this.activeLine = line;
      this.entryQty   = String(line.qty_received || line.qty_expected || '');
      this.entryCost  = String(line.unit_cost || '');
      this.openManual = false;
      this.stopScanner();
    },

    async lookupSku(barcode) {
      barcode = String(barcode || '').trim();
      if (!barcode) return;
      try {
        const data = await SLVScanner.api('/api/v1/scan/sku_lookup.php?barcode=' + encodeURIComponent(barcode));
        const pid  = data.product.id;
        const line = this.lines.find(l => l.product_id === pid);
        if (!line) {
          this.notify(data.product.sku_code + ' is not on this GRN.', 'error');
          return;
        }
        this.setActive(line);
      } catch (e) { this.notify(e.message, 'error'); }
    },

    async submitReceive() {
      if (!this.activeLine) return;
      this.submitting = true;
      try {
        const data = await SLVScanner.api('/api/v1/scan/grn_receive.php', 'POST', {
          grn_id:       this.grn.id,
          line_id:      this.activeLine.id,
          qty_received: parseFloat(this.entryQty)  || 0,
          unit_cost:    parseFloat(this.entryCost) || 0,
        });
        // Reload the GRN so totals + status are fresh.
        await this.openGrn(this.grn.id);
        this.activeLine = null;
        this.entryQty = ''; this.entryCost = '';
        this.notify('Received recorded.', 'success');
      } catch (e) { this.notify(e.message, 'error'); }
      this.submitting = false;
    },

    async toggleScanner() {
      if (this.scannerOn) { this.stopScanner(); return; }
      try {
        await SLVScanner.start('scanner-area', (code) => this.lookupSku(code));
        this.scannerOn = true;
      } catch (e) { this.notify('Camera error: ' + e.message, 'error'); }
    },
    stopScanner() {
      if (this.scannerOn) { SLVScanner.stop(); this.scannerOn = false; }
    },
  };
}

</script>
<?php require __DIR__ . '/../partials/sw_bootstrap.php'; ?>
</body>
</html>
