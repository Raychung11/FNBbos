<?php
// SLV WMS — index.php
// Purpose: Front controller for the desktop UI. Forwards to dashboard
//          (logged-in) or login page (anonymous).
// Roles allowed: any
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

if (!is_logged_in()) {
    redirect('/login.php');
}

require __DIR__ . '/pages/dashboard.php';
