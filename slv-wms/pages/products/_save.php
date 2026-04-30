<?php
// SLV WMS — pages/products/_save.php
// Purpose: Shared validation + persistence for create.php / edit.php.
//          Returns [errors, values, barcodes]. Caller redirects on success.
// Last updated: 2026-04-29

declare(strict_types=1);

/**
 * @param array|null $existing  product row when editing, null when creating
 * @return array{0:array,1:array,2:array} [errors, values, barcodes_normalised]
 */
function products_save_post(?array $existing = null): array
{
    $errors = [];
    $values = [
        'sku_code'             => strtoupper(trim((string)($_POST['sku_code']             ?? ''))),
        'name'                 => trim((string)($_POST['name']                 ?? '')),
        'category_id'          => ($_POST['category_id']          ?? '') === '' ? null : (int)$_POST['category_id'],
        'default_tax_group_id' => ($_POST['default_tax_group_id'] ?? '') === '' ? null : (int)$_POST['default_tax_group_id'],
        'uom'                  => (string)($_POST['uom']                  ?? 'pcs'),
        'pack_size'            => (string)($_POST['pack_size']            ?? '1'),
        'weight_kg'            => trim((string)($_POST['weight_kg']       ?? '')),
        'selling_price'        => (string)($_POST['selling_price']        ?? '0'),
        'min_qty'              => (string)($_POST['min_qty']              ?? '0'),
        'max_qty'              => (string)($_POST['max_qty']              ?? '0'),
        'reorder_point'        => (string)($_POST['reorder_point']        ?? '0'),
        'status'               => (string)($_POST['status']               ?? 'ACTIVE'),
        'notes'                => trim((string)($_POST['notes']           ?? '')),
    ];

    $barcodesIn = isset($_POST['barcodes']) && is_array($_POST['barcodes']) ? $_POST['barcodes'] : [];
    $barcodes = [];
    foreach ($barcodesIn as $b) {
        $code = trim((string)($b['barcode'] ?? ''));
        if ($code === '') continue;
        $barcodes[] = [
            'barcode'    => $code,
            'type'       => in_array((string)($b['type'] ?? 'INTERNAL'), ['EAN','UPC','QR','INTERNAL','OTHER'], true)
                              ? (string)$b['type'] : 'INTERNAL',
            'is_primary' => !empty($b['is_primary']) ? 1 : 0,
        ];
    }

    if (!preg_match('/^[A-Z0-9_\-\.\/]{1,64}$/', $values['sku_code']))            $errors[] = 'SKU code must be 1–64 letters/digits/dash/underscore/dot/slash.';
    if ($values['name'] === '' || mb_strlen($values['name']) > 255)               $errors[] = 'Name is required (max 255).';
    if (!in_array($values['status'], ['ACTIVE','INACTIVE'], true))                $errors[] = 'Invalid status.';
    if (!is_numeric($values['pack_size']) || (float)$values['pack_size'] <= 0)    $errors[] = 'Pack size must be > 0.';
    if (!is_numeric($values['selling_price']) || (float)$values['selling_price'] < 0) $errors[] = 'Selling price must be ≥ 0.';
    foreach (['min_qty','max_qty','reorder_point'] as $k) {
        if (!is_numeric($values[$k]) || (float)$values[$k] < 0) $errors[] = "$k must be ≥ 0.";
    }

    // Promote first barcode to primary if none flagged.
    $hasPrimary = false;
    foreach ($barcodes as $b) if ($b['is_primary']) $hasPrimary = true;
    if ($barcodes && !$hasPrimary) {
        $barcodes[0]['is_primary'] = 1;
    }

    // Detect duplicate barcodes within the form itself.
    $seen = [];
    foreach ($barcodes as $b) {
        $key = strtoupper($b['barcode']);
        if (isset($seen[$key])) {
            $errors[] = "Barcode {$b['barcode']} appears twice in this form.";
            break;
        }
        $seen[$key] = true;
    }
    // Multiple primaries shouldn't happen post-promotion, but guard.
    $primaries = 0;
    foreach ($barcodes as $b) $primaries += $b['is_primary'] ? 1 : 0;
    if ($primaries > 1) $errors[] = 'Only one barcode may be marked primary.';

    if ($errors) {
        return [$errors, $values, $barcodes];
    }

    // Persist
    try {
        db_tx(function () use (&$values, $barcodes, $existing) {
            if ($existing) {
                $stmt = db()->prepare(
                    'UPDATE products
                        SET sku_code = ?, name = ?, category_id = ?, uom = ?,
                            pack_size = ?, weight_kg = ?, selling_price = ?,
                            min_qty = ?, max_qty = ?, reorder_point = ?,
                            default_tax_group_id = ?, status = ?, notes = ?
                      WHERE id = ? AND company_id = ?'
                );
                $stmt->execute([
                    $values['sku_code'], $values['name'], $values['category_id'], $values['uom'],
                    $values['pack_size'], $values['weight_kg'] === '' ? null : $values['weight_kg'],
                    $values['selling_price'], $values['min_qty'], $values['max_qty'], $values['reorder_point'],
                    $values['default_tax_group_id'], $values['status'], $values['notes'] ?: null,
                    $existing['id'], company_id(),
                ]);
                $product_id = (int)$existing['id'];
                db()->prepare('DELETE FROM product_barcodes WHERE product_id = ?')->execute([$product_id]);
                $action = 'product_update';
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO products
                       (company_id, sku_code, name, category_id, uom, pack_size,
                        weight_kg, selling_price, min_qty, max_qty, reorder_point,
                        default_tax_group_id, status, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    company_id(), $values['sku_code'], $values['name'], $values['category_id'], $values['uom'],
                    $values['pack_size'], $values['weight_kg'] === '' ? null : $values['weight_kg'],
                    $values['selling_price'], $values['min_qty'], $values['max_qty'], $values['reorder_point'],
                    $values['default_tax_group_id'], $values['status'], $values['notes'] ?: null,
                ]);
                $product_id = (int)db()->lastInsertId();
                $action = 'product_create';
            }
            $values['id'] = $product_id;

            if ($barcodes) {
                $bins = db()->prepare(
                    'INSERT INTO product_barcodes (product_id, barcode, type, is_primary)
                     VALUES (?, ?, ?, ?)'
                );
                foreach ($barcodes as $b) {
                    $bins->execute([$product_id, $b['barcode'], $b['type'], $b['is_primary']]);
                }
            }
            audit_log($action, 'products', $product_id, [
                'sku_code' => $values['sku_code'], 'name' => $values['name'],
                'barcode_count' => count($barcodes),
            ]);
        });
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            $msg = (string)$e->getMessage();
            if (stripos($msg, 'uniq_products_company_sku') !== false) {
                $errors[] = 'SKU code already exists.';
            } elseif (stripos($msg, 'uniq_barcode') !== false) {
                $errors[] = 'One of the barcodes is already in use on another SKU.';
            } else {
                $errors[] = 'Duplicate value.';
            }
        } else {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }

    return [$errors, $values, $barcodes];
}
