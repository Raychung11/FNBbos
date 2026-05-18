<?php
// SLV WMS — pages/settings/branding.php
// Purpose: Edit company info (companies row) + theme colours + logo (app_settings).
// Roles allowed: super_admin
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role('super_admin');

$errors = [];
$company = company_record();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // ---- Company fields → companies table ---------------------------------
    $name            = trim((string)($_POST['name']            ?? ''));
    $registered_name = trim((string)($_POST['registered_name'] ?? ''));
    $address         = trim((string)($_POST['address']         ?? ''));
    $phone           = trim((string)($_POST['phone']           ?? ''));
    $email           = trim((string)($_POST['email']           ?? ''));
    $registration_no = trim((string)($_POST['registration_no'] ?? ''));
    $tax_no          = trim((string)($_POST['tax_no']          ?? ''));

    if ($name === '')                                        $errors[] = 'Company name is required.';
    if (mb_strlen($name) > 191)                              $errors[] = 'Company name is too long (max 191).';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is not a valid address.';

    // ---- Colours → app_settings -------------------------------------------
    $primary   = strtolower(trim((string)($_POST['primary_color']   ?? '')));
    $secondary = strtolower(trim((string)($_POST['secondary_color'] ?? '')));
    $accent    = strtolower(trim((string)($_POST['accent_color']    ?? '')));
    $hex = '/^#[0-9a-f]{6}$/';
    if (!preg_match($hex, $primary))   $errors[] = 'Primary colour must be a hex like #6D28D9.';
    if (!preg_match($hex, $secondary)) $errors[] = 'Secondary colour must be a hex like #1F2937.';
    if (!preg_match($hex, $accent))    $errors[] = 'Accent colour must be a hex like #F59E0B.';

    // ---- Logo upload ------------------------------------------------------
    $newLogoPath = null; // when set, replaces brand.logo_path
    if (!empty($_FILES['logo']['name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
        $f = $_FILES['logo'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Logo upload failed (error code ' . (int)$f['error'] . ').';
        } elseif ($f['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Logo too large (max 2 MB).';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($f['tmp_name']) ?: '';
            $allowed = ['image/png' => 'png', 'image/svg+xml' => 'svg', 'image/jpeg' => 'jpg'];
            if (!isset($allowed[$mime])) {
                $errors[] = 'Logo must be PNG, SVG, or JPEG.';
            } else {
                $ext = $allowed[$mime];
                $dir = dirname(__DIR__, 2) . '/uploads/branding';
                if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                    $errors[] = 'Unable to create /uploads/branding directory.';
                } else {
                    $base = 'logo-' . date('YmdHis') . '-' . substr(uuid_v4(), 0, 8) . '.' . $ext;
                    $dest = $dir . '/' . $base;
                    if (!move_uploaded_file($f['tmp_name'], $dest)) {
                        $errors[] = 'Could not save uploaded logo.';
                    } else {
                        $newLogoPath = '/uploads/branding/' . $base;
                    }
                }
            }
        }
    }

    if (!$errors) {
        db_tx(function () use (
            $name, $registered_name, $address, $phone, $email,
            $registration_no, $tax_no, $primary, $secondary, $accent, $newLogoPath
        ) {
            $upd = db()->prepare(
                'UPDATE companies
                    SET name            = ?,
                        registered_name = ?,
                        address         = ?,
                        phone           = ?,
                        email           = ?,
                        registration_no = ?,
                        tax_no          = ?
                  WHERE id = ?'
            );
            $upd->execute([
                $name, $registered_name ?: null, $address ?: null, $phone ?: null,
                $email ?: null, $registration_no ?: null, $tax_no ?: null,
                company_id(),
            ]);

            setting_set('brand.company_name',    $name,      'string');
            setting_set('brand.primary_color',   $primary,   'color');
            setting_set('brand.secondary_color', $secondary, 'color');
            setting_set('brand.accent_color',    $accent,    'color');

            if ($newLogoPath !== null) {
                $existing = setting('brand.logo_path', '');
                setting_set('brand.logo_path', $newLogoPath, 'path');
                // Best-effort cleanup of the previous logo file.
                if ($existing && str_starts_with((string)$existing, '/uploads/branding/')) {
                    $abs = dirname(__DIR__, 2) . $existing;
                    if (is_file($abs)) {
                        @unlink($abs);
                    }
                }
            }

            audit_log('settings_update', 'branding', company_id(), [
                'fields_changed' => [
                    'name','colors',
                    $newLogoPath !== null ? 'logo' : null,
                ],
            ]);
        });

        flash('success', 'Branding saved.');
        redirect('/pages/settings/branding.php');
    }
}

// Re-read after save (or on first GET)
$company   = company_record();
$primary   = setting('brand.primary_color',   '#6D28D9');
$secondary = setting('brand.secondary_color', '#1F2937');
$accent    = setting('brand.accent_color',    '#F59E0B');
$logoPath  = setting('brand.logo_path', '');

$PAGE_TITLE   = 'Branding';
$SETTINGS_TAB = 'branding';
require __DIR__ . '/../../partials/header.php';
require __DIR__ . '/../../partials/settings_nav.php';
?>
<h1 class="text-2xl font-semibold text-gray-900 mb-6">Branding & company</h1>

<?php if ($errors): ?>
  <div class="mb-4 border border-red-200 bg-red-50 text-red-800 rounded p-3 text-sm">
    <ul class="list-disc list-inside">
      <?php foreach ($errors as $err): ?><li><?= e_($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="space-y-6 max-w-3xl">
  <?= csrf_field() ?>

  <section class="bg-white border border-gray-200 rounded-lg p-5">
    <h2 class="text-base font-semibold text-gray-900 mb-4">Company</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Company name</label>
        <input type="text" name="name" value="<?= e_($company['name'] ?? '') ?>" required maxlength="191"
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
      <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Registered legal name</label>
        <input type="text" name="registered_name" value="<?= e_($company['registered_name'] ?? '') ?>" maxlength="191"
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
      <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Address</label>
        <textarea name="address" rows="2" class="mt-1 w-full rounded border-gray-300 shadow-sm"><?= e_($company['address'] ?? '') ?></textarea>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Phone</label>
        <input type="text" name="phone" value="<?= e_($company['phone'] ?? '') ?>" maxlength="64"
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Email</label>
        <input type="email" name="email" value="<?= e_($company['email'] ?? '') ?>" maxlength="191"
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Registration no</label>
        <input type="text" name="registration_no" value="<?= e_($company['registration_no'] ?? '') ?>" maxlength="64"
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Tax no (SST/GST)</label>
        <input type="text" name="tax_no" value="<?= e_($company['tax_no'] ?? '') ?>" maxlength="64"
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
    </div>
  </section>

  <section class="bg-white border border-gray-200 rounded-lg p-5">
    <h2 class="text-base font-semibold text-gray-900 mb-4">Theme colours</h2>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700">Primary</label>
        <div class="flex items-center gap-2 mt-1">
          <input type="color" value="<?= e_($primary) ?>" oninput="this.nextElementSibling.value=this.value"
                 class="h-10 w-10 rounded border-gray-300">
          <input type="text" name="primary_color" value="<?= e_($primary) ?>" pattern="^#[0-9a-fA-F]{6}$" required
                 class="flex-1 rounded border-gray-300 shadow-sm font-mono text-sm">
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Secondary</label>
        <div class="flex items-center gap-2 mt-1">
          <input type="color" value="<?= e_($secondary) ?>" oninput="this.nextElementSibling.value=this.value"
                 class="h-10 w-10 rounded border-gray-300">
          <input type="text" name="secondary_color" value="<?= e_($secondary) ?>" pattern="^#[0-9a-fA-F]{6}$" required
                 class="flex-1 rounded border-gray-300 shadow-sm font-mono text-sm">
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Accent</label>
        <div class="flex items-center gap-2 mt-1">
          <input type="color" value="<?= e_($accent) ?>" oninput="this.nextElementSibling.value=this.value"
                 class="h-10 w-10 rounded border-gray-300">
          <input type="text" name="accent_color" value="<?= e_($accent) ?>" pattern="^#[0-9a-fA-F]{6}$" required
                 class="flex-1 rounded border-gray-300 shadow-sm font-mono text-sm">
        </div>
      </div>
    </div>
  </section>

  <section class="bg-white border border-gray-200 rounded-lg p-5">
    <h2 class="text-base font-semibold text-gray-900 mb-4">Logo</h2>
    <div class="flex items-start gap-6">
      <div class="w-32 h-32 bg-gray-50 border border-dashed border-gray-300 rounded flex items-center justify-center overflow-hidden">
        <?php if ($logoPath !== '' && is_file(dirname(__DIR__, 2) . $logoPath)): ?>
          <img src="<?= e_($logoPath) ?>" alt="Current logo" class="max-w-full max-h-full">
        <?php else: ?>
          <span class="text-xs text-gray-400">No logo</span>
        <?php endif; ?>
      </div>
      <div class="flex-1">
        <label class="block text-sm font-medium text-gray-700">Upload logo</label>
        <input type="file" name="logo" accept="image/png,image/svg+xml,image/jpeg"
               class="mt-1 block w-full text-sm">
        <p class="mt-1 text-xs text-gray-500">PNG, SVG, or JPEG · max 2 MB. Used in UI header and PDF documents.</p>
      </div>
    </div>
  </section>

  <div class="flex justify-end gap-3">
    <a href="/pages/settings/index.php" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    <button type="submit" class="slv-bg-primary text-white px-4 py-2 rounded text-sm font-medium">Save branding</button>
  </div>
</form>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
