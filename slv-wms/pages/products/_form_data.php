<?php
// SLV WMS — pages/products/_form_data.php
// Purpose: Shared dropdown options for create.php / edit.php.
// Last updated: 2026-04-29

declare(strict_types=1);

$cstmt = db()->prepare(
    "SELECT id, name, parent_id FROM categories
      WHERE company_id = ? ORDER BY name"
);
$cstmt->execute([company_id()]);
$CATEGORIES = $cstmt->fetchAll();

$tstmt = db()->prepare(
    "SELECT id, code, name FROM tax_groups
      WHERE company_id = ? AND is_active = 1 ORDER BY code"
);
$tstmt->execute([company_id()]);
$TAX_GROUPS = $tstmt->fetchAll();

$BARCODE_TYPES = ['EAN','UPC','QR','INTERNAL','OTHER'];
$UOMS          = ['pcs','box','carton','case','pallet','kg','g','l','ml','m'];
