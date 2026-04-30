<?php
// SLV WMS — config/app.example.php
// Purpose: Template for config/app.php. Copy this file to config/app.php and edit.
// Roles allowed: n/a (deployment-time)
// Last updated: 2026-04-29

return [
    // -----------------------------------------------------------------
    // Database (Hostinger MySQL).
    // -----------------------------------------------------------------
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'slv_wms',
        'user'    => 'slv_wms',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    // -----------------------------------------------------------------
    // App identity.
    // -----------------------------------------------------------------
    'app' => [
        // Active company id. Multi-company is future-proofed; v1 = 1.
        'company_id'    => 1,

        // Public base URL (no trailing slash). Used for absolute links
        // (PDF QR codes, password-reset emails, manifest start_url).
        'base_url'      => 'https://wms.example.com',

        // Set to true on production (hides PHP errors from end users).
        'production'    => true,

        // Session cookie name; bump if you ever re-key sessions.
        'session_name'  => 'slvwms_sess',

        // Send session cookies only over HTTPS. Treated as a *ceiling* —
        // the runtime auto-disables this on a plain-HTTP request to avoid
        // breaking sessions before SSL is provisioned. Leaving it true is
        // safe and recommended.
        'cookie_secure' => true,

        // Default timezone for date formatting.
        'timezone'      => 'Asia/Kuala_Lumpur',
    ],
];
