<?php
// SLV WMS — m/home.php
// Purpose: Mobile home — task tiles based on role + warehouse picker.
//          Phase 0 ships the shell only; tiles link to placeholders in
//          subsequent phases.
// Roles allowed: any logged-in role
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_login();

$user    = current_user();
$role    = $user['role'] ?? 'viewer';
$company = setting('brand.company_name', 'SLV Group Sdn. Bhd.');
$primary = setting('brand.primary_color', '#6D28D9');

$accessible_ids = user_warehouse_ids();
$warehouses = [];
if ($accessible_ids) {
    $in = implode(',', array_fill(0, count($accessible_ids), '?'));
    $stmt = db()->prepare(
        "SELECT id, code, name FROM warehouses
          WHERE company_id = ? AND id IN ($in)
          ORDER BY code"
    );
    $stmt->execute(array_merge([company_id()], $accessible_ids));
    $warehouses = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'set_warehouse') {
    verify_csrf();
    $raw = $_POST['warehouse_id'] ?? '';
    try {
        set_selected_warehouse_id($raw === '' ? null : (int)$raw);
    } catch (Throwable $e) {
        flash('error', 'No access to that warehouse.');
    }
    redirect('/m/home.php');
}
$selected = selected_warehouse_id();

// Role → available tasks
$tasks = [
    'receiver'         => ['receive', 'putaway'],
    'picker'           => ['pick'],
    'packer'           => ['pick'],
    'driver'           => ['dispatch'],
    'warehouse_manager'=> ['receive','putaway','pick','transfer','iw_dispatch','iw_receive','count','dispatch'],
    'super_admin'      => ['receive','putaway','pick','transfer','iw_dispatch','iw_receive','count','dispatch'],
];
$allowed = $tasks[$role] ?? [];

$catalogue = [
    'receive'     => ['Receive (GRN)',          '/m/receive.php',     'Phase 5'],
    'putaway'     => ['Putaway',                '/m/putaway.php',     'Phase 5'],
    'pick'        => ['Pick',                   '/m/pick.php',        'Phase 7'],
    'transfer'    => ['Bin transfer',           '/m/transfer.php',    'Phase 11'],
    'iw_dispatch' => ['Inter-warehouse send',   '/m/iw_dispatch.php', 'Phase 11'],
    'iw_receive'  => ['Inter-warehouse receive','/m/iw_receive.php',  'Phase 11'],
    'count'       => ['Cycle count',            '/m/count.php',       'Phase 10'],
    'dispatch'    => ['Driver / POD',           '/m/dispatch.php',    'Phase 9'],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e_($primary) ?>">
<title><?= e_($company) ?> · Scanner</title>
<link rel="manifest" href="/manifest.json">
<script src="https://cdn.tailwindcss.com"></script>
<style>:root{--slv-primary:<?= e_($primary) ?>;}.slv-bg-primary{background:var(--slv-primary);}</style>
</head>
<body class="min-h-screen bg-gray-50">
<header class="slv-bg-primary text-white">
  <div class="px-4 py-3 flex items-center justify-between">
    <div>
      <div class="text-xs opacity-80"><?= e_($company) ?></div>
      <div class="text-base font-semibold">Hi, <?= e_($user['name']) ?></div>
    </div>
    <a href="/logout.php" class="text-sm underline opacity-90">Sign out</a>
  </div>
</header>

<main class="p-4 space-y-4">
  <form method="post" class="bg-white rounded-lg border border-gray-200 p-4">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="set_warehouse">
    <label class="block text-xs font-medium text-gray-500">Active warehouse</label>
    <select name="warehouse_id" onchange="this.form.submit()"
            class="mt-1 w-full rounded border-gray-300 text-base">
      <option value="">All / unset</option>
      <?php foreach ($warehouses as $w): ?>
        <option value="<?= e_($w['id']) ?>" <?= $selected === (int)$w['id'] ? 'selected' : '' ?>>
          <?= e_($w['code']) ?> — <?= e_($w['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php if (!$allowed): ?>
    <div class="bg-amber-50 border border-amber-200 rounded p-4 text-sm text-amber-800">
      Your role (<?= e_($role) ?>) has no mobile tasks assigned. Use the
      <a href="/index.php" class="underline">desktop dashboard</a>.
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-2 gap-3">
    <?php foreach ($allowed as $key):
      [$label, $href, $phase] = $catalogue[$key]; ?>
      <a href="<?= e_($href) ?>"
         class="block bg-white rounded-xl border border-gray-200 p-4 text-center active:bg-gray-100">
        <div class="text-base font-semibold text-gray-900"><?= e_($label) ?></div>
        <div class="text-xs text-gray-400 mt-1">ships in <?= e_($phase) ?></div>
      </a>
    <?php endforeach; ?>
  </div>

  <p class="text-center text-xs text-gray-400 pt-4">
    SLV WMS Phase 0 — scanner shell deployed.
  </p>
</main>

<script>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/service-worker.js').catch(() => {});
    });
  }
</script>
</body>
</html>
