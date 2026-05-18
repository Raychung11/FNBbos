<?php
// SLV WMS — pages/users/_form_data.php
// Purpose: Shared role catalogue + warehouse list used by create.php / edit.php.
// Last updated: 2026-04-29

declare(strict_types=1);

/** Role => human label. Source of truth: lib/auth.php role enum. */
$ROLES = [
    'super_admin'       => 'Super admin (full access, all warehouses)',
    'warehouse_manager' => 'Warehouse manager',
    'receiver'          => 'Receiver',
    'picker'            => 'Picker',
    'packer'            => 'Packer',
    'driver'            => 'Driver',
    'sales'             => 'Sales',
    'viewer'            => 'Viewer (read-only)',
];

$wstmt = db()->prepare(
    "SELECT id, code, name, status FROM warehouses
      WHERE company_id = ? ORDER BY code"
);
$wstmt->execute([company_id()]);
$ALL_WAREHOUSES = $wstmt->fetchAll();
