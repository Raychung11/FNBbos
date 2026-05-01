<?php
// SLV WMS — pages/grn/index.php
// Purpose: List goods-receipt notes with status / warehouse / supplier filters.
// Roles allowed: super_admin, warehouse_manager, receiver
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','receiver']);

$role          = current_user()['role'] ?? '';
$accessibleIds = user_warehouse_ids();

// Filters.
$status = (string)($_GET['status']        ?? '');
$wid    = isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '' ? (int)$_GET['warehouse_id'] : null;
$sid    = isset($_GET['supplier_id'])  && $_GET['supplier_id']  !== '' ? (int)$_GET['supplier_id']  : null;
$q      = trim((string)($_GET['q'] ?? ''));

if ($wid !== null && $role !== 'super_admin' && !in_array($wid, $accessibleIds, true)) {
    flash('error', 'No access to that warehouse.');
    redirect('/pages/grn/index.php');
}

$where  = ['g.company_id = ?'];
$params = [company_id()];

if ($role !== 'super_admin') {
    if (!$accessibleIds) {
        $where[] = '1 = 0';
    } else {
        $where[]  = 'g.warehouse_id IN (' . implode(',', array_fill(0, count($accessibleIds), '?')) . ')';
        $params   = array_merge($params, $accessibleIds);
    }
}
$validStatus = ['DRAFT','RECEIVING','RECEIVED','PUTAWAY','CLOSED','CANCELLED'];
if ($status !== '' && in_array($status, $validStatus, true)) {
    $where[] = 'g.status = ?';
    $params[] = $status;
}
if ($wid !== null) { $where[] = 'g.warehouse_id = ?'; $params[] = $wid; }
if ($sid !== null) { $where[] = 'g.supplier_id  = ?'; $params[] = $sid; }
if ($q !== '')     { $where[] = '(g.grn_no LIKE ? OR g.ref_po LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }

$sql = "SELECT g.*, w.code AS warehouse_code, s.code AS supplier_code, s.name AS supplier_name,
               u.name AS creator_name,
               (SELECT COUNT(*) FROM grn_items WHERE grn_id = g.id) AS line_count
          FROM grn g
          JOIN warehouses w ON w.id = g.warehouse_id
     LEFT JOIN suppliers  s ON s.id = g.supplier_id
     LEFT JOIN users      u ON u.id = g.created_by
         WHERE " . implode(' AND ', $where) . "
      ORDER BY g.id DESC
         LIMIT 200";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Filter dropdown sources.
$whWhere  = ['company_id = ?']; $whParams = [company_id()];
if ($role !== 'super_admin' && $accessibleIds) {
    $whWhere[]  = 'id IN (' . implode(',', array_fill(0, count($accessibleIds), '?')) . ')';
    $whParams   = array_merge($whParams, $accessibleIds);
}
$whStmt = db()->prepare('SELECT id, code, name FROM warehouses WHERE ' . implode(' AND ', $whWhere) . ' ORDER BY code');
$whStmt->execute($whParams);
$warehouses = $whStmt->fetchAll();

$sStmt = db()->prepare("SELECT id, code, name FROM suppliers WHERE company_id = ? AND status='ACTIVE' ORDER BY code");
$sStmt->execute([company_id()]);
$suppliers = $sStmt->fetchAll();

$canCreate = in_array($role, ['super_admin','warehouse_manager','receiver'], true);

$PAGE_TITLE = 'Goods receipt';
require __DIR__ . '/../../partials/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
  <h1 class="text-2xl font-semibold text-gray-900">Goods receipt (GRN)</h1>
  <?php if ($canCreate): ?>
    <a href="/pages/grn/create.php" class="slv-bg-primary text-white px-3 py-2 rounded text-sm font-medium">+ New GRN</a>
  <?php endif; ?>
</div>

<form method="get" class="bg-white border border-gray-200 rounded-lg p-3 mb-4 grid grid-cols-2 sm:grid-cols-5 gap-3 text-sm">
  <div>
    <label class="block text-xs font-medium text-gray-500">Status</label>
    <select name="status" class="mt-1 w-full rounded border-gray-300 shadow-sm">
      <option value="">All</option>
      <?php foreach ($validStatus as $s): ?>
        <option value="<?= e_($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e_($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-gray-500">Warehouse</label>
    <select name="warehouse_id" class="mt-1 w-full rounded border-gray-300 shadow-sm">
      <option value="">All</option>
      <?php foreach ($warehouses as $w): ?>
        <option value="<?= e_($w['id']) ?>" <?= $wid === (int)$w['id'] ? 'selected' : '' ?>><?= e_($w['code']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-gray-500">Supplier</label>
    <select name="supplier_id" class="mt-1 w-full rounded border-gray-300 shadow-sm">
      <option value="">All</option>
      <?php foreach ($suppliers as $s): ?>
        <option value="<?= e_($s['id']) ?>" <?= $sid === (int)$s['id'] ? 'selected' : '' ?>><?= e_($s['code']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-gray-500">Search GRN # / PO #</label>
    <input type="text" name="q" value="<?= e_($q) ?>" class="mt-1 w-full rounded border-gray-300 shadow-sm">
  </div>
  <div class="flex items-end gap-2">
    <button class="slv-bg-primary text-white px-3 py-2 rounded">Filter</button>
    <a href="/pages/grn/index.php" class="px-3 py-2 text-gray-600 hover:text-gray-900">Reset</a>
  </div>
</form>

<div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 text-gray-600">
      <tr>
        <th class="text-left px-4 py-2 font-medium">GRN #</th>
        <th class="text-left px-4 py-2 font-medium">WH</th>
        <th class="text-left px-4 py-2 font-medium">Supplier</th>
        <th class="text-left px-4 py-2 font-medium">PO ref</th>
        <th class="text-right px-4 py-2 font-medium">Lines</th>
        <th class="text-right px-4 py-2 font-medium">Value</th>
        <th class="text-left px-4 py-2 font-medium">Status</th>
        <th class="text-left px-4 py-2 font-medium">Created</th>
        <th class="px-4 py-2"></th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="px-4 py-6 text-center text-gray-400">No GRNs match.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $g):
        $statusClass = match ($g['status']) {
            'DRAFT'      => 'bg-gray-100   text-gray-700',
            'RECEIVING'  => 'bg-blue-50    text-blue-700',
            'RECEIVED'   => 'bg-cyan-50    text-cyan-700',
            'PUTAWAY'    => 'bg-amber-50   text-amber-800',
            'CLOSED'     => 'bg-green-50   text-green-700',
            'CANCELLED'  => 'bg-red-50     text-red-700',
            default      => 'bg-gray-100   text-gray-500',
        };
      ?>
        <tr>
          <td class="px-4 py-2 font-mono"><?= e_($g['grn_no']) ?></td>
          <td class="px-4 py-2 font-mono text-xs"><?= e_($g['warehouse_code']) ?></td>
          <td class="px-4 py-2 text-xs">
            <?php if ($g['supplier_name']): ?>
              <span class="font-mono"><?= e_($g['supplier_code']) ?></span>
              <span class="text-gray-500"><?= e_($g['supplier_name']) ?></span>
            <?php else: ?>
              <span class="text-gray-400">—</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-2 text-xs text-gray-600"><?= e_($g['ref_po'] ?: '—') ?></td>
          <td class="px-4 py-2 text-right"><?= e_((string)$g['line_count']) ?></td>
          <td class="px-4 py-2 text-right font-mono"><?= e_(money($g['total_value'])) ?></td>
          <td class="px-4 py-2"><span class="inline-flex items-center px-2 py-0.5 rounded text-xs <?= $statusClass ?>"><?= e_($g['status']) ?></span></td>
          <td class="px-4 py-2 text-xs text-gray-500"><?= e_($g['created_at']) ?><br><span class="opacity-60"><?= e_($g['creator_name'] ?? '') ?></span></td>
          <td class="px-4 py-2 text-right whitespace-nowrap">
            <a href="/pages/grn/view.php?id=<?= e_($g['id']) ?>" class="text-indigo-700 hover:underline">Open</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
