<?php
// SLV WMS — pages/settings/numbering.php
// Purpose: Edit document_sequences format_template + reset_yearly toggle.
//          current_seq is read-only (never edit; that breaks audit trail).
// Roles allowed: super_admin
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role('super_admin');

$DOC_TYPES = [
    'INV' => 'Invoice',
    'DO'  => 'Delivery Order',
    'SO'  => 'Sales Order',
    'GRN' => 'Goods Receipt Note',
    'PO'  => 'Purchase Order',
    'TRF' => 'Stock Transfer',
    'ADJ' => 'Stock Adjustment',
    'CNT' => 'Stock Count',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $rows = $_POST['rows'] ?? [];
    if (!is_array($rows)) {
        $rows = [];
    }

    foreach ($rows as $doc_type => $row) {
        $doc_type = (string)$doc_type;
        if (!isset($DOC_TYPES[$doc_type])) {
            continue; // ignore unknown
        }
        $tpl   = trim((string)($row['format_template'] ?? ''));
        $reset = !empty($row['reset_yearly']);

        if ($tpl === '') {
            $errors[] = $doc_type . ': format template is required.';
            continue;
        }
        if (!preg_match('/\{seq:(\d{1,2})\}/', $tpl)) {
            $errors[] = $doc_type . ': format must contain a {seq:N} placeholder.';
            continue;
        }
        if (mb_strlen($tpl) > 128) {
            $errors[] = $doc_type . ': template too long (max 128).';
            continue;
        }
    }

    if (!$errors) {
        db_tx(function () use ($rows, $DOC_TYPES) {
            $upd = db()->prepare(
                'UPDATE document_sequences
                    SET format_template = ?, reset_yearly = ?
                  WHERE company_id = ? AND doc_type = ?'
            );
            foreach ($rows as $doc_type => $row) {
                if (!isset($DOC_TYPES[$doc_type])) {
                    continue;
                }
                $tpl   = trim((string)($row['format_template'] ?? ''));
                $reset = !empty($row['reset_yearly']) ? 1 : 0;
                $upd->execute([$tpl, $reset, company_id(), $doc_type]);
            }
            audit_log('settings_update', 'document_sequences', null, ['rows' => array_keys($rows)]);
        });
        flash('success', 'Document numbering saved.');
        redirect('/pages/settings/numbering.php');
    }
}

// Load current sequences
$stmt = db()->prepare(
    'SELECT doc_type, format_template, current_seq, reset_yearly, last_reset_year
       FROM document_sequences
      WHERE company_id = ?
      ORDER BY doc_type'
);
$stmt->execute([company_id()]);
$sequences = [];
foreach ($stmt->fetchAll() as $r) {
    $sequences[$r['doc_type']] = $r;
}

$PAGE_TITLE   = 'Document numbering';
$SETTINGS_TAB = 'numbering';
require __DIR__ . '/../../partials/header.php';
require __DIR__ . '/../../partials/settings_nav.php';
?>
<h1 class="text-2xl font-semibold text-gray-900 mb-2">Document numbering</h1>
<p class="text-sm text-gray-500 mb-6">
  Placeholders: <code>{YY}</code> <code>{YYYY}</code> <code>{MM}</code>
  <code>{warehouse_code}</code> <code>{seq:N}</code> (zero-padded).
  The sequence counter is consumed atomically when a document is issued
  &mdash; do not edit it manually.
</p>

<?php if ($errors): ?>
  <div class="mb-4 border border-red-200 bg-red-50 text-red-800 rounded p-3 text-sm">
    <ul class="list-disc list-inside">
      <?php foreach ($errors as $err): ?><li><?= e_($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" class="bg-white border border-gray-200 rounded-lg overflow-hidden">
  <?= csrf_field() ?>
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 text-gray-600">
      <tr>
        <th class="text-left px-4 py-2 font-medium">Doc type</th>
        <th class="text-left px-4 py-2 font-medium">Format template</th>
        <th class="text-left px-4 py-2 font-medium">Current seq</th>
        <th class="text-left px-4 py-2 font-medium">Reset yearly</th>
        <th class="text-left px-4 py-2 font-medium">Next preview</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php foreach ($DOC_TYPES as $code => $label):
        $row = $sequences[$code] ?? ['format_template' => '', 'current_seq' => 0, 'reset_yearly' => 0, 'last_reset_year' => 0];
        $tpl = $row['format_template'];
        $next_seq = (int)$row['current_seq'] + 1;
        if ((int)$row['reset_yearly'] === 1 && (int)$row['last_reset_year'] !== (int)date('Y')) {
            $next_seq = 1;
        }
        $preview = $tpl !== '' ? docnum_render($tpl, $next_seq, ['warehouse_code' => 'WH01']) : '';
      ?>
        <tr>
          <td class="px-4 py-2 font-mono"><strong><?= e_($code) ?></strong> <span class="text-gray-400"><?= e_($label) ?></span></td>
          <td class="px-4 py-2">
            <input type="text" name="rows[<?= e_($code) ?>][format_template]" value="<?= e_($tpl) ?>" required
                   class="w-full rounded border-gray-300 shadow-sm font-mono text-xs">
          </td>
          <td class="px-4 py-2 text-gray-500"><?= e_((string)$row['current_seq']) ?></td>
          <td class="px-4 py-2">
            <label class="inline-flex items-center gap-2">
              <input type="checkbox" name="rows[<?= e_($code) ?>][reset_yearly]" value="1"
                     <?= (int)$row['reset_yearly'] === 1 ? 'checked' : '' ?>
                     class="rounded border-gray-300">
              <span class="text-xs text-gray-600">on Jan 1</span>
            </label>
          </td>
          <td class="px-4 py-2 font-mono text-xs text-indigo-700"><?= e_($preview) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="flex justify-end gap-3 px-4 py-3 bg-gray-50 border-t border-gray-200">
    <a href="/pages/settings/index.php" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    <button type="submit" class="slv-bg-primary text-white px-4 py-2 rounded text-sm font-medium">Save numbering</button>
  </div>
</form>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
