<?php
// SLV WMS — lib/bootstrap.php
// Purpose: Single include that wires up config + lib + session.
//          Every entry point (page, api endpoint) starts with:
//              require __DIR__ . '/lib/bootstrap.php';
// Roles allowed: n/a
// Last updated: 2026-04-29

declare(strict_types=1);

require __DIR__ . '/../config/db.php';
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/csrf.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/docnum.php';
require __DIR__ . '/csv.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/stock.php';
require __DIR__ . '/tax.php';
require __DIR__ . '/grn.php';
require __DIR__ . '/so.php';

session_boot();
