<?php
// SLV WMS — pages/suppliers/_form.php
// Shared form for create.php and edit.php.
// Expects $values, $errors, $isEdit, $supplier_id (when editing).
?>
<?php if ($errors): ?>
  <div class="mb-4 border border-red-200 bg-red-50 text-red-800 rounded p-3 text-sm max-w-2xl">
    <ul class="list-disc list-inside">
      <?php foreach ($errors as $err): ?><li><?= e_($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" class="bg-white border border-gray-200 rounded-lg p-5 max-w-2xl space-y-4">
  <?= csrf_field() ?>
  <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= e_($supplier_id) ?>"><?php endif; ?>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
      <label class="block text-sm font-medium text-gray-700">Code</label>
      <input type="text" name="code" value="<?= e_($values['code'] ?? '') ?>" required pattern="[A-Za-z0-9_\-]{1,64}"
             maxlength="64" style="text-transform:uppercase"
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
      <input type="text" name="name" value="<?= e_($values['name'] ?? '') ?>" required maxlength="191"
             class="mt-1 w-full rounded border-gray-300 shadow-sm">
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Contact person</label>
      <input type="text" name="contact_person" value="<?= e_($values['contact_person'] ?? '') ?>" maxlength="191"
             class="mt-1 w-full rounded border-gray-300 shadow-sm">
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Phone</label>
      <input type="text" name="phone" value="<?= e_($values['phone'] ?? '') ?>" maxlength="64"
             class="mt-1 w-full rounded border-gray-300 shadow-sm">
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Email</label>
      <input type="email" name="email" value="<?= e_($values['email'] ?? '') ?>" maxlength="191"
             class="mt-1 w-full rounded border-gray-300 shadow-sm">
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Tax no</label>
      <input type="text" name="tax_no" value="<?= e_($values['tax_no'] ?? '') ?>" maxlength="64"
             class="mt-1 w-full rounded border-gray-300 shadow-sm">
    </div>
    <div class="sm:col-span-2">
      <label class="block text-sm font-medium text-gray-700">Address</label>
      <textarea name="address" rows="2" class="mt-1 w-full rounded border-gray-300 shadow-sm"><?= e_($values['address'] ?? '') ?></textarea>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Payment terms</label>
      <input type="text" name="payment_terms" value="<?= e_($values['payment_terms'] ?? '') ?>" maxlength="64"
             placeholder="e.g. Net 30"
             class="mt-1 w-full rounded border-gray-300 shadow-sm">
    </div>
    <div class="sm:col-span-2">
      <label class="block text-sm font-medium text-gray-700">Notes</label>
      <textarea name="notes" rows="2" class="mt-1 w-full rounded border-gray-300 shadow-sm"><?= e_($values['notes'] ?? '') ?></textarea>
    </div>
  </div>
  <div class="flex justify-end gap-3 pt-2">
    <a href="/pages/suppliers/index.php" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    <button type="submit" class="slv-bg-primary text-white px-4 py-2 rounded text-sm font-medium"><?= $isEdit ? 'Save changes' : 'Create supplier' ?></button>
  </div>
</form>
