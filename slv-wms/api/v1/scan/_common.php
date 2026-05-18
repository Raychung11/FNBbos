<?php
// SLV WMS — api/v1/scan/_common.php
// Shared bootstrapping for the JSON scan endpoints.
// Every endpoint requires this and immediately calls api_scan_boot($roles).

declare(strict_types=1);

require __DIR__ . '/../../../lib/bootstrap.php';

/**
 * Establish auth + read JSON body (if any) into a single helper.
 * Roles default to the set that should be able to scan stock movements.
 */
function api_scan_boot(array $roles = ['super_admin','warehouse_manager','receiver','picker','packer','driver']): array
{
    require_login();
    require_role($roles);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
    }

    $body = $_POST;
    $ct   = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if (strpos($ct, 'application/json') !== false) {
        $raw  = file_get_contents('php://input');
        $body = $raw === false || $raw === '' ? [] : (json_decode($raw, true) ?: []);
    }
    return is_array($body) ? $body : [];
}

/** Validate a warehouse the requester can actually use. */
function api_scan_require_warehouse(int $warehouse_id): void
{
    if ($warehouse_id <= 0) {
        json_error(400, 'warehouse_id is required.');
    }
    require_warehouse_access($warehouse_id);
}
