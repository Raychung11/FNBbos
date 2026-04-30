<?php
// SLV WMS — pages/imports/index.php
// Purpose: List CSV import jobs.
// Roles allowed: super_admin, warehouse_manager
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager']);

$stmt = db()->prepare(
    "SELECT j.*, u.name AS uploader
       FROM import_jobs j
  LEFT JOIN users u ON u.id = j.created_by
      WHERE j.company_id = ?
   ORDER BY j.id DESC LIMIT 50"
);
$stmt->execute([company_id()]);
$jobs = $stmt->fetchAll();

$PAGE_TITLE = 'CSV imports';
require __DIR__ . '/../../partials/header.php';
?>
<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-semibold text-gray-900">CSV imports</h1>
  <a href="/pages/imports/upload.php" class="slv-bg-primary text-white px-3 py-2 rounded text-sm font-medium">+ New import</a>
</div>

<div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 text-gray-600">
      <tr>
        <th class="text-left px-4 py-2 font-medium">Type</th>
        <th class="text-left px-4 py-2 font-medium">Filename</th>
        <th class="text-right px-4 py-2 font-medium">Rows</th>
        <th class="text-right px-4 py-2 font-medium">Done</th>
        <th class="text-right px-4 py-2 font-medium">Errors</th>
        <th class="text-left px-4 py-2 font-medium">Status</th>
        <th class="text-left px-4 py-2 font-medium">Uploaded</th>
        <th class="px-4 py-2"></th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$jobs): ?>
        <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No imports yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($jobs as $j):
        $pct = (int)$j['total_rows'] > 0 ? round(((int)$j['processed_rows'] / (int)$j['total_rows']) * 100, 1) : 0;
      ?>
        <tr>
          <td class="px-4 py-2 font-mono text-xs"><?= e_($j['type']) ?></td>
          <td class="px-4 py-2 text-xs"><?= e_($j['filename']) ?></td>
          <td class="px-4 py-2 text-right"><?= e_((string)$j['total_rows']) ?></td>
          <td class="px-4 py-2 text-right"><?= e_((string)$j['processed_rows']) ?> <span class="text-xs text-gray-400">(<?= $pct ?>%)</span></td>
          <td class="px-4 py-2 text-right <?= (int)$j['error_rows'] > 0 ? 'text-red-600' : 'text-gray-500' ?>"><?= e_((string)$j['error_rows']) ?></td>
          <td class="px-4 py-2"><span class="inline-flex items-center px-2 py-0.5 rounded text-xs <?php
              echo match ($j['status']) {
                'COMPLETED' => 'bg-green-50 text-green-700',
                'RUNNING'   => 'bg-blue-50  text-blue-700',
                'FAILED'    => 'bg-red-50   text-red-700',
                default     => 'bg-gray-100 text-gray-500',
              };
            ?>"><?= e_($j['status']) ?></span></td>
          <td class="px-4 py-2 text-gray-500 text-xs"><?= e_($j['created_at']) ?><br><span class="opacity-60"><?= e_($j['uploader'] ?? '') ?></span></td>
          <td class="px-4 py-2 text-right whitespace-nowrap">
            <?php if (in_array($j['status'], ['PENDING','RUNNING'], true)): ?>
              <a href="/pages/imports/run.php?id=<?= e_($j['id']) ?>" class="text-indigo-700 hover:underline">Run</a>
            <?php endif; ?>
            <?php if ((int)$j['error_rows'] > 0 && $j['error_csv_path']): ?>
              <a href="/pages/imports/errors.php?id=<?= e_($j['id']) ?>" class="ml-2 text-red-600 hover:underline">Errors CSV</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
