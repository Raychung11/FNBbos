<?php
// SLV WMS — m/pick.php
// Mobile (PWA) picking scan flow:
//   1. Pick a pick list (DRAFT / ASSIGNED / IN_PROGRESS)
//   2. Walk to the next un-picked line (suggested bin highlighted)
//   3. Scan the bin (or type it)
//   4. Scan the SKU (verifies it matches the line — wrong product → error)
//   5. Confirm qty, submit → POST /api/v1/scan/pick_execute.php
//      Idempotent via UUID-v4 scan_uuid — the same uuid replayed is a no-op.
//   6. Status flips DRAFT → IN_PROGRESS → COMPLETED automatically; the
//      parent SO flips PICKING → PICKED on the last line.
//
// Roles allowed: super_admin, warehouse_manager, picker, packer
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','picker','packer']);

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
<title>Pick · <?= e_($company) ?></title>
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
      x-data="pickApp()" x-init="loadLists()">

<header class="slv-bg-secondary text-white px-4 py-2 flex items-center justify-between sticky top-0 z-20">
  <div class="flex items-center gap-2 min-w-0">
    <a href="/m/home.php" class="text-xl leading-none">←</a>
    <div class="min-w-0">
      <div class="text-xs opacity-70">Pick</div>
      <div class="text-sm font-semibold truncate" x-text="pl ? pl.pick_no : 'Pick a list'"></div>
    </div>
  </div>
  <div class="text-xs opacity-80"><?= e_($user['name']) ?></div>
</header>

<div x-show="msg" x-cloak class="px-4 py-2 text-sm"
     :class="msgKind === 'error' ? 'bg-red-50 text-red-800 border-b border-red-200' : 'bg-emerald-50 text-emerald-800 border-b border-emerald-200'"
     x-text="msg" @click="msg=''"></div>

<!-- ─── State 1: pick-list picker ─── -->
<main class="p-4 space-y-3" x-show="!pl" x-cloak>
  <p class="text-xs text-gray-500">Pick a pick list to work. List refreshes when you arrive on this page.</p>
  <button @click="loadLists()" class="w-full text-xs text-indigo-700 underline">Refresh</button>
  <template x-for="g in lists" :key="g.id">
    <button @click="openList(g.id)"
            class="block w-full bg-white rounded-lg border border-gray-200 p-3 text-left active:bg-gray-100">
      <div class="flex items-center justify-between">
        <div class="font-mono text-sm" x-text="g.pick_no"></div>
        <span class="text-[11px] uppercase font-semibold px-2 py-0.5 rounded"
              :class="{
                'bg-gray-100 text-gray-700': g.status==='DRAFT',
                'bg-blue-50  text-blue-700': g.status==='ASSIGNED',
                'bg-amber-50 text-amber-700': g.status==='IN_PROGRESS',
              }" x-text="g.status"></span>
      </div>
      <div class="text-xs text-gray-500 mt-1">
        <span x-text="g.warehouse_code"></span>
        · SO <span x-text="g.so_no"></span>
        · <span x-text="g.customer_code"></span>
      </div>
      <div class="mt-2 h-1 rounded bg-gray-100 overflow-hidden">
        <div class="h-full slv-bg-primary"
             :style="`width: ${g.qty_total > 0 ? Math.min(100, (g.qty_done / g.qty_total) * 100) : 0}%`"></div>
      </div>
      <div class="text-[11px] text-gray-500 mt-1">
        <span x-text="g.qty_done"></span> / <span x-text="g.qty_total"></span> picked
        · <span x-text="g.line_count"></span> line(s)
      </div>
    </button>
  </template>
  <div x-show="lists.length === 0 && !loading" x-cloak class="text-center text-gray-500 text-sm py-8">
    No pick lists waiting. Confirm a sales order from desktop and generate a pick list.
  </div>
</main>

<!-- ─── State 2: working a pick list ─── -->
<main class="p-4 space-y-3" x-show="pl" x-cloak>
  <div class="bg-white border border-gray-200 rounded-lg p-3 flex items-center justify-between text-sm">
    <div>
      <div class="text-xs text-gray-500">Warehouse</div>
      <div class="font-mono" x-text="pl?.warehouse_code"></div>
    </div>
    <button @click="closeList()" class="text-xs text-indigo-700 underline">Switch list</button>
  </div>

  <!-- Active line card -->
  <template x-if="activeItem">
    <div class="bg-white border-2 border-indigo-300 rounded-lg p-3 space-y-2">
      <div class="flex items-start justify-between gap-2">
        <div>
          <div class="text-xs text-gray-500">
            Line <span x-text="activeItem.walk_order"></span> of <span x-text="items.length"></span>
          </div>
          <div class="font-mono text-sm" x-text="activeItem.sku_code"></div>
          <div class="text-xs text-gray-500" x-text="activeItem.product_name"></div>
        </div>
        <div class="text-right">
          <div class="text-xs text-gray-500">To pick</div>
          <div class="text-base font-semibold text-gray-900">
            <span x-text="activeItem.remaining"></span> <span x-text="activeItem.uom"></span>
          </div>
        </div>
      </div>

      <div x-show="activeItem.suggested_full" x-cloak
           class="bg-amber-50 border border-amber-200 rounded p-2 text-xs">
        Go to bin
        <span class="font-mono font-semibold" x-text="activeItem.suggested_full"></span>
      </div>
      <div x-show="!activeItem.suggested_full" x-cloak
           class="bg-red-50 border border-red-200 rounded p-2 text-xs text-red-800">
        No suggested bin (short-stock at generation). Scan whatever bin currently has this SKU.
      </div>

      <!-- Step 1: bin -->
      <div x-show="!scannedBin" x-cloak>
        <div class="text-sm font-semibold text-gray-700 pt-1">Step 1 · Scan bin</div>
        <div class="flex gap-2 mt-1">
          <button @click="toggleScanner('bin')"
                  class="flex-1 slv-bg-primary text-white rounded-lg py-3 text-base font-semibold"
                  x-text="scannerOn && scannerMode==='bin' ? 'Stop camera' : 'Scan bin'"></button>
          <button @click="openBinManual = !openBinManual"
                  class="px-3 rounded-lg border border-gray-300 text-sm">⌨</button>
        </div>
        <form x-show="openBinManual" x-cloak @submit.prevent="lookupBin(manualBin); manualBin=''"
              class="flex gap-2 mt-2">
          <input x-model="manualBin" placeholder="Bin code (full or short)"
                 class="flex-1 rounded border-gray-300 text-sm font-mono">
          <button class="slv-bg-primary text-white px-3 rounded text-sm">Find</button>
        </form>
      </div>

      <!-- Step 2: SKU -->
      <template x-if="scannedBin && !skuVerified">
        <div>
          <div class="text-xs text-gray-600 pt-1">
            ✓ Bin: <span class="font-mono" x-text="scannedBin.full_code"></span>
            <span x-show="!isSuggestedBin()" x-cloak class="text-amber-700">(override — not the suggested bin)</span>
          </div>
          <div class="text-sm font-semibold text-gray-700 pt-1">Step 2 · Scan SKU</div>
          <div class="flex gap-2 mt-1">
            <button @click="toggleScanner('sku')"
                    class="flex-1 slv-bg-primary text-white rounded-lg py-3 text-base font-semibold"
                    x-text="scannerOn && scannerMode==='sku' ? 'Stop camera' : 'Scan SKU'"></button>
            <button @click="openSkuManual = !openSkuManual"
                    class="px-3 rounded-lg border border-gray-300 text-sm">⌨</button>
          </div>
          <form x-show="openSkuManual" x-cloak @submit.prevent="verifySku(manualSku); manualSku=''"
                class="flex gap-2 mt-2">
            <input x-model="manualSku" placeholder="SKU code or barcode"
                   class="flex-1 rounded border-gray-300 text-sm">
            <button class="slv-bg-primary text-white px-3 rounded text-sm">Find</button>
          </form>
        </div>
      </template>

      <!-- Step 3: confirm -->
      <template x-if="scannedBin && skuVerified">
        <div>
          <div class="text-xs text-gray-600 pt-1">
            ✓ Bin: <span class="font-mono" x-text="scannedBin.full_code"></span>
            · ✓ SKU: <span class="font-mono" x-text="activeItem.sku_code"></span>
          </div>
          <div class="text-sm font-semibold text-gray-700 pt-1">Step 3 · Confirm qty</div>
          <form @submit.prevent="submitPick()" class="grid grid-cols-1 gap-2 mt-2">
            <div>
              <label class="block text-xs text-gray-500">Qty</label>
              <input type="number" step="0.0001" min="0" x-model="entryQty" required
                     class="mt-1 w-full rounded border-gray-300 text-base text-right">
            </div>
            <button type="submit"
                    class="slv-bg-primary text-white rounded-lg py-3 text-base font-semibold"
                    :disabled="submitting" x-text="submitting ? 'Saving…' : 'Confirm pick'"></button>
            <button type="button" @click="resetSteps()" class="text-xs text-gray-500 underline">
              Restart this line
            </button>
          </form>
        </div>
      </template>

      <!-- Camera viewport reused across both modes -->
      <div id="scanner-area" class="rounded overflow-hidden mt-2" :class="scannerOn ? 'h-56' : 'h-0'"></div>
    </div>
  </template>

  <!-- Lines list (live progress) -->
  <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mt-4">Walk path</h3>
  <template x-for="i in items" :key="i.id">
    <div class="bg-white border border-gray-200 rounded-lg p-3 text-sm"
         :class="{'bg-emerald-50/40': i.remaining <= 0, 'border-indigo-300': activeItem && activeItem.id === i.id}">
      <div class="flex items-start justify-between">
        <div>
          <div class="text-xs text-gray-400">#<span x-text="i.walk_order"></span> · <span class="font-mono" x-text="i.suggested_full || '—'"></span></div>
          <div class="font-mono" x-text="i.sku_code"></div>
          <div class="text-xs text-gray-500" x-text="i.product_name"></div>
        </div>
        <div class="text-right">
          <button x-show="i.remaining > 0" @click="setActive(i)" class="text-xs text-indigo-700 underline">work</button>
          <span x-show="i.remaining <= 0" class="text-xs text-emerald-700 font-semibold">done</span>
        </div>
      </div>
      <div class="mt-1 h-1 rounded bg-gray-100 overflow-hidden">
        <div class="h-full"
             :class="i.remaining <= 0 ? 'bg-emerald-500' : 'slv-bg-primary'"
             :style="`width: ${i.qty_to_pick > 0 ? Math.min(100, (i.qty_picked / i.qty_to_pick) * 100) : 0}%`"></div>
      </div>
      <div class="text-[11px] text-gray-500 mt-1">
        <span x-text="i.qty_picked"></span> / <span x-text="i.qty_to_pick"></span>
        <span x-text="i.uom"></span>
      </div>
    </div>
  </template>

  <p x-show="pl?.status === 'COMPLETED'" x-cloak
     class="text-center text-emerald-700 text-base font-semibold pt-3">
    Pick list complete — ready for packing.
  </p>
</main>

<script>
function uuidv4() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = Math.random()*16|0, v = c==='x' ? r : (r&0x3|0x8);
    return v.toString(16);
  });
}

function pickApp() {
  return {
    loading: false,
    msg: '', msgKind: 'info',
    lists: [], pl: null, items: [],
    activeItem: null,
    scannedBin: null,    // {id, full_code, ...}
    skuVerified: false,
    entryQty: '',
    submitting: false,
    scannerOn: false,
    scannerMode: '',     // 'bin' | 'sku'
    openBinManual: false,
    openSkuManual: false,
    manualBin: '',
    manualSku: '',

    notify(text, kind) {
      this.msgKind = kind || 'info';
      this.msg = text;
      setTimeout(() => { if (this.msg === text) this.msg = ''; }, 4000);
    },

    isSuggestedBin() {
      return this.activeItem && this.scannedBin
          && this.activeItem.suggested_bin_id === this.scannedBin.id;
    },

    nextItem() {
      return this.items.find(i => i.remaining > 0) || null;
    },

    async loadLists() {
      this.loading = true;
      try {
        const data = await SLVScanner.api('/api/v1/scan/pick_list_list.php');
        this.lists = data.pick_lists || [];
      } catch (e) { this.notify(e.message, 'error'); }
      this.loading = false;
    },

    async openList(id) {
      try {
        const data = await SLVScanner.api('/api/v1/scan/pick_list_get.php?id=' + id);
        this.pl    = data.pick_list;
        this.items = data.items || [];
        this.resetSteps();
        this.activeItem = this.nextItem();
        if (this.activeItem) {
          this.entryQty = String(this.activeItem.remaining || '');
        }
      } catch (e) { this.notify(e.message, 'error'); }
    },

    closeList() {
      this.stopScanner();
      this.pl = null; this.items = []; this.activeItem = null;
      this.resetSteps();
      this.loadLists();
    },

    setActive(item) {
      if (item.remaining <= 0) return;
      this.activeItem = item;
      this.entryQty = String(item.remaining);
      this.resetSteps();
    },

    resetSteps() {
      this.scannedBin = null;
      this.skuVerified = false;
      this.openBinManual = false;
      this.openSkuManual = false;
      this.stopScanner();
    },

    async lookupBin(barcode) {
      barcode = String(barcode || '').trim();
      if (!barcode || !this.activeItem) return;
      try {
        const data = await SLVScanner.api(
          '/api/v1/scan/bin_lookup.php?warehouse_id=' + this.pl.warehouse_id +
          '&barcode=' + encodeURIComponent(barcode)
        );
        this.scannedBin = data.bin;
        this.openBinManual = false;
        this.stopScanner();
      } catch (e) { this.notify(e.message, 'error'); }
    },

    async verifySku(barcode) {
      barcode = String(barcode || '').trim();
      if (!barcode || !this.activeItem) return;
      try {
        const data = await SLVScanner.api(
          '/api/v1/scan/sku_lookup.php?barcode=' + encodeURIComponent(barcode)
        );
        if ((data.product || {}).id !== this.activeItem.product_id) {
          this.notify('Wrong SKU. Expected ' + this.activeItem.sku_code + '.', 'error');
          return;
        }
        this.skuVerified = true;
        this.openSkuManual = false;
        this.stopScanner();
      } catch (e) { this.notify(e.message, 'error'); }
    },

    async submitPick() {
      if (!this.activeItem || !this.scannedBin) return;
      this.submitting = true;
      try {
        const data = await SLVScanner.api('/api/v1/scan/pick_execute.php', 'POST', {
          pick_item_id: this.activeItem.id,
          bin_id:       this.scannedBin.id,
          qty:          parseFloat(this.entryQty) || 0,
          scan_uuid:    uuidv4(),
        });
        this.notify('Pick recorded.', 'success');
        // Reload the list so totals + suggestions are fresh.
        await this.openList(this.pl.id);
      } catch (e) { this.notify(e.message, 'error'); }
      this.submitting = false;
    },

    async toggleScanner(mode) {
      if (this.scannerOn && this.scannerMode === mode) { this.stopScanner(); return; }
      this.scannerMode = mode;
      try {
        await SLVScanner.start('scanner-area', (code) => {
          if (this.scannerMode === 'bin') this.lookupBin(code);
          else                            this.verifySku(code);
        });
        this.scannerOn = true;
      } catch (e) { this.notify('Camera error: ' + e.message, 'error'); }
    },
    stopScanner() {
      if (this.scannerOn) { SLVScanner.stop(); this.scannerOn = false; this.scannerMode = ''; }
    },
  };
}

</script>
<?php require __DIR__ . '/../partials/sw_bootstrap.php'; ?>
</body>
</html>
