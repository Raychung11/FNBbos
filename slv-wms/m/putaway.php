<?php
// SLV WMS — m/putaway.php
// Mobile (PWA) putaway scan flow:
//   1. Pick a GRN (RECEIVED or PUTAWAY)
//   2. Scan a SKU — system finds the matching line and shows the
//      suggested bin
//   3. Scan / type the destination bin
//   4. Confirm qty + cost → POST /api/v1/scan/grn_putaway.php
//      Idempotency via client-generated scan_uuid (UUID v4).
//   5. Repeat until done; status flips automatically through PUTAWAY → CLOSED.
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
<title>Putaway · <?= e_($company) ?></title>
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
      x-data="putawayApp()" x-init="loadGrns()">

<header class="slv-bg-secondary text-white px-4 py-2 flex items-center justify-between sticky top-0 z-20">
  <div class="flex items-center gap-2 min-w-0">
    <a href="/m/home.php" class="text-xl leading-none">←</a>
    <div class="min-w-0">
      <div class="text-xs opacity-70">Putaway</div>
      <div class="text-sm font-semibold truncate" x-text="grn ? grn.grn_no : 'Pick a GRN'"></div>
    </div>
  </div>
  <div class="text-xs opacity-80"><?= e_($user['name']) ?></div>
</header>

<div x-show="msg" x-cloak class="px-4 py-2 text-sm"
     :class="msgKind === 'error' ? 'bg-red-50 text-red-800 border-b border-red-200' : 'bg-emerald-50 text-emerald-800 border-b border-emerald-200'"
     x-text="msg" @click="msg=''"></div>

<!-- ─── State 1: GRN picker ─── -->
<main class="p-4 space-y-3" x-show="!grn" x-cloak>
  <p class="text-xs text-gray-500">Pick a received GRN to put away. Only RECEIVED + PUTAWAY GRNs are listed.</p>
  <button @click="loadGrns()" class="w-full text-xs text-indigo-700 underline">Refresh</button>
  <template x-for="g in grns" :key="g.id">
    <button @click="openGrn(g.id)"
            class="block w-full bg-white rounded-lg border border-gray-200 p-3 text-left active:bg-gray-100">
      <div class="flex items-center justify-between">
        <div class="font-mono text-sm" x-text="g.grn_no"></div>
        <span class="text-[11px] uppercase font-semibold px-2 py-0.5 rounded"
              :class="{'bg-cyan-50 text-cyan-700': g.status==='RECEIVED','bg-amber-50 text-amber-700': g.status==='PUTAWAY'}"
              x-text="g.status"></span>
      </div>
      <div class="text-xs text-gray-500 mt-1">
        <span x-text="g.warehouse_code"></span>
        <template x-if="g.supplier_code"> · <span x-text="g.supplier_code"></span></template>
      </div>
      <div class="mt-2 h-1 rounded bg-gray-100 overflow-hidden">
        <div class="h-full slv-bg-primary"
             :style="`width: ${g.qty_received > 0 ? Math.min(100, (g.qty_putaway / g.qty_received) * 100) : 0}%`"></div>
      </div>
      <div class="text-[11px] text-gray-500 mt-1">
        Putaway <span x-text="g.qty_putaway"></span> / <span x-text="g.qty_received"></span>
      </div>
    </button>
  </template>
  <div x-show="grns.length === 0 && !loading" x-cloak class="text-center text-gray-500 text-sm py-8">
    No GRNs ready for putaway. Receive lines first in the desktop or m/receive.
  </div>
</main>

<!-- ─── State 2: working a GRN ─── -->
<main class="p-4 space-y-3" x-show="grn" x-cloak>
  <div class="bg-white border border-gray-200 rounded-lg p-3 flex items-center justify-between text-sm">
    <div>
      <div class="text-xs text-gray-500">Warehouse</div>
      <div class="font-mono" x-text="grn?.warehouse_code"></div>
    </div>
    <button @click="closeGrn()" class="text-xs text-indigo-700 underline">Switch GRN</button>
  </div>

  <!-- STEP 1: scan SKU -->
  <div class="bg-white border border-gray-200 rounded-lg p-3 space-y-2" x-show="!activeLine" x-cloak>
    <div class="text-sm font-semibold text-gray-700">Step 1 · Scan SKU</div>
    <div class="flex gap-2">
      <button @click="toggleScanner('sku')"
              class="flex-1 slv-bg-primary text-white rounded-lg py-3 text-base font-semibold"
              x-text="scannerOn ? 'Stop camera' : 'Scan SKU'"></button>
      <button @click="openManual = !openManual"
              class="px-3 rounded-lg border border-gray-300 text-sm">⌨</button>
    </div>
    <div id="scanner-area" class="rounded overflow-hidden" :class="scannerOn ? 'h-56' : 'h-0'"></div>
    <form x-show="openManual" x-cloak @submit.prevent="lookupSku(manualEntry); manualEntry=''"
          class="flex gap-2">
      <input x-model="manualEntry" placeholder="SKU code or barcode"
             class="flex-1 rounded border-gray-300 text-sm">
      <button class="slv-bg-primary text-white px-3 rounded text-sm">Find</button>
    </form>
  </div>

  <!-- STEP 2: scan bin -->
  <template x-if="activeLine && !activeBin">
    <div class="bg-white border-2 border-indigo-300 rounded-lg p-3 space-y-2">
      <div class="flex items-start justify-between gap-2">
        <div>
          <div class="font-mono text-sm" x-text="activeLine.sku_code"></div>
          <div class="text-xs text-gray-500" x-text="activeLine.product_name"></div>
          <div class="text-xs text-amber-700 mt-1">
            Putaway remaining: <span x-text="activeLine.remaining_putaway"></span>
            <span x-text="activeLine.uom"></span>
          </div>
        </div>
        <button @click="resetLine()" class="text-xs text-gray-500 underline">change SKU</button>
      </div>
      <div x-show="activeLine.suggested_bin" x-cloak class="bg-amber-50 border border-amber-200 rounded p-2 text-xs">
        Suggested bin:
        <span class="font-mono" x-text="activeLine.suggested_bin?.full_code"></span>
        <span class="text-amber-700" x-text="'(' + activeLine.suggested_bin?.reason + ')'"></span>
        <button @click="useSuggestedBin()" class="ml-2 text-indigo-700 underline">use it</button>
      </div>
      <div class="text-sm font-semibold text-gray-700 pt-1">Step 2 · Scan bin</div>
      <div class="flex gap-2">
        <button @click="toggleScanner('bin')"
                class="flex-1 slv-bg-primary text-white rounded-lg py-3 text-base font-semibold"
                x-text="scannerOn ? 'Stop camera' : 'Scan bin'"></button>
        <button @click="openBinManual = !openBinManual"
                class="px-3 rounded-lg border border-gray-300 text-sm">⌨</button>
      </div>
      <form x-show="openBinManual" x-cloak @submit.prevent="lookupBin(manualBin); manualBin=''"
            class="flex gap-2">
        <input x-model="manualBin" placeholder="Bin code (full or short)"
               class="flex-1 rounded border-gray-300 text-sm font-mono">
        <button class="slv-bg-primary text-white px-3 rounded text-sm">Find</button>
      </form>
    </div>
  </template>

  <!-- STEP 3: confirm qty -->
  <template x-if="activeLine && activeBin">
    <div class="bg-white border-2 border-emerald-400 rounded-lg p-3 space-y-2">
      <div class="text-sm font-semibold text-gray-700">Step 3 · Confirm</div>
      <div class="text-sm">
        <span class="font-mono" x-text="activeLine.sku_code"></span>
        → <span class="font-mono" x-text="activeBin.full_code"></span>
      </div>
      <form @submit.prevent="submitPutaway()" class="grid grid-cols-2 gap-2 mt-2">
        <div>
          <label class="block text-xs text-gray-500">Qty</label>
          <input type="number" step="0.0001" min="0" x-model="entryQty" required
                 class="mt-1 w-full rounded border-gray-300 text-base text-right">
        </div>
        <div>
          <label class="block text-xs text-gray-500">Unit cost (optional)</label>
          <input type="number" step="0.0001" min="0" x-model="entryCost"
                 :placeholder="activeLine.unit_cost"
                 class="mt-1 w-full rounded border-gray-300 text-base text-right">
        </div>
        <button type="submit"
                class="col-span-2 slv-bg-primary text-white rounded-lg py-3 text-base font-semibold"
                :disabled="submitting" x-text="submitting ? 'Saving…' : 'Confirm putaway'"></button>
        <button type="button" @click="activeBin = null; openBinManual=false"
                class="col-span-2 text-xs text-gray-500 underline">Wrong bin? rescan</button>
      </form>
    </div>
  </template>

  <!-- Lines list (live progress) -->
  <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mt-4">All lines</h3>
  <template x-for="l in lines" :key="l.id">
    <div class="bg-white border border-gray-200 rounded-lg p-3 text-sm">
      <div class="flex items-start justify-between">
        <div>
          <div class="font-mono" x-text="l.sku_code"></div>
          <div class="text-xs text-gray-500" x-text="l.product_name"></div>
        </div>
        <button @click="setActiveLine(l)"
                x-show="l.remaining_putaway > 0"
                class="text-xs text-indigo-700 underline">putaway</button>
        <span x-show="l.remaining_putaway <= 0"
              class="text-xs text-emerald-700 font-semibold">done</span>
      </div>
      <div class="mt-1 h-1 rounded bg-gray-100 overflow-hidden">
        <div class="h-full"
             :class="l.remaining_putaway <= 0 ? 'bg-emerald-500' : 'slv-bg-primary'"
             :style="`width: ${l.qty_received > 0 ? Math.min(100, (l.qty_putaway / l.qty_received) * 100) : 0}%`"></div>
      </div>
      <div class="text-[11px] text-gray-500 mt-1">
        Putaway <span x-text="l.qty_putaway"></span> / <span x-text="l.qty_received"></span> <span x-text="l.uom"></span>
      </div>
    </div>
  </template>

  <p x-show="grn?.status === 'CLOSED'" x-cloak
     class="text-center text-emerald-700 text-base font-semibold pt-3">
    GRN closed — every received unit is in a bin.
  </p>
</main>

<script>
function uuidv4() {
  // RFC4122-ish; sufficient for client-generated scan_uuid uniqueness.
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = Math.random()*16|0, v = c==='x' ? r : (r&0x3|0x8);
    return v.toString(16);
  });
}

function putawayApp() {
  return {
    loading: false,
    msg: '', msgKind: 'info',
    grns: [], grn: null, lines: [],
    activeLine: null,
    activeBin:  null,
    entryQty:   '',
    entryCost:  '',
    submitting: false,
    scannerOn: false,
    scannerMode: '',          // 'sku' | 'bin'
    openManual: false,
    openBinManual: false,
    manualEntry: '',
    manualBin:   '',

    notify(text, kind) {
      this.msgKind = kind || 'info';
      this.msg = text;
      setTimeout(() => { if (this.msg === text) this.msg = ''; }, 4000);
    },

    async loadGrns() {
      this.loading = true;
      try {
        const data = await SLVScanner.api('/api/v1/scan/grn_list.php?mode=putaway');
        this.grns = data.grns || [];
      } catch (e) { this.notify(e.message, 'error'); }
      this.loading = false;
    },

    async openGrn(id) {
      try {
        const data = await SLVScanner.api('/api/v1/scan/grn_get.php?id=' + id);
        this.grn   = data.grn;
        this.lines = data.lines || [];
        this.resetLine();
      } catch (e) { this.notify(e.message, 'error'); }
    },

    closeGrn() {
      this.stopScanner();
      this.grn = null; this.lines = []; this.resetLine();
      this.loadGrns();
    },

    setActiveLine(line) {
      if (line.remaining_putaway <= 0) return;
      this.activeLine = line;
      this.activeBin  = null;
      this.entryQty   = String(line.remaining_putaway);
      this.entryCost  = '';
      this.openManual = false;
      this.openBinManual = false;
      this.stopScanner();
    },

    resetLine() {
      this.activeLine = null; this.activeBin = null;
      this.entryQty = ''; this.entryCost = '';
    },

    async lookupSku(barcode) {
      barcode = String(barcode || '').trim();
      if (!barcode) return;
      try {
        const data = await SLVScanner.api('/api/v1/scan/sku_lookup.php?barcode=' + encodeURIComponent(barcode));
        const pid  = data.product.id;
        const line = this.lines.find(l => l.product_id === pid && l.remaining_putaway > 0);
        if (!line) {
          this.notify(data.product.sku_code + ' has no remaining putaway on this GRN.', 'error');
          return;
        }
        this.setActiveLine(line);
        this.stopScanner();
      } catch (e) { this.notify(e.message, 'error'); }
    },

    async lookupBin(barcode) {
      barcode = String(barcode || '').trim();
      if (!barcode || !this.activeLine) return;
      try {
        const data = await SLVScanner.api(
          '/api/v1/scan/bin_lookup.php?warehouse_id=' + this.grn.warehouse_id +
          '&barcode=' + encodeURIComponent(barcode)
        );
        this.activeBin = data.bin;
        this.openBinManual = false;
        this.stopScanner();
      } catch (e) { this.notify(e.message, 'error'); }
    },

    useSuggestedBin() {
      if (this.activeLine?.suggested_bin) {
        this.activeBin = this.activeLine.suggested_bin;
      }
    },

    async submitPutaway() {
      if (!this.activeLine || !this.activeBin) return;
      this.submitting = true;
      try {
        const data = await SLVScanner.api('/api/v1/scan/grn_putaway.php', 'POST', {
          grn_item_id: this.activeLine.id,
          bin_id:      this.activeBin.id,
          qty:         parseFloat(this.entryQty)  || 0,
          unit_cost:   this.entryCost === '' ? null : parseFloat(this.entryCost),
          scan_uuid:   uuidv4(),
        });
        this.notify('Putaway recorded.', 'success');
        await this.openGrn(this.grn.id);
      } catch (e) { this.notify(e.message, 'error'); }
      this.submitting = false;
    },

    async toggleScanner(mode) {
      if (this.scannerOn) { this.stopScanner(); return; }
      this.scannerMode = mode || 'sku';
      try {
        await SLVScanner.start('scanner-area', (code) => {
          if (this.scannerMode === 'bin') this.lookupBin(code);
          else                            this.lookupSku(code);
        });
        this.scannerOn = true;
      } catch (e) { this.notify('Camera error: ' + e.message, 'error'); }
    },
    stopScanner() {
      if (this.scannerOn) { SLVScanner.stop(); this.scannerOn = false; }
    },
  };
}

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('/service-worker.js').catch(()=>{}));
}
</script>
</body>
</html>
