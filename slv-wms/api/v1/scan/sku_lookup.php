<?php
// SLV WMS — /api/v1/scan/sku_lookup.php
// GET — resolve a barcode (or sku_code as fallback) to an active SKU.
// Query params: barcode (required)
// Roles allowed: any logged-in role with scan privileges.
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/_common.php';
api_scan_boot();

$barcode = trim((string)($_GET['barcode'] ?? ''));
if ($barcode === '') json_error(400, 'barcode is required.');

// Try the barcode table first (UNIQUE so at most one hit).
$stmt = db()->prepare(
    "SELECT p.id, p.sku_code, p.name, p.uom, p.selling_price,
            pb.barcode, pb.type, pb.is_primary
       FROM product_barcodes pb
       JOIN products p ON p.id = pb.product_id
      WHERE pb.barcode = ? AND p.company_id = ? AND p.status = 'ACTIVE'
      LIMIT 1"
);
$stmt->execute([$barcode, company_id()]);
$row = $stmt->fetch();

// Fall back to direct sku_code match (people often type the code).
if (!$row) {
    $alt = db()->prepare(
        "SELECT p.id, p.sku_code, p.name, p.uom, p.selling_price,
                NULL AS barcode, NULL AS type, 0 AS is_primary
           FROM products p
          WHERE p.sku_code = ? AND p.company_id = ? AND p.status = 'ACTIVE'
          LIMIT 1"
    );
    $alt->execute([strtoupper($barcode), company_id()]);
    $row = $alt->fetch();
}

if (!$row) json_error(404, 'No SKU matches that barcode.');

json_ok([
    'product' => [
        'id'             => (int)$row['id'],
        'sku_code'       => $row['sku_code'],
        'name'           => $row['name'],
        'uom'            => $row['uom'],
        'selling_price'  => (float)$row['selling_price'],
        'matched_barcode'=> $row['barcode'],
        'matched_type'   => $row['type'],
    ],
]);
