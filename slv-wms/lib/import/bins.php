<?php
// SLV WMS — lib/import/bins.php
// Importer for: zones + racks + bins (creates parents on demand).
// CSV columns:
//   warehouse_code, zone_code, zone_name, zone_type, rack_code,
//   bin_code, capacity_units, pickable, status, barcode
// At minimum: warehouse_code, zone_code, rack_code, bin_code.

declare(strict_types=1);

function csv_import_bins_required_headers(): array
{
    return ['warehouse_code', 'zone_code', 'rack_code', 'bin_code'];
}

/**
 * @return array{ok:bool, error?:string}
 */
function csv_import_bins_process_row(array $row, int $row_no, array $opts): array
{
    $whCode    = strtoupper(trim((string)($row['warehouse_code'] ?? '')));
    $zoneCode  = strtoupper(trim((string)($row['zone_code']      ?? '')));
    $rackCode  = strtoupper(trim((string)($row['rack_code']      ?? '')));
    $binCode   = strtoupper(trim((string)($row['bin_code']       ?? '')));
    if ($whCode === '' || $zoneCode === '' || $rackCode === '' || $binCode === '') {
        return ['ok' => false, 'error' => 'warehouse_code, zone_code, rack_code, bin_code are all required'];
    }
    foreach ([
        'warehouse_code' => $whCode, 'zone_code' => $zoneCode,
        'rack_code'      => $rackCode, 'bin_code' => $binCode,
    ] as $label => $val) {
        if (!preg_match('/^[A-Z0-9_\-]{1,32}$/', $val) && $label !== 'warehouse_code') {
            return ['ok' => false, 'error' => "$label must be 1–32 letters/digits/dash/underscore"];
        }
    }
    if (!preg_match('/^[A-Z0-9_\-]{1,64}$/', $whCode)) {
        return ['ok' => false, 'error' => 'warehouse_code must be 1–64 letters/digits/dash/underscore'];
    }

    $zoneName = trim((string)($row['zone_name'] ?? '')) ?: $zoneCode;
    $zoneType = strtoupper(trim((string)($row['zone_type'] ?? 'GENERAL'))) ?: 'GENERAL';
    if (!in_array($zoneType, ['GENERAL','COLD','DRY','BULK','RETURNS','PACKING','STAGING','OTHER'], true)) {
        $zoneType = 'GENERAL';
    }
    $cap_raw  = trim((string)($row['capacity_units'] ?? ''));
    $capacity = $cap_raw === '' ? null : (float)$cap_raw;
    if ($cap_raw !== '' && !is_numeric($cap_raw)) {
        return ['ok' => false, 'error' => 'capacity_units must be numeric'];
    }
    $pickable_raw = strtolower(trim((string)($row['pickable'] ?? '1')));
    $pickable     = in_array($pickable_raw, ['1','y','yes','true','t'], true) ? 1 : 0;
    $status       = strtoupper(trim((string)($row['status'] ?? 'ACTIVE'))) ?: 'ACTIVE';
    if (!in_array($status, ['ACTIVE','BLOCKED','INACTIVE'], true)) $status = 'ACTIVE';
    $barcode = trim((string)($row['barcode'] ?? ''));

    try {
        return db_tx(function () use (
            $whCode, $zoneCode, $zoneName, $zoneType,
            $rackCode, $binCode, $capacity, $pickable, $status, $barcode
        ) {
            $w = db()->prepare(
                'SELECT id FROM warehouses WHERE company_id = ? AND code = ? LIMIT 1'
            );
            $w->execute([company_id(), $whCode]);
            $wid = $w->fetchColumn();
            if ($wid === false) return ['ok' => false, 'error' => "warehouse '$whCode' not found"];
            $wid = (int)$wid;

            // Upsert zone
            $z = db()->prepare('SELECT id FROM zones WHERE warehouse_id = ? AND code = ? LIMIT 1');
            $z->execute([$wid, $zoneCode]);
            $zid = $z->fetchColumn();
            if ($zid === false) {
                db()->prepare(
                    'INSERT INTO zones (warehouse_id, code, name, type) VALUES (?, ?, ?, ?)'
                )->execute([$wid, $zoneCode, $zoneName, $zoneType]);
                $zid = (int)db()->lastInsertId();
            } else {
                $zid = (int)$zid;
            }

            // Upsert rack
            $r = db()->prepare('SELECT id FROM racks WHERE zone_id = ? AND code = ? LIMIT 1');
            $r->execute([$zid, $rackCode]);
            $rid = $r->fetchColumn();
            if ($rid === false) {
                db()->prepare('INSERT INTO racks (zone_id, code) VALUES (?, ?)')
                    ->execute([$zid, $rackCode]);
                $rid = (int)db()->lastInsertId();
            } else {
                $rid = (int)$rid;
            }

            $full_code   = "$whCode/$zoneCode/$rackCode/$binCode";
            $bcode_final = $barcode !== '' ? $barcode : $full_code;

            // Upsert bin (by rack_id+code)
            $b = db()->prepare('SELECT id FROM bins WHERE rack_id = ? AND code = ? LIMIT 1');
            $b->execute([$rid, $binCode]);
            $bid = $b->fetchColumn();
            if ($bid === false) {
                db()->prepare(
                    'INSERT INTO bins (rack_id, code, full_code, barcode, capacity_units, pickable, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$rid, $binCode, $full_code, $bcode_final, $capacity, $pickable, $status]);
            } else {
                db()->prepare(
                    'UPDATE bins
                        SET full_code = ?, barcode = ?, capacity_units = ?,
                            pickable = ?, status = ?
                      WHERE id = ?'
                )->execute([$full_code, $bcode_final, $capacity, $pickable, $status, (int)$bid]);
            }

            return ['ok' => true];
        });
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
