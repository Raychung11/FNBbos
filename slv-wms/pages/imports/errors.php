<?php
// SLV WMS — pages/imports/errors.php
// Purpose: Stream the per-job error CSV to the browser as an attachment.
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

$stmt = db()->prepare(
    'SELECT type, filename, error_csv_path FROM import_jobs WHERE id = ? AND company_id = ?'
);
$stmt->execute([$id, company_id()]);
$job = $stmt->fetch();
if (!$job || empty($job['error_csv_path']) || !is_file($job['error_csv_path'])) {
    flash('error', 'Error CSV not available for this import.');
    redirect('/pages/imports/index.php');
}

$download = sprintf('errors-%s-%s', $job['type'], basename((string)$job['filename']));
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $download . '"');
header('Content-Length: ' . (string)filesize($job['error_csv_path']));
header('Cache-Control: no-store');
readfile($job['error_csv_path']);
exit;
