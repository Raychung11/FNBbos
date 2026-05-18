<?php
// SLV WMS — pages/customers/_form.php
// Shared form for create.php / edit.php.
// Expects $values, $errors, $isEdit, $customer_id, $TAX_GROUPS.
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
  <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= e_($customer_id) ?>"><?php endif; ?>
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
      <label class="block text-sm font-medium text-gray-700">Billing address</label>
      <textarea name="billing_address" rows="2" class="mt-1 w-full rounded border-gray-300 shadow-sm"><?= e_($values['billing_address'] ?? '') ?></textarea>
    </div>
    <div class="sm:col-span-2">
      <label class="block text-sm font-medium text-gray-700">Shipping address</label>
      <textarea name="shipping_address" rows="2" class="mt-1 w-full rounded border-gray-300 shadow-sm"><?= e_($values['shipping_address'] ?? '') ?></textarea>
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
      <label class="block text-sm font-medium text-gray-700">Payment terms</label>
      <input type="text" name="payment_terms" value="<?= e_($values['payment_terms'] ?? '') ?>" maxlength="64"
             placeholder="e.g. Net 30" class="mt-1 w-full rounded border-gray-300 shadow-sm">
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Credit limit</label>
      <input type="number" step="0.01" min="0" name="credit_limit" value="<?= e_($values['credit_limit'] ?? '0') ?>"
             class="mt-1 w-full rounded border-gray-300 shadow-sm">
    </div>
    <div class="sm:col-span-2">
      <label class="block text-sm font-medium text-gray-700">Notes</label>
      <textarea name="notes" rows="2" class="mt-1 w-full rounded border-gray-300 shadow-sm"><?= e_($values['notes'] ?? '') ?></textarea>
    </div>
  </div>
  <div class="flex justify-end gap-3 pt-2">
    <a href="/pages/customers/index.php" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    <button type="submit" class="slv-bg-primary text-white px-4 py-2 rounded text-sm font-medium"><?= $isEdit ? 'Save changes' : 'Create customer' ?></button>
  </div>
</form>
