<?php
// SLV WMS — pages/settings/smtp.php
// Purpose: Persist SMTP credentials + notification toggles.
//          Test send activates after PHPMailer is dropped into vendor_local/
//          (Phase 12 — daily aging / low-stock email cron).
// Roles allowed: super_admin
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role('super_admin');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $host       = trim((string)($_POST['host']       ?? ''));
    $port       = trim((string)($_POST['port']       ?? ''));
    $secure     = (string)($_POST['secure']          ?? 'tls'); // tls | ssl | none
    $user       = trim((string)($_POST['user']       ?? ''));
    $pass       = (string)($_POST['pass']            ?? '');
    $from_email = trim((string)($_POST['from_email'] ?? ''));
    $from_name  = trim((string)($_POST['from_name']  ?? ''));
    $notif_low  = !empty($_POST['notify_low_stock']);
    $notif_dig  = !empty($_POST['notify_daily_digest']);

    if ($host !== '' && !preg_match('/^[a-z0-9.\-]+$/i', $host)) $errors[] = 'Host looks invalid.';
    if ($port !== '' && (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535))
                                                                $errors[] = 'Port must be 1–65535.';
    if (!in_array($secure, ['tls','ssl','none'], true))         $errors[] = 'Encryption must be tls, ssl or none.';
    if ($from_email !== '' && !filter_var($from_email, FILTER_VALIDATE_EMAIL))
                                                                $errors[] = 'From email is not a valid address.';

    if (!$errors) {
        setting_set('smtp.host',       $host,       'string');
        setting_set('smtp.port',       $port === '' ? '' : (int)$port, $port === '' ? 'string' : 'int');
        setting_set('smtp.secure',     $secure,     'string');
        setting_set('smtp.user',       $user,       'string');
        if ($pass !== '') {
            setting_set('smtp.pass', $pass, 'string');
        }
        setting_set('smtp.from_email', $from_email, 'string');
        setting_set('smtp.from_name',  $from_name,  'string');
        setting_set('notify.low_stock',     $notif_low ? '1' : '0', 'bool');
        setting_set('notify.daily_digest',  $notif_dig ? '1' : '0', 'bool');

        audit_log('settings_update', 'smtp', null, ['host' => $host, 'from_email' => $from_email]);
        flash('success', 'Email settings saved.');
        redirect('/pages/settings/smtp.php');
    }
}

$host       = (string)setting('smtp.host', '');
$port       = setting('smtp.port', 587);
$secure     = (string)setting('smtp.secure', 'tls');
$user       = (string)setting('smtp.user', '');
$pass_set   = setting('smtp.pass', '') !== '';
$from_email = (string)setting('smtp.from_email', '');
$from_name  = (string)setting('smtp.from_name', '');
$notif_low  = (bool)setting('notify.low_stock', false);
$notif_dig  = (bool)setting('notify.daily_digest', false);

$PAGE_TITLE   = 'Email (SMTP)';
$SETTINGS_TAB = 'smtp';
require __DIR__ . '/../../partials/header.php';
require __DIR__ . '/../../partials/settings_nav.php';
?>
<h1 class="text-2xl font-semibold text-gray-900 mb-2">Email (SMTP)</h1>
<p class="text-sm text-gray-500 mb-6">
  Used for low-stock alerts and the daily ops digest. Test-send is wired
  up after the PHPMailer drop in <code>vendor_local/</code>.
</p>

<?php if ($errors): ?>
  <div class="mb-4 border border-red-200 bg-red-50 text-red-800 rounded p-3 text-sm">
    <ul class="list-disc list-inside">
      <?php foreach ($errors as $err): ?><li><?= e_($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" class="space-y-6 max-w-2xl">
  <?= csrf_field() ?>
  <section class="bg-white border border-gray-200 rounded-lg p-5">
    <h2 class="text-base font-semibold text-gray-900 mb-4">SMTP server</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Host</label>
        <input type="text" name="host" value="<?= e_($host) ?>"
               placeholder="smtp.hostinger.com"
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Port</label>
        <input type="number" name="port" value="<?= e_((string)$port) ?>" min="1" max="65535"
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Encryption</label>
        <select name="secure" class="mt-1 w-full rounded border-gray-300 shadow-sm">
          <?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL', 'none' => 'None'] as $k => $v): ?>
            <option value="<?= e_($k) ?>" <?= $secure === $k ? 'selected' : '' ?>><?= e_($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Username</label>
        <input type="text" name="user" value="<?= e_($user) ?>" autocomplete="off"
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">
          Password
          <?php if ($pass_set): ?>
            <span class="text-xs text-gray-400">— stored; leave blank to keep</span>
          <?php endif; ?>
        </label>
        <input type="password" name="pass" value="" autocomplete="new-password"
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
    </div>
  </section>

  <section class="bg-white border border-gray-200 rounded-lg p-5">
    <h2 class="text-base font-semibold text-gray-900 mb-4">Sender identity</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700">From email</label>
        <input type="email" name="from_email" value="<?= e_($from_email) ?>" placeholder="ops@slv.local"
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">From name</label>
        <input type="text" name="from_name" value="<?= e_($from_name) ?>" placeholder="SLV WMS"
               class="mt-1 w-full rounded border-gray-300 shadow-sm">
      </div>
    </div>
  </section>

  <section class="bg-white border border-gray-200 rounded-lg p-5">
    <h2 class="text-base font-semibold text-gray-900 mb-4">Notifications</h2>
    <div class="space-y-2">
      <label class="inline-flex items-center gap-2">
        <input type="checkbox" name="notify_low_stock"    value="1" <?= $notif_low ? 'checked' : '' ?>
               class="rounded border-gray-300">
        <span class="text-sm">Email low-stock alerts (per warehouse)</span>
      </label><br>
      <label class="inline-flex items-center gap-2">
        <input type="checkbox" name="notify_daily_digest" value="1" <?= $notif_dig ? 'checked' : '' ?>
               class="rounded border-gray-300">
        <span class="text-sm">Email daily ops digest (02:00 cron)</span>
      </label>
    </div>
  </section>

  <div class="flex justify-end gap-3">
    <a href="/pages/settings/index.php" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    <button type="submit" class="slv-bg-primary text-white px-4 py-2 rounded text-sm font-medium">Save email settings</button>
  </div>
</form>

<?php require __DIR__ . '/../../partials/footer.php'; ?>
