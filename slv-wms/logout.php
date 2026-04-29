<?php
// SLV WMS — logout.php
// Purpose: Destroy session and redirect to login.
// Roles allowed: any
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

logout();
redirect('/login.php');
