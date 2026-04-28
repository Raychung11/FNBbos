<?php
use FNBBOS\Auth;

$siteTitle = $siteTitle ?? config('app.name');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($siteTitle) ?> — F&amp;B Operating System for Malaysia</title>
<meta name="description" content="Centralise sales from every delivery platform, calculate SST and platform fees automatically, reconcile bank settlements, and catch suspicious claims before they hit your bottom line.">
<link rel="stylesheet" href="<?= e(url('assets/css/site.css')) ?>">
</head>
<body>
<header class="site-nav">
  <div class="wrap site-nav__inner">
    <a href="<?= e(url('index.php')) ?>" class="site-nav__brand">
      <span class="logo"></span>
      <span><?= e(config('app.name')) ?></span>
    </a>
    <nav class="site-nav__links">
      <a href="#features">Features</a>
      <a href="#how">How it works</a>
      <a href="#demo">Request demo</a>
    </nav>
    <div class="site-nav__cta">
      <?php if (Auth::check()): ?>
        <a class="btn btn--ghost" href="<?= e(url('pages/dashboard.php')) ?>">Open dashboard →</a>
      <?php else: ?>
        <a class="btn btn--ghost" href="<?= e(url('login.php')) ?>">Login</a>
        <a class="btn btn--primary" href="#demo">Request demo</a>
      <?php endif; ?>
    </div>
  </div>
</header>
