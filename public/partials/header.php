<?php
use FNBBOS\Auth;
use FNBBOS\Rbac;

$pageTitle = $pageTitle ?? 'F&B BOS';
$active = $active ?? '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> — <?= e(config('app.name')) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
<script defer src="<?= e(url('assets/js/app.js')) ?>"></script>
</head>
<body>
<div class="layout">
<aside class="sidebar">
  <h1><?= e(config('app.name')) ?></h1>
  <span class="tag">Malaysian F&amp;B • Multi-outlet</span>
  <nav>
    <a href="<?= e(url('pages/dashboard.php')) ?>"       class="<?= $active==='dashboard'?'active':'' ?>">Dashboard</a>
    <div class="section">Sales</div>
    <a href="<?= e(url('pages/sales-import.php')) ?>"    class="<?= $active==='sales-import'?'active':'' ?>">Sales Import</a>
    <a href="<?= e(url('pages/sales-list.php')) ?>"      class="<?= $active==='sales-list'?'active':'' ?>">Sales Orders</a>
    <a href="<?= e(url('pages/reconciliation.php')) ?>"  class="<?= $active==='reconciliation'?'active':'' ?>">Fee Reconciliation</a>
    <a href="<?= e(url('pages/bank-import.php')) ?>"     class="<?= $active==='bank-import'?'active':'' ?>">Bank Reconciliation</a>
    <div class="section">Claims</div>
    <a href="<?= e(url('pages/claim-submit.php')) ?>"    class="<?= $active==='claim-submit'?'active':'' ?>">Submit Claim</a>
    <a href="<?= e(url('pages/claims.php')) ?>"          class="<?= $active==='claims'?'active':'' ?>">Claims</a>
    <a href="<?= e(url('pages/claim-approve.php')) ?>"   class="<?= $active==='claim-approve'?'active':'' ?>">Approval Queue</a>
    <div class="section">Setup</div>
    <a href="<?= e(url('pages/companies.php')) ?>"       class="<?= $active==='companies'?'active':'' ?>">Companies &amp; Brands</a>
    <a href="<?= e(url('pages/outlets.php')) ?>"         class="<?= $active==='outlets'?'active':'' ?>">Outlets</a>
    <a href="<?= e(url('pages/users.php')) ?>"           class="<?= $active==='users'?'active':'' ?>">Users &amp; Roles</a>
    <a href="<?= e(url('pages/platforms.php')) ?>"       class="<?= $active==='platforms'?'active':'' ?>">Platforms &amp; Fees</a>
    <a href="<?= e(url('pages/tax-profiles.php')) ?>"    class="<?= $active==='tax-profiles'?'active':'' ?>">Tax Profiles</a>
    <a href="<?= e(url('pages/approval-rules.php')) ?>"  class="<?= $active==='approval-rules'?'active':'' ?>">Approval Rules</a>
    <div class="section">Reports</div>
    <a href="<?= e(url('pages/insights.php')) ?>"        class="<?= $active==='insights'?'active':'' ?>">Insights (charts)</a>
    <a href="<?= e(url('pages/forecast.php')) ?>"        class="<?= $active==='forecast'?'active':'' ?>">Forecast</a>
    <a href="<?= e(url('pages/leakage.php')) ?>"         class="<?= $active==='leakage'?'active':'' ?>">Leakage Predictions</a>
    <a href="<?= e(url('pages/reports.php')) ?>"         class="<?= $active==='reports'?'active':'' ?>">Reports &amp; Exports</a>
    <a href="<?= e(url('pages/audit-logs.php')) ?>"      class="<?= $active==='audit-logs'?'active':'' ?>">Audit Logs</a>
    <?php if (Rbac::can('platforms.sync')): ?>
      <div class="section">Integrations</div>
      <a href="<?= e(url('pages/platform-sync.php')) ?>" class="<?= $active==='platform-sync'?'active':'' ?>">Platform API Sync</a>
    <?php endif; ?>
    <?php if (Rbac::can('demo.review')): ?>
      <div class="section">Sales / SaaS</div>
      <a href="<?= e(url('pages/demo-requests.php')) ?>" class="<?= $active==='demo-requests'?'active':'' ?>">Demo Requests</a>
      <a href="<?= e(url('index.php')) ?>" target="_blank">View public site ↗</a>
    <?php endif; ?>
  </nav>
</aside>
<div class="main">
  <div class="topbar">
    <div class="scope">
      <strong><?= e($pageTitle) ?></strong>
    </div>
    <div class="who">
      Signed in as <strong><?= e(Auth::user()['name'] ?? '') ?></strong>
      <span class="badge badge--muted"><?= e(Rbac::role()) ?></span>
      <a href="<?= e(url('logout.php')) ?>">Logout</a>
    </div>
  </div>
  <div class="content">
    <?php if ($msg = flash('error')): ?><div class="alert alert--error"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($msg = flash('ok')):    ?><div class="alert alert--ok"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($msg = flash('info')):  ?><div class="alert alert--info"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($msg = flash('warn')):  ?><div class="alert alert--warn"><?= e($msg) ?></div><?php endif; ?>
