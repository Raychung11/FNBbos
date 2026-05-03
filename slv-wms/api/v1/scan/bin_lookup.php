<?php
// SLV WMS — /api/v1/scan/bin_lookup.php
// GET — resolve a bin barcode / full_code / short code (last segment) to a
//        bin, scoped to a warehouse the user can access.
// Query params:
//   barcode      (required) value scanned/typed
//   warehouse_id (required) where the bin must live
// Roles allowed: any logged-in role with scan privileges.
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/_common.php';
api_scan_boot();

$barcode = trim((string)($_GET['barcode']      ?? ''));
$wid     = (int)($_GET['warehouse_id'] ?? 0);
if ($barcode === '') json_error(400, 'barcode is required.');
api_scan_require_warehouse($wid);

// 1) Try the bin's own barcode column (UNIQUE).
$stmt = db()->prepare(
    "SELECT b.id, b.code, b.full_code, b.barcode, b.capacity_units,
            b.pickable, b.status, z.code AS zone_code
       FROM bins b
       JOIN racks r ON r.id = b.rack_id
       JOIN zones z ON z.id = r.zone_id
      WHERE z.warehouse_id = ? AND b.barcode = ?
      LIMIT 1"
);
$stmt->execute([$wid, $barcode]);
$row = $stmt->fetch();

// 2) Fall back to full_code (e.g. WH01/Z-A/R01/B03).
if (!$row) {
    $stmt = db()->prepare(
        "SELECT b.id, b.code, b.full_code, b.barcode, b.capacity_units,
                b.pickable, b.status, z.code AS zone_code
           FROM bins b
           JOIN racks r ON r.id = b.rack_id
           JOIN zones z ON z.id = r.zone_id
          WHERE z.warehouse_id = ? AND b.full_code = ?
          LIMIT 1"
    );
    $stmt->execute([$wid, $barcode]);
    $row = $stmt->fetch();
}

// 3) Fall back to short code, but only if unambiguous within the warehouse.
if (!$row) {
    $stmt = db()->prepare(
        "SELECT b.id, b.code, b.full_code, b.barcode, b.capacity_units,
                b.pickable, b.status, z.code AS zone_code
           FROM bins b
           JOIN racks r ON r.id = b.rack_id
           JOIN zones z ON z.id = r.zone_id
          WHERE z.warehouse_id = ? AND b.code = ?
          LIMIT 2"
    );
    $stmt->execute([$wid, strtoupper($barcode)]);
    $hits = $stmt->fetchAll();
    if (count($hits) === 1) $row = $hits[0];
}

if (!$row)                          json_error(404, 'No bin matches in this warehouse.');
if ($row['status'] !== 'ACTIVE')    json_error(409, "Bin {$row['full_code']} is {$row['status']}.");

json_ok([
    'bin' => [
        'id'             => (int)$row['id'],
        'code'           => $row['code'],
        'full_code'      => $row['full_code'],
        'barcode'        => $row['barcode'],
        'zone_code'      => $row['zone_code'],
        'capacity_units' => $row['capacity_units'] === null ? null : (float)$row['capacity_units'],
        'pickable'       => (int)$row['pickable'] === 1,
    ],
]);
