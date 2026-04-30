<?php
// SLV WMS — pages/products/_form.php
// Shared form partial used by create.php and edit.php.
// Expects: $values (array), $barcodes (array of {barcode,type,is_primary}),
//          $CATEGORIES, $TAX_GROUPS, $BARCODE_TYPES, $UOMS, $errors,
//          $isEdit (bool), $product_id (int|null).
?>
<?php if ($errors): ?>
  <div class="mb-4 border border-red-200 bg-red-50 text-red-800 rounded p-3 text-sm max-w-3xl">
    <ul class="list-disc list-inside">
      <?php foreach ($errors as $err): ?><li><?= e_($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" class="space-y-6 max-w-3xl"
      x-data='{
        barcodes: <?= json_encode(array_values($barcodes ?: [["barcode"=>"","type"=>"INTERNAL","is_primary"=>1]]), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>,
        addBarcode(){ this.barcodes.push({barcode:"", type:"INTERNAL", is_primary:0}); },
        rmBarcode(i){ this.barcodes.splice(i,1); if(!this.barcodes.some(b=>b.is_primary) && this.barcodes.length) this.barcodes[0].is_primary = 1; },
        setPrimary(i){ this.barcodes.forEach((b,j)=> b.is_primary = (j===i)?1:0); }
      }'>
  <?= csrf_field() ?>
  <?php if ($isEdit): ?>
    <input type="hidden" name="id" value="<?= e_($product_id) ?>">
  <?php endif; ?>

  <section class="bg-white border border-gray-200 rounded-lg p-5">
    <h2 class="text-base font-semibold text-gray-900 mb-4">SKU details</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700">SKU code</label>
        <input type="text" name="sku_code" value="<?= e_($values['sku_code'] ?? '') ?>" required maxlength="64"
               pattern="[A-Za-z0-9_\-/.]{1,64}" style="text-transform:uppercase"
               class="mt-1 w-full rounded border-gray-300 shadow-sm font-mono">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Status</label>
        <select name="status" class="mt-1 w-full rounded border-gray-300 shadow-sm">
          <?php foreach (['ACTIVE','INACTIVE'] as $s): ?>
            <option value="<?= e_($s) ?>" <?= (($values['status'] ?? 'ACTIVE') === $s) ? 'selected' : '' ?>><?= e_($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Name</label>
        <input type="text" name="name" value="<?= e_($values['name'] ?? '') ?>" required maxlength="255"
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Category</label>
        <select name="category_id" class="mt-1 w-full rounded border-gray-300 shadow-sm">
          <option value="">— none —</option>
          <?php foreach ($CATEGORIES as $c): ?>
            <option value="<?= e_($c['id']) ?>" <?= (int)($values['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
              <?= e_($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Default tax group</label>
        <select name="default_tax_group_id" class="mt-1 w-full rounded border-gray-300 shadow-sm">
          <option value="">— none —</option>
          <?php foreach ($TAX_GROUPS as $tg): ?>
            <option value="<?= e_($tg['id']) ?>" <?= (int)($values['default_tax_group_id'] ?? 0) === (int)$tg['id'] ? 'selected' : '' ?>>
              <?= e_($tg['code']) ?> — <?= e_($tg['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">UoM</label>
        <select name="uom" class="mt-1 w-full rounded border-gray-300 shadow-sm">
          <?php foreach ($UOMS as $u): ?>
            <option value="<?= e_($u) ?>" <?= (($values['uom'] ?? 'pcs') === $u) ? 'selected' : '' ?>><?= e_($u) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Pack size</label>
        <input type="number" step="0.0001" min="0" name="pack_size" value="<?= e_($values['pack_size'] ?? '1') ?>" required
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Weight (kg)</label>
        <input type="number" step="0.0001" min="0" name="weight_kg" value="<?= e_($values['weight_kg'] ?? '') ?>"
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Selling price</label>
        <input type="number" step="0.01" min="0" name="selling_price" value="<?= e_($values['selling_price'] ?? '0') ?>" required
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Min qty</label>
        <input type="number" step="0.0001" min="0" name="min_qty" value="<?= e_($values['min_qty'] ?? '0') ?>"
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Max qty</label>
        <input type="number" step="0.0001" min="0" name="max_qty" value="<?= e_($values['max_qty'] ?? '0') ?>"
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Reorder point</label>
        <input type="number" step="0.0001" min="0" name="reorder_point" value="<?= e_($values['reorder_point'] ?? '0') ?>"
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
      <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Notes</label>
        <textarea name="notes" rows="2" class="mt-1 w-full rounded border-gray-300 shadow-sm"><?= e_($values['notes'] ?? '') ?></textarea>
      </div>
    </div>
  </section>

  <section class="bg-white border border-gray-200 rounded-lg p-5">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-semibold text-gray-900">Barcodes</h2>
      <button type="button" @click="addBarcode()" class="text-indigo-700 text-sm hover:underline">+ Add barcode</button>
    </div>
    <p class="text-xs text-gray-500 mb-3">Each SKU can have multiple barcodes. Pick one as <strong>primary</strong> &mdash; that's what's printed on labels.</p>

    <template x-for="(b, i) in barcodes" :key="i">
      <div class="grid grid-cols-12 gap-2 items-center mb-2">
        <input type="text" :name="`barcodes[${i}][barcode]`" x-model="b.barcode"
               placeholder="Barcode value" maxlength="128"
               class="col-span-6 rounded border-gray-300 shadow-sm font-mono text-sm">
        <select :name="`barcodes[${i}][type]`" x-model="b.type"
                class="col-span-3 rounded border-gray-300 shadow-sm text-sm">
          <?php foreach ($BARCODE_TYPES as $bt): ?>
            <option value="<?= e_($bt) ?>"><?= e_($bt) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="col-span-2 inline-flex items-center gap-1 text-sm">
          <input type="radio" :name="`primary_barcode`" :value="i" :checked="b.is_primary == 1"
                 @change="setPrimary(i)" class="border-gray-300">
          Primary
        </label>
        <input type="hidden" :name="`barcodes[${i}][is_primary]`" :value="b.is_primary">
        <button type="button" @click="rmBarcode(i)" class="col-span-1 text-red-600 text-sm hover:underline">×</button>
      </div>
    </template>
  </section>

  <div class="flex justify-end gap-3 max-w-3xl">
    <a href="/pages/products/index.php" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    <button type="submit" class="slv-bg-primary text-white px-4 py-2 rounded text-sm font-medium">
      <?= $isEdit ? 'Save changes' : 'Create product' ?>
    </button>
  </div>
</form>
