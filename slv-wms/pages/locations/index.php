<?php
// SLV WMS — pages/locations/index.php
// Purpose: Warehouse → Zone → Rack → Bin browser with inline CRUD.
//          Lower-friction than three separate index files; all POSTs target
//          this page via the _action parameter.
// Roles allowed: super_admin, warehouse_manager
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager']);

$role = current_user()['role'] ?? '';

// Resolve which warehouse the page is viewing
$accessible_ids = user_warehouse_ids();
$wstmt = db()->prepare(
    "SELECT id, code, name FROM warehouses
      WHERE company_id = ? " .
      ($accessible_ids
          ? 'AND id IN (' . implode(',', array_fill(0, count($accessible_ids), '?')) . ')'
          : 'AND 1 = 0') . "
   ORDER BY code"
);
$wstmt->execute(array_merge([company_id()], $accessible_ids));
$warehouses = $wstmt->fetchAll();

$wid = (int)($_GET['warehouse_id'] ?? $_POST['warehouse_id'] ?? 0);
if ($wid === 0 && $warehouses) {
    $wid = (int)$warehouses[0]['id'];
}
if ($wid > 0) {
    require_warehouse_access($wid);
}

// ---------- POST dispatcher ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = (string)($_POST['_action'] ?? '');

    try {
        switch ($do) {

        // ----- ZONES ----------------------------------------------------------
        case 'zone_save': {
            $zid   = (int)($_POST['zone_id'] ?? 0);
            $code  = strtoupper(trim((string)($_POST['code'] ?? '')));
            $name  = trim((string)($_POST['name'] ?? ''));
            $type  = (string)($_POST['type'] ?? 'GENERAL');
            $stat  = (string)($_POST['status'] ?? 'ACTIVE');
            if (!preg_match('/^[A-Z0-9_\-]{1,32}$/', $code)) throw new RuntimeException('Zone code 1–32 chars (A-Z 0-9 _ -).');
            if ($name === '' || mb_strlen($name) > 191)      throw new RuntimeException('Zone name required.');
            if (!in_array($type, ['GENERAL','COLD','DRY','BULK','RETURNS','PACKING','STAGING','OTHER'], true)) {
                throw new RuntimeException('Invalid zone type.');
            }
            if ($zid > 0) {
                db()->prepare(
                    'UPDATE zones SET code=?, name=?, type=?, status=? WHERE id=? AND warehouse_id=?'
                )->execute([$code, $name, $type, $stat, $zid, $wid]);
                audit_log('zone_update', 'zones', $zid, compact('code','name','type','stat'));
                flash('success', "Zone {$code} updated.");
            } else {
                db()->prepare(
                    'INSERT INTO zones (warehouse_id, code, name, type, status) VALUES (?,?,?,?,?)'
                )->execute([$wid, $code, $name, $type, $stat]);
                audit_log('zone_create', 'zones', (int)db()->lastInsertId(), compact('code','name','type'));
                flash('success', "Zone {$code} created.");
            }
            break;
        }
        case 'zone_delete': {
            $zid = (int)($_POST['zone_id'] ?? 0);
            db()->prepare('DELETE FROM zones WHERE id = ? AND warehouse_id = ?')->execute([$zid, $wid]);
            audit_log('zone_delete', 'zones', $zid);
            flash('success', 'Zone deleted (with its racks and bins).');
            break;
        }

        // ----- RACKS ----------------------------------------------------------
        case 'rack_save': {
            $rid  = (int)($_POST['rack_id'] ?? 0);
            $zid  = (int)($_POST['zone_id'] ?? 0);
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            $name = trim((string)($_POST['name'] ?? ''));
            $stat = (string)($_POST['status'] ?? 'ACTIVE');
            if (!preg_match('/^[A-Z0-9_\-]{1,32}$/', $code)) throw new RuntimeException('Rack code 1–32 chars.');
            // verify zone belongs to this warehouse
            $z = db()->prepare('SELECT id FROM zones WHERE id = ? AND warehouse_id = ?');
            $z->execute([$zid, $wid]);
            if (!$z->fetch()) throw new RuntimeException('Zone not found in this warehouse.');
            if ($rid > 0) {
                db()->prepare('UPDATE racks SET code=?, name=?, status=? WHERE id=? AND zone_id=?')
                    ->execute([$code, $name ?: null, $stat, $rid, $zid]);
                audit_log('rack_update', 'racks', $rid, compact('code','name'));
                flash('success', "Rack {$code} updated.");
            } else {
                db()->prepare('INSERT INTO racks (zone_id, code, name, status) VALUES (?,?,?,?)')
                    ->execute([$zid, $code, $name ?: null, $stat]);
                audit_log('rack_create', 'racks', (int)db()->lastInsertId(), compact('code','name'));
                flash('success', "Rack {$code} created.");
            }
            break;
        }
        case 'rack_delete': {
            $rid = (int)($_POST['rack_id'] ?? 0);
            $zid = (int)($_POST['zone_id'] ?? 0);
            db()->prepare(
                'DELETE r FROM racks r JOIN zones z ON z.id = r.zone_id
                  WHERE r.id = ? AND z.warehouse_id = ?'
            )->execute([$rid, $wid]);
            audit_log('rack_delete', 'racks', $rid);
            flash('success', 'Rack deleted (with its bins).');
            break;
        }

        // ----- BINS -----------------------------------------------------------
        case 'bin_save': {
            $bid     = (int)($_POST['bin_id']  ?? 0);
            $rid     = (int)($_POST['rack_id'] ?? 0);
            $code    = strtoupper(trim((string)($_POST['code'] ?? '')));
            $cap_raw = trim((string)($_POST['capacity_units'] ?? ''));
            $bcode   = trim((string)($_POST['barcode'] ?? ''));
            $pickable = !empty($_POST['pickable']) ? 1 : 0;
            $stat    = (string)($_POST['status'] ?? 'ACTIVE');
            if (!preg_match('/^[A-Z0-9_\-]{1,32}$/', $code)) throw new RuntimeException('Bin code 1–32 chars.');
            if ($cap_raw !== '' && (!is_numeric($cap_raw) || (float)$cap_raw < 0)) throw new RuntimeException('Capacity must be a non-negative number.');
            if (!in_array($stat, ['ACTIVE','BLOCKED','INACTIVE'], true)) throw new RuntimeException('Invalid bin status.');

            $r = db()->prepare(
                'SELECT r.id, r.code AS rack_code, z.code AS zone_code, w.code AS wh_code
                   FROM racks r
                   JOIN zones z ON z.id = r.zone_id
                   JOIN warehouses w ON w.id = z.warehouse_id
                  WHERE r.id = ? AND w.id = ?'
            );
            $r->execute([$rid, $wid]);
            $rk = $r->fetch();
            if (!$rk) throw new RuntimeException('Rack not found in this warehouse.');

            $full_code = "{$rk['wh_code']}/{$rk['zone_code']}/{$rk['rack_code']}/{$code}";
            $bcode_final = $bcode !== '' ? $bcode : $full_code;
            $cap = $cap_raw === '' ? null : (float)$cap_raw;

            if ($bid > 0) {
                db()->prepare(
                    'UPDATE bins
                        SET code=?, full_code=?, barcode=?, capacity_units=?, pickable=?, status=?
                      WHERE id=? AND rack_id=?'
                )->execute([$code, $full_code, $bcode_final, $cap, $pickable, $stat, $bid, $rid]);
                audit_log('bin_update', 'bins', $bid, compact('code','full_code','bcode_final'));
                flash('success', "Bin {$full_code} updated.");
            } else {
                db()->prepare(
                    'INSERT INTO bins (rack_id, code, full_code, barcode, capacity_units, pickable, status)
                     VALUES (?,?,?,?,?,?,?)'
                )->execute([$rid, $code, $full_code, $bcode_final, $cap, $pickable, $stat]);
                audit_log('bin_create', 'bins', (int)db()->lastInsertId(), compact('code','full_code'));
                flash('success', "Bin {$full_code} created.");
            }
            break;
        }
        case 'bin_delete': {
            $bid = (int)($_POST['bin_id'] ?? 0);
            db()->prepare(
                'DELETE b FROM bins b
                   JOIN racks r ON r.id = b.rack_id
                   JOIN zones z ON z.id = r.zone_id
                  WHERE b.id = ? AND z.warehouse_id = ?'
            )->execute([$bid, $wid]);
            audit_log('bin_delete', 'bins', $bid);
            flash('success', 'Bin deleted.');
            break;
        }

        default:
            flash('error', 'Unknown action.');
        }
    } catch (PDOException $e) {
        $msg = ($e->errorInfo[1] ?? 0) === 1062 ? 'Duplicate code or barcode for this scope.' : 'Database error.';
        flash('error', $msg);
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/pages/locations/index.php?warehouse_id=' . $wid);
}

// ---------- Load tree ---------------------------------------------------------
$tree = [];
if ($wid > 0) {
    $z = db()->prepare(
        'SELECT id, code, name, type, status FROM zones WHERE warehouse_id = ? ORDER BY code'
    );
    $z->execute([$wid]);
    foreach ($z->fetchAll() as $zone) {
        $zone['racks'] = [];
        $r = db()->prepare(
            'SELECT id, code, name, status FROM racks WHERE zone_id = ? ORDER BY code'
        );
        $r->execute([$zone['id']]);
        foreach ($r->fetchAll() as $rack) {
            $b = db()->prepare(
                'SELECT id, code, full_code, barcode, capacity_units, pickable, status
                   FROM bins WHERE rack_id = ? ORDER BY code'
            );
            $b->execute([$rack['id']]);
            $rack['bins'] = $b->fetchAll();
            $zone['racks'][] = $rack;
        }
        $tree[] = $zone;
    }
}

$PAGE_TITLE = 'Locations';
require __DIR__ . '/../../partials/header.php';
?>
<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-semibold text-gray-900">Zones, racks &amp; bins</h1>
  <a href="/pages/imports/upload.php?type=bins" class="px-3 py-2 rounded text-sm border border-gray-300 hover:bg-gray-50">Import bins CSV</a>
</div>

<form method="get" class="bg-white border border-gray-200 rounded-lg p-3 mb-4 flex items-end gap-3 text-sm">
  <div>
    <label class="block text-xs font-medium text-gray-500">Warehouse</label>
    <select name="warehouse_id" onchange="this.form.submit()" class="mt-1 rounded border-gray-300 shadow-sm">
      <?php foreach ($warehouses as $w): ?>
        <option value="<?= e_($w['id']) ?>" <?= $wid === (int)$w['id'] ? 'selected' : '' ?>>
          <?= e_($w['code']) ?> — <?= e_($w['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<?php if (!$wid): ?>
  <p class="text-gray-500 text-sm">No warehouses available.</p>
<?php else: ?>

<details class="bg-white border border-gray-200 rounded-lg p-4 mb-4" open>
  <summary class="cursor-pointer text-sm font-semibold text-gray-700">+ Add zone</summary>
  <form method="post" class="mt-3 grid grid-cols-1 sm:grid-cols-5 gap-2 items-end">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="zone_save">
    <input type="hidden" name="warehouse_id" value="<?= e_($wid) ?>">
    <div><label class="block text-xs text-gray-500">Code</label>
      <input type="text" name="code" required pattern="[A-Za-z0-9_\-]{1,32}" maxlength="32"
             style="text-transform:uppercase"
             class="w-full rounded border-gray-300 shadow-sm font-mono text-sm"></div>
    <div class="sm:col-span-2"><label class="block text-xs text-gray-500">Name</label>
      <input type="text" name="name" required maxlength="191" class="w-full rounded border-gray-300 shadow-sm text-sm"></div>
    <div><label class="block text-xs text-gray-500">Type</label>
      <select name="type" class="w-full rounded border-gray-300 shadow-sm text-sm">
        <?php foreach (['GENERAL','COLD','DRY','BULK','RETURNS','PACKING','STAGING','OTHER'] as $t): ?>
          <option value="<?= e_($t) ?>"><?= e_($t) ?></option>
        <?php endforeach; ?>
      </select></div>
    <button class="slv-bg-primary text-white px-3 py-2 rounded text-sm">Add zone</button>
  </form>
</details>

<?php if (!$tree): ?>
  <p class="text-gray-500 text-sm">No zones yet for this warehouse.</p>
<?php endif; ?>

<?php foreach ($tree as $zone): ?>
  <section class="bg-white border border-gray-200 rounded-lg overflow-hidden mb-4">
    <header class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex items-center justify-between">
      <div>
        <span class="font-mono font-semibold"><?= e_($zone['code']) ?></span>
        <span class="text-gray-700"><?= e_($zone['name']) ?></span>
        <span class="text-xs text-gray-400 ml-2"><?= e_($zone['type']) ?> · <?= e_($zone['status']) ?></span>
      </div>
      <div class="flex items-center gap-2">
        <details class="relative">
          <summary class="text-xs text-gray-500 cursor-pointer hover:underline">edit</summary>
          <form method="post" class="absolute right-0 mt-1 z-20 bg-white border border-gray-200 rounded shadow-lg p-3 w-72 grid grid-cols-2 gap-2 text-sm">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="zone_save">
            <input type="hidden" name="warehouse_id" value="<?= e_($wid) ?>">
            <input type="hidden" name="zone_id" value="<?= e_($zone['id']) ?>">
            <div class="col-span-1"><label class="block text-xs text-gray-500">Code</label>
              <input type="text" name="code" required value="<?= e_($zone['code']) ?>" pattern="[A-Za-z0-9_\-]{1,32}"
                     maxlength="32" class="w-full rounded border-gray-300 font-mono text-xs"></div>
            <div class="col-span-1"><label class="block text-xs text-gray-500">Type</label>
              <select name="type" class="w-full rounded border-gray-300 text-xs">
                <?php foreach (['GENERAL','COLD','DRY','BULK','RETURNS','PACKING','STAGING','OTHER'] as $t): ?>
                  <option value="<?= e_($t) ?>" <?= $zone['type'] === $t ? 'selected' : '' ?>><?= e_($t) ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="col-span-2"><label class="block text-xs text-gray-500">Name</label>
              <input type="text" name="name" required value="<?= e_($zone['name']) ?>" class="w-full rounded border-gray-300 text-xs"></div>
            <div class="col-span-1"><label class="block text-xs text-gray-500">Status</label>
              <select name="status" class="w-full rounded border-gray-300 text-xs">
                <?php foreach (['ACTIVE','INACTIVE'] as $s): ?>
                  <option value="<?= e_($s) ?>" <?= $zone['status'] === $s ? 'selected' : '' ?>><?= e_($s) ?></option>
                <?php endforeach; ?>
              </select></div>
            <button class="col-span-1 slv-bg-primary text-white py-1 rounded text-xs">Save</button>
          </form>
        </details>
        <form method="post" class="inline" onsubmit="return confirm('Delete zone <?= e_($zone['code']) ?> (with all racks and bins)?');">
          <?= csrf_field() ?>
          <input type="hidden" name="_action" value="zone_delete">
          <input type="hidden" name="warehouse_id" value="<?= e_($wid) ?>">
          <input type="hidden" name="zone_id" value="<?= e_($zone['id']) ?>">
          <button class="text-xs text-red-600 hover:underline">delete</button>
        </form>
      </div>
    </header>

    <div class="px-4 py-3">
      <details class="mb-3">
        <summary class="cursor-pointer text-xs text-indigo-700 hover:underline">+ Add rack</summary>
        <form method="post" class="mt-2 grid grid-cols-4 gap-2 items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="_action" value="rack_save">
          <input type="hidden" name="warehouse_id" value="<?= e_($wid) ?>">
          <input type="hidden" name="zone_id" value="<?= e_($zone['id']) ?>">
          <div><label class="block text-xs text-gray-500">Code</label>
            <input type="text" name="code" required pattern="[A-Za-z0-9_\-]{1,32}" maxlength="32"
                   style="text-transform:uppercase" class="w-full rounded border-gray-300 font-mono text-sm"></div>
          <div class="col-span-2"><label class="block text-xs text-gray-500">Name (optional)</label>
            <input type="text" name="name" maxlength="191" class="w-full rounded border-gray-300 text-sm"></div>
          <button class="slv-bg-primary text-white py-1 rounded text-sm">Add rack</button>
        </form>
      </details>

      <?php foreach ($zone['racks'] as $rack): ?>
        <div class="border border-gray-200 rounded mb-3">
          <div class="px-3 py-2 bg-gray-50 border-b border-gray-200 flex items-center justify-between text-sm">
            <div>
              <span class="font-mono font-medium"><?= e_($rack['code']) ?></span>
              <?php if ($rack['name']): ?><span class="text-gray-700">— <?= e_($rack['name']) ?></span><?php endif; ?>
              <span class="text-xs text-gray-400 ml-2"><?= e_($rack['status']) ?></span>
            </div>
            <form method="post" class="inline" onsubmit="return confirm('Delete rack <?= e_($rack['code']) ?> (with bins)?');">
              <?= csrf_field() ?>
              <input type="hidden" name="_action" value="rack_delete">
              <input type="hidden" name="warehouse_id" value="<?= e_($wid) ?>">
              <input type="hidden" name="zone_id" value="<?= e_($zone['id']) ?>">
              <input type="hidden" name="rack_id" value="<?= e_($rack['id']) ?>">
              <button class="text-xs text-red-600 hover:underline">delete</button>
            </form>
          </div>
          <div class="p-3">
            <details class="mb-2">
              <summary class="cursor-pointer text-xs text-indigo-700 hover:underline">+ Add bin</summary>
              <form method="post" class="mt-2 grid grid-cols-12 gap-2 items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="bin_save">
                <input type="hidden" name="warehouse_id" value="<?= e_($wid) ?>">
                <input type="hidden" name="rack_id" value="<?= e_($rack['id']) ?>">
                <div class="col-span-2"><label class="block text-xs text-gray-500">Bin code</label>
                  <input type="text" name="code" required pattern="[A-Za-z0-9_\-]{1,32}" maxlength="32"
                         style="text-transform:uppercase" class="w-full rounded border-gray-300 font-mono text-xs"></div>
                <div class="col-span-2"><label class="block text-xs text-gray-500">Capacity</label>
                  <input type="number" step="0.0001" min="0" name="capacity_units" class="w-full rounded border-gray-300 text-xs"></div>
                <div class="col-span-4"><label class="block text-xs text-gray-500">Barcode (blank = full code)</label>
                  <input type="text" name="barcode" maxlength="128" class="w-full rounded border-gray-300 font-mono text-xs"></div>
                <div class="col-span-2 flex items-center gap-1 pt-4">
                  <input type="checkbox" name="pickable" value="1" checked class="rounded border-gray-300">
                  <span class="text-xs">Pickable</span>
                </div>
                <button class="col-span-2 slv-bg-primary text-white py-1 rounded text-xs">Add</button>
              </form>
            </details>

            <?php if ($rack['bins']): ?>
              <table class="min-w-full text-xs">
                <thead class="text-gray-500">
                  <tr>
                    <th class="text-left py-1">Code</th>
                    <th class="text-left py-1">Full code</th>
                    <th class="text-left py-1">Barcode</th>
                    <th class="text-right py-1">Capacity</th>
                    <th class="text-left py-1">Pickable</th>
                    <th class="text-left py-1">Status</th>
                    <th class="py-1"></th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <?php foreach ($rack['bins'] as $bin): ?>
                    <tr>
                      <td class="py-1 font-mono"><?= e_($bin['code']) ?></td>
                      <td class="py-1 font-mono text-gray-500"><?= e_($bin['full_code']) ?></td>
                      <td class="py-1 font-mono text-gray-500"><?= e_($bin['barcode']) ?></td>
                      <td class="py-1 text-right"><?= $bin['capacity_units'] === null ? '—' : e_(qty($bin['capacity_units'])) ?></td>
                      <td class="py-1"><?= ((int)$bin['pickable']) ? 'Yes' : 'No' ?></td>
                      <td class="py-1"><?= e_($bin['status']) ?></td>
                      <td class="py-1 text-right whitespace-nowrap">
                        <details class="inline-block">
                          <summary class="cursor-pointer text-indigo-700 hover:underline">edit</summary>
                          <form method="post" class="mt-2 grid grid-cols-12 gap-1 items-end bg-gray-50 border border-gray-200 p-2 rounded">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="bin_save">
                            <input type="hidden" name="warehouse_id" value="<?= e_($wid) ?>">
                            <input type="hidden" name="rack_id" value="<?= e_($rack['id']) ?>">
                            <input type="hidden" name="bin_id" value="<?= e_($bin['id']) ?>">
                            <div class="col-span-2"><label class="block text-xs text-gray-500">Code</label>
                              <input type="text" name="code" required value="<?= e_($bin['code']) ?>" pattern="[A-Za-z0-9_\-]{1,32}"
                                     class="w-full rounded border-gray-300 font-mono text-xs"></div>
                            <div class="col-span-2"><label class="block text-xs text-gray-500">Cap</label>
                              <input type="number" step="0.0001" name="capacity_units" value="<?= e_((string)$bin['capacity_units']) ?>"
                                     class="w-full rounded border-gray-300 text-xs"></div>
                            <div class="col-span-3"><label class="block text-xs text-gray-500">Barcode</label>
                              <input type="text" name="barcode" value="<?= e_($bin['barcode']) ?>"
                                     class="w-full rounded border-gray-300 font-mono text-xs"></div>
                            <div class="col-span-2 flex items-center gap-1 pt-4">
                              <input type="checkbox" name="pickable" value="1" <?= ((int)$bin['pickable']) ? 'checked' : '' ?> class="rounded border-gray-300">
                              <span>Pick</span>
                            </div>
                            <div class="col-span-2"><label class="block text-xs text-gray-500">Status</label>
                              <select name="status" class="w-full rounded border-gray-300 text-xs">
                                <?php foreach (['ACTIVE','BLOCKED','INACTIVE'] as $s): ?>
                                  <option value="<?= e_($s) ?>" <?= $bin['status'] === $s ? 'selected' : '' ?>><?= e_($s) ?></option>
                                <?php endforeach; ?>
                              </select></div>
                            <button class="col-span-1 slv-bg-primary text-white py-1 rounded text-xs">Save</button>
                          </form>
                        </details>
                        <form method="post" class="inline" onsubmit="return confirm('Delete bin <?= e_($bin['full_code']) ?>?');">
                          <?= csrf_field() ?>
                          <input type="hidden" name="_action" value="bin_delete">
                          <input type="hidden" name="warehouse_id" value="<?= e_($wid) ?>">
                          <input type="hidden" name="bin_id" value="<?= e_($bin['id']) ?>">
                          <button class="ml-2 text-red-600 hover:underline">delete</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <p class="text-xs text-gray-400">No bins in this rack.</p>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <?php if (!$zone['racks']): ?>
        <p class="text-xs text-gray-400">No racks in this zone.</p>
      <?php endif; ?>
    </div>
  </section>
<?php endforeach; ?>

<?php endif; // end if $wid ?>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
