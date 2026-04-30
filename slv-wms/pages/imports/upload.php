<?php
// SLV WMS — pages/imports/upload.php
// Purpose: Upload a CSV, kick off a chunked import job.
// Roles allowed: super_admin, warehouse_manager
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager']);

$types  = csv_import_types();
$type   = $_GET['type'] ?? '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $type = (string)($_POST['type'] ?? '');
    if (!isset($types[$type])) {
        $errors[] = 'Pick a valid import type.';
    }
    if (empty($_FILES['file']['name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        $errors[] = 'Choose a CSV file to upload.';
    }
    if (!$errors) {
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload failed (error ' . (int)$f['error'] . ').';
        } elseif ($f['size'] > 25 * 1024 * 1024) {
            $errors[] = 'File too large (max 25 MB).';
        } else {
            // Move into storage/imports/.
            $dir = dirname(__DIR__, 2) . '/storage/imports';
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                $errors[] = 'Cannot create storage/imports/.';
            } else {
                $base = sprintf('%s-%s-%s.csv',
                    $type, date('Ymd-His'), substr(uuid_v4(), 0, 8));
                $abs = $dir . '/' . $base;
                if (!move_uploaded_file($f['tmp_name'], $abs)) {
                    $errors[] = 'Could not save the uploaded file.';
                } else {
                    require_once dirname(__DIR__, 2) . '/lib/import/' . $type . '.php';
                    $reqFn = "csv_import_{$type}_required_headers";
                    $req   = function_exists($reqFn) ? (array)$reqFn() : [];
                    $hdrErr = csv_validate_headers($abs, $req);
                    if ($hdrErr !== null) {
                        @unlink($abs);
                        $errors[] = $hdrErr;
                    } else {
                        $total = csv_count_rows($abs);

                        db()->prepare(
                            'INSERT INTO import_jobs
                               (company_id, type, filename, file_path, total_rows, status, created_by)
                             VALUES (?, ?, ?, ?, ?, "PENDING", ?)'
                        )->execute([
                            company_id(), $type, (string)$f['name'], $abs, $total,
                            (int)(current_user()['id'] ?? 0),
                        ]);
                        $jobId = (int)db()->lastInsertId();
                        audit_log('import_create', 'import_jobs', $jobId, ['type' => $type, 'filename' => $f['name'], 'total' => $total]);
                        flash('success', "Import queued: $total rows.");
                        redirect('/pages/imports/run.php?id=' . $jobId);
                    }
                }
            }
        }
    }
}

$PAGE_TITLE = 'Upload CSV';
require __DIR__ . '/../../partials/header.php';
?>
<div class="mb-6">
  <a href="/pages/imports/index.php" class="text-sm text-gray-500 hover:text-gray-900">&larr; CSV imports</a>
  <h1 class="text-2xl font-semibold text-gray-900 mt-1">New CSV import</h1>
</div>

<?php if ($errors): ?>
  <div class="mb-4 border border-red-200 bg-red-50 text-red-800 rounded p-3 text-sm max-w-2xl">
    <ul class="list-disc list-inside">
      <?php foreach ($errors as $err): ?><li><?= e_($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="bg-white border border-gray-200 rounded-lg p-5 max-w-2xl space-y-4">
  <?= csrf_field() ?>
  <div>
    <label class="block text-sm font-medium text-gray-700">Import type</label>
    <select name="type" required class="mt-1 w-full rounded border-gray-300 shadow-sm">
      <option value="">— pick a type —</option>
      <?php foreach ($types as $key => $label): ?>
        <option value="<?= e_($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= e_($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-sm font-medium text-gray-700">CSV file</label>
    <input type="file" name="file" accept=".csv,text/csv" required class="mt-1 block w-full text-sm">
    <p class="mt-1 text-xs text-gray-500">Up to 25 MB. UTF-8 encoded. First row = column headers (case-insensitive).</p>
  </div>

  <details class="text-xs text-gray-600">
    <summary class="cursor-pointer">Expected columns</summary>
    <div class="mt-2 space-y-2">
      <div>
        <strong>products</strong>: <code>sku_code, name, category_name, uom, pack_size, weight_kg, selling_price, min_qty, max_qty, reorder_point, default_tax_group_code, primary_barcode, barcode_type, status</code>
        — required: <code>sku_code, name</code>. Categories are auto-created if not found.
      </div>
      <div>
        <strong>bins</strong>: <code>warehouse_code, zone_code, zone_name, zone_type, rack_code, bin_code, capacity_units, pickable, status, barcode</code>
        — required: <code>warehouse_code, zone_code, rack_code, bin_code</code>. Zones &amp; racks are auto-created.
      </div>
    </div>
  </details>

  <div class="flex justify-end gap-3 pt-2">
    <a href="/pages/imports/index.php" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    <button type="submit" class="slv-bg-primary text-white px-4 py-2 rounded text-sm font-medium">Upload &amp; queue</button>
  </div>
</form>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
