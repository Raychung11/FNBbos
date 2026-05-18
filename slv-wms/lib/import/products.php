<?php
// SLV WMS — lib/import/products.php
// Importer for: products + primary barcode.
// CSV columns:
//   sku_code, name, category_name, uom, pack_size, weight_kg,
//   selling_price, min_qty, max_qty, reorder_point, default_tax_group_code,
//   primary_barcode, barcode_type, status
// All non-required columns may be left blank.

declare(strict_types=1);

function csv_import_products_required_headers(): array
{
    return ['sku_code', 'name'];
}

/**
 * @return array{ok:bool, error?:string}
 */
function csv_import_products_process_row(array $row, int $row_no, array $opts): array
{
    $sku  = strtoupper(trim((string)($row['sku_code'] ?? '')));
    $name = trim((string)($row['name'] ?? ''));
    if ($sku === '' || !preg_match('/^[A-Z0-9_\-\.\/]{1,64}$/', $sku)) {
        return ['ok' => false, 'error' => 'sku_code is missing or invalid'];
    }
    if ($name === '' || mb_strlen($name) > 255) {
        return ['ok' => false, 'error' => 'name is required (max 255)'];
    }

    $category_id = null;
    $cat = trim((string)($row['category_name'] ?? ''));
    if ($cat !== '') {
        $stmt = db()->prepare(
            'SELECT id FROM categories WHERE company_id = ? AND name = ? AND parent_id IS NULL LIMIT 1'
        );
        $stmt->execute([company_id(), $cat]);
        $category_id = $stmt->fetchColumn();
        if ($category_id === false) {
            // Auto-create top-level category
            db()->prepare('INSERT INTO categories (company_id, name) VALUES (?, ?)')
                ->execute([company_id(), $cat]);
            $category_id = (int)db()->lastInsertId();
        } else {
            $category_id = (int)$category_id;
        }
    }

    $tax_group_id = null;
    $tgc = strtoupper(trim((string)($row['default_tax_group_code'] ?? '')));
    if ($tgc !== '') {
        $stmt = db()->prepare('SELECT id FROM tax_groups WHERE company_id = ? AND code = ? LIMIT 1');
        $stmt->execute([company_id(), $tgc]);
        $tax_group_id = $stmt->fetchColumn();
        if ($tax_group_id === false) {
            return ['ok' => false, 'error' => "tax group '$tgc' not found"];
        }
        $tax_group_id = (int)$tax_group_id;
    }

    $uom            = trim((string)($row['uom'] ?? 'pcs')) ?: 'pcs';
    $pack_size      = ($row['pack_size'] ?? '') === '' ? 1 : (float)$row['pack_size'];
    $weight_kg      = ($row['weight_kg'] ?? '') === '' ? null : (float)$row['weight_kg'];
    $selling_price  = ($row['selling_price'] ?? '') === '' ? 0 : (float)$row['selling_price'];
    $min_qty        = ($row['min_qty'] ?? '') === '' ? 0 : (float)$row['min_qty'];
    $max_qty        = ($row['max_qty'] ?? '') === '' ? 0 : (float)$row['max_qty'];
    $reorder_point  = ($row['reorder_point'] ?? '') === '' ? 0 : (float)$row['reorder_point'];
    $status         = strtoupper(trim((string)($row['status'] ?? 'ACTIVE'))) ?: 'ACTIVE';

    if (!in_array($status, ['ACTIVE','INACTIVE'], true)) $status = 'ACTIVE';
    if ($pack_size <= 0)                                  return ['ok' => false, 'error' => 'pack_size must be > 0'];
    if ($selling_price < 0 || $min_qty < 0 || $max_qty < 0 || $reorder_point < 0) {
        return ['ok' => false, 'error' => 'numeric values must be ≥ 0'];
    }

    $primaryBc   = trim((string)($row['primary_barcode'] ?? ''));
    $barcodeType = strtoupper(trim((string)($row['barcode_type'] ?? 'INTERNAL')));
    if (!in_array($barcodeType, ['EAN','UPC','QR','INTERNAL','OTHER'], true)) {
        $barcodeType = 'INTERNAL';
    }
    if (mb_strlen($primaryBc) > 128) {
        return ['ok' => false, 'error' => 'primary_barcode too long (max 128)'];
    }

    try {
        return db_tx(function () use (
            $sku, $name, $category_id, $tax_group_id, $uom, $pack_size, $weight_kg,
            $selling_price, $min_qty, $max_qty, $reorder_point, $status,
            $primaryBc, $barcodeType
        ) {
            // Upsert product by (company_id, sku_code).
            $find = db()->prepare(
                'SELECT id FROM products WHERE company_id = ? AND sku_code = ? LIMIT 1'
            );
            $find->execute([company_id(), $sku]);
            $pid = $find->fetchColumn();

            if ($pid === false) {
                db()->prepare(
                    'INSERT INTO products
                       (company_id, sku_code, name, category_id, uom, pack_size,
                        weight_kg, selling_price, min_qty, max_qty, reorder_point,
                        default_tax_group_id, status)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    company_id(), $sku, $name, $category_id, $uom, $pack_size,
                    $weight_kg, $selling_price, $min_qty, $max_qty, $reorder_point,
                    $tax_group_id, $status,
                ]);
                $pid = (int)db()->lastInsertId();
            } else {
                $pid = (int)$pid;
                db()->prepare(
                    'UPDATE products
                        SET name = ?, category_id = ?, uom = ?, pack_size = ?,
                            weight_kg = ?, selling_price = ?, min_qty = ?, max_qty = ?,
                            reorder_point = ?, default_tax_group_id = ?, status = ?
                      WHERE id = ?'
                )->execute([
                    $name, $category_id, $uom, $pack_size,
                    $weight_kg, $selling_price, $min_qty, $max_qty,
                    $reorder_point, $tax_group_id, $status, $pid,
                ]);
            }

            if ($primaryBc !== '') {
                // Make sure no other primary exists for this product.
                db()->prepare(
                    'UPDATE product_barcodes SET is_primary = 0 WHERE product_id = ?'
                )->execute([$pid]);

                // Upsert this barcode.
                $bx = db()->prepare('SELECT id, product_id FROM product_barcodes WHERE barcode = ? LIMIT 1');
                $bx->execute([$primaryBc]);
                $bcRow = $bx->fetch();
                if ($bcRow === false) {
                    db()->prepare(
                        'INSERT INTO product_barcodes (product_id, barcode, type, is_primary) VALUES (?, ?, ?, 1)'
                    )->execute([$pid, $primaryBc, $barcodeType]);
                } else {
                    if ((int)$bcRow['product_id'] !== $pid) {
                        throw new RuntimeException("barcode '$primaryBc' is already used on a different SKU");
                    }
                    db()->prepare(
                        'UPDATE product_barcodes SET type = ?, is_primary = 1 WHERE id = ?'
                    )->execute([$barcodeType, (int)$bcRow['id']]);
                }
            }

            return ['ok' => true];
        });
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
