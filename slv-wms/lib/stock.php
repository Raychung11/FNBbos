<?php
// SLV WMS — lib/stock.php
// Purpose: FIFO engine. The ONLY place in the codebase that may write to
//          stock_movements / stock_layers / stock_layer_movements /
//          inventory. Higher-level flows (GRN, picking, transfers,
//          adjustments, opening-stock import) call record_putaway() or
//          record_issue() — they never touch the tables directly.
// Last updated: 2026-04-30
//
// Atomicity: every public function runs inside db_tx(). When a function
// is called from another transaction db_tx() falls back to a savepoint
// so the FIFO consume can be partially rolled back without affecting
// the outer caller.
//
// Idempotency: if scan_uuid is supplied and a row with that uuid already
// exists in stock_movements, we return that movement's id without
// performing the work. The UNIQUE index on stock_movements.scan_uuid
// guarantees double-tap-safe scan endpoints (spec §4 invariant 6).

declare(strict_types=1);

/** Map of source_type → movement_type used by record_putaway(). */
const STOCK_PUTAWAY_MOVEMENT = [
    'GRN'        => 'PUTAWAY',
    'OPENING'    => 'OPENING',
    'ADJUST'     => 'ADJUST_PLUS',
    'IW_RECEIVE' => 'IW_RECEIVE',
    'RETURN'     => 'RETURN_IN',
];

/** Movement types allowed by record_issue(). */
const STOCK_ISSUE_MOVEMENT_TYPES = [
    'PICK', 'TRANSFER_OUT', 'IW_DISPATCH', 'ADJUST_MINUS', 'COUNT_MINUS',
];

/**
 * Record an inbound move: creates a new FIFO layer, an append-only ledger
 * row, and bumps the (product, bin) inventory cell.
 *
 * @param int         $company_id
 * @param int         $warehouse_id
 * @param int         $product_id
 * @param int         $bin_id
 * @param float|string $qty         positive
 * @param float|string $unit_cost   per-unit cost in DECIMAL(14,4)
 * @param string      $source_type  one of GRN|OPENING|ADJUST|IW_RECEIVE|RETURN
 * @param int|null    $source_ref_id  document id (e.g. grn_id, transfer_id, import_jobs.id)
 * @param int|null    $user_id
 * @param string|null $scan_uuid    client-generated; null for non-scan flows
 * @param string|null $received_at  override the layer's FIFO timestamp; default NOW()
 * @param string|null $notes
 * @return int        stock_movements.id
 */
function record_putaway(
    int $company_id,
    int $warehouse_id,
    int $product_id,
    int $bin_id,
    $qty,
    $unit_cost,
    string $source_type,
    ?int $source_ref_id = null,
    ?int $user_id = null,
    ?string $scan_uuid = null,
    ?string $received_at = null,
    ?string $notes = null
): int {
    $qty       = (float)$qty;
    $unit_cost = (float)$unit_cost;
    if ($qty <= 0)        throw new InvalidArgumentException('record_putaway: qty must be > 0.');
    if ($unit_cost < 0)   throw new InvalidArgumentException('record_putaway: unit_cost must be >= 0.');
    if (!isset(STOCK_PUTAWAY_MOVEMENT[$source_type])) {
        throw new InvalidArgumentException("record_putaway: unknown source_type '$source_type'.");
    }
    $movement_type = STOCK_PUTAWAY_MOVEMENT[$source_type];
    $received_at   = $received_at ?: date('Y-m-d H:i:s');

    return db_tx(function () use (
        $company_id, $warehouse_id, $product_id, $bin_id, $qty, $unit_cost,
        $source_type, $source_ref_id, $user_id, $scan_uuid, $received_at, $notes,
        $movement_type
    ) {
        // Idempotency: if this scan_uuid was already recorded, return its id.
        if ($scan_uuid !== null) {
            $find = db()->prepare('SELECT id FROM stock_movements WHERE scan_uuid = ? LIMIT 1');
            $find->execute([$scan_uuid]);
            $hit = $find->fetchColumn();
            if ($hit !== false) {
                return (int)$hit;
            }
        }

        stock_assert_bin_in_warehouse($bin_id, $warehouse_id);

        // 1. Insert FIFO layer.
        $insLayer = db()->prepare(
            'INSERT INTO stock_layers
               (company_id, warehouse_id, product_id, bin_id,
                qty_received, qty_remaining, unit_cost, received_at,
                source_type, source_ref_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "ACTIVE")'
        );
        $insLayer->execute([
            $company_id, $warehouse_id, $product_id, $bin_id,
            $qty, $qty, $unit_cost, $received_at,
            $source_type, $source_ref_id,
        ]);
        $layer_id = (int)db()->lastInsertId();

        // 2. Append the ledger row.
        $insMove = db()->prepare(
            'INSERT INTO stock_movements
               (company_id, warehouse_id, scan_uuid, product_id, bin_id,
                qty_delta, movement_type, ref_type, ref_id, user_id, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insMove->execute([
            $company_id, $warehouse_id, $scan_uuid, $product_id, $bin_id,
            $qty, $movement_type, $source_type, $source_ref_id, $user_id, $notes,
        ]);
        $movement_id = (int)db()->lastInsertId();

        // 3. Recompute the inventory cache for this (product, bin).
        stock_inventory_upsert($company_id, $warehouse_id, $product_id, $bin_id, $qty);

        audit_log('stock_putaway', 'stock_movements', $movement_id, [
            'product_id' => $product_id, 'bin_id' => $bin_id,
            'qty' => $qty, 'unit_cost' => $unit_cost,
            'layer_id' => $layer_id, 'source_type' => $source_type,
            'source_ref_id' => $source_ref_id,
        ]);

        return $movement_id;
    });
}

/**
 * Record an outbound move: walks the FIFO layers for (product, bin),
 * consumes them oldest-first, and writes the ledger row(s).
 *
 * @param int         $company_id
 * @param int         $warehouse_id
 * @param int         $product_id
 * @param int         $bin_id
 * @param float|string $qty           positive amount to issue
 * @param string      $movement_type  one of STOCK_ISSUE_MOVEMENT_TYPES
 * @param string|null $ref_type       e.g. 'pick_list', 'transfer'
 * @param int|null    $ref_id
 * @param int|null    $ref_line_id
 * @param int|null    $user_id
 * @param string|null $scan_uuid
 * @param string|null $notes
 * @return array{movement_id:int, consumed:array<int,array{layer_id:int,qty:float,unit_cost:float}>}
 *         The consumed array is what callers (e.g. inter-warehouse dispatch)
 *         use to mirror the original FIFO cost basis at the destination.
 */
function record_issue(
    int $company_id,
    int $warehouse_id,
    int $product_id,
    int $bin_id,
    $qty,
    string $movement_type,
    ?string $ref_type = null,
    ?int $ref_id = null,
    ?int $ref_line_id = null,
    ?int $user_id = null,
    ?string $scan_uuid = null,
    ?string $notes = null
): array {
    $qty = (float)$qty;
    if ($qty <= 0) throw new InvalidArgumentException('record_issue: qty must be > 0.');
    if (!in_array($movement_type, STOCK_ISSUE_MOVEMENT_TYPES, true)) {
        throw new InvalidArgumentException("record_issue: unsupported movement_type '$movement_type'.");
    }

    return db_tx(function () use (
        $company_id, $warehouse_id, $product_id, $bin_id, $qty,
        $movement_type, $ref_type, $ref_id, $ref_line_id,
        $user_id, $scan_uuid, $notes
    ) {
        // Idempotency.
        if ($scan_uuid !== null) {
            $find = db()->prepare(
                'SELECT id FROM stock_movements WHERE scan_uuid = ? LIMIT 1'
            );
            $find->execute([$scan_uuid]);
            $hit = $find->fetchColumn();
            if ($hit !== false) {
                $mid = (int)$hit;
                $existing = db()->prepare(
                    'SELECT layer_id, qty_consumed, unit_cost
                       FROM stock_layer_movements WHERE stock_movement_id = ?'
                );
                $existing->execute([$mid]);
                $consumed = array_map(static function ($r) {
                    return [
                        'layer_id'  => (int)$r['layer_id'],
                        'qty'       => (float)$r['qty_consumed'],
                        'unit_cost' => (float)$r['unit_cost'],
                    ];
                }, $existing->fetchAll());
                return ['movement_id' => $mid, 'consumed' => $consumed];
            }
        }

        stock_assert_bin_in_warehouse($bin_id, $warehouse_id);

        // Check we have enough before we touch anything.
        $available = stock_available_qty_for_issue($product_id, $bin_id);
        if ($available + 1e-9 < $qty) {
            throw new RuntimeException(sprintf(
                'Insufficient stock: need %s, have %s in this bin.',
                rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format($available, 4, '.', ''), '0'), '.')
            ));
        }

        // Lock the active layers in FIFO order.
        $layers = db()->prepare(
            'SELECT id, qty_remaining, unit_cost
               FROM stock_layers
              WHERE product_id = ? AND bin_id = ? AND status = "ACTIVE"
                AND qty_remaining > 0
              ORDER BY received_at ASC, id ASC
              FOR UPDATE'
        );
        $layers->execute([$product_id, $bin_id]);

        $remaining = $qty;
        $consumed  = [];
        $depleteIds = [];
        $updateLayer = db()->prepare(
            'UPDATE stock_layers
                SET qty_remaining = ?,
                    status = IF(? = 0, "DEPLETED", status)
              WHERE id = ?'
        );

        foreach ($layers->fetchAll() as $layer) {
            if ($remaining <= 0) break;
            $take      = min($remaining, (float)$layer['qty_remaining']);
            $newQ      = (float)$layer['qty_remaining'] - $take;
            $newQRound = round($newQ, 4);
            $updateLayer->execute([$newQRound, $newQRound, (int)$layer['id']]);

            $consumed[] = [
                'layer_id'  => (int)$layer['id'],
                'qty'       => round($take, 4),
                'unit_cost' => (float)$layer['unit_cost'],
            ];
            $remaining -= $take;
        }

        if ($remaining > 1e-9) {
            // Defensive — covered by the available-qty check above, but a
            // belt-and-braces guard means a race condition still rolls back
            // cleanly rather than producing negative qty_remaining.
            throw new RuntimeException('Insufficient stock encountered while consuming FIFO layers.');
        }

        // Append the (single) ledger row for this issue.
        $insMove = db()->prepare(
            'INSERT INTO stock_movements
               (company_id, warehouse_id, scan_uuid, product_id, bin_id,
                qty_delta, movement_type, ref_type, ref_id, ref_line_id,
                user_id, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insMove->execute([
            $company_id, $warehouse_id, $scan_uuid, $product_id, $bin_id,
            -1 * $qty, $movement_type, $ref_type, $ref_id, $ref_line_id,
            $user_id, $notes,
        ]);
        $movement_id = (int)db()->lastInsertId();

        // Detail rows: one per layer touched.
        $insSlm = db()->prepare(
            'INSERT INTO stock_layer_movements
               (stock_movement_id, layer_id, qty_consumed, unit_cost)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($consumed as $c) {
            $insSlm->execute([$movement_id, $c['layer_id'], $c['qty'], $c['unit_cost']]);
        }

        // Decrement inventory.
        stock_inventory_upsert($company_id, $warehouse_id, $product_id, $bin_id, -1 * $qty);

        audit_log('stock_issue', 'stock_movements', $movement_id, [
            'product_id'    => $product_id,
            'bin_id'        => $bin_id,
            'qty'           => $qty,
            'movement_type' => $movement_type,
            'ref_type'      => $ref_type,
            'ref_id'        => $ref_id,
            'consumed_layers' => array_map(fn($c) => $c['layer_id'], $consumed),
        ]);

        return ['movement_id' => $movement_id, 'consumed' => $consumed];
    });
}

// -----------------------------------------------------------------------------
// Internal helpers — only callable from this file.
// -----------------------------------------------------------------------------

/**
 * Apply a delta to the (product, bin) inventory cell. Creates the row if
 * it doesn't exist yet.
 */
function stock_inventory_upsert(int $company_id, int $warehouse_id, int $product_id, int $bin_id, float $delta): void
{
    $stmt = db()->prepare(
        'INSERT INTO inventory (company_id, warehouse_id, product_id, bin_id, qty)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)'
    );
    $stmt->execute([$company_id, $warehouse_id, $product_id, $bin_id, $delta]);
}

/**
 * Check the supplied bin actually lives in the supplied warehouse. Throws
 * RuntimeException if not — prevents accidental cross-warehouse writes.
 */
function stock_assert_bin_in_warehouse(int $bin_id, int $warehouse_id): void
{
    $stmt = db()->prepare(
        'SELECT 1 FROM bins b
           JOIN racks r ON r.id = b.rack_id
           JOIN zones z ON z.id = r.zone_id
          WHERE b.id = ? AND z.warehouse_id = ?
          LIMIT 1'
    );
    $stmt->execute([$bin_id, $warehouse_id]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException("Bin $bin_id does not belong to warehouse $warehouse_id.");
    }
}

/**
 * SUM(qty_remaining) of active layers for this (product, bin). Used to
 * pre-flight an issue so we never end up with negative qty_remaining.
 */
function stock_available_qty_for_issue(int $product_id, int $bin_id): float
{
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(qty_remaining), 0)
           FROM stock_layers
          WHERE product_id = ? AND bin_id = ? AND status = "ACTIVE"'
    );
    $stmt->execute([$product_id, $bin_id]);
    return (float)$stmt->fetchColumn();
}

// -----------------------------------------------------------------------------
// Read-side helpers (safe to call without a transaction)
// -----------------------------------------------------------------------------

/**
 * Per-(product, warehouse) FIFO valuation. Optionally narrowed to a single
 * warehouse or product. Returns numeric strings (DECIMAL) so the caller can
 * format them however they like.
 *
 * @return array<int,array{
 *   product_id:int, sku_code:string, name:string,
 *   warehouse_id:int, warehouse_code:string, warehouse_name:string,
 *   qty_total:string, value_total:string, avg_cost:string, layer_count:int
 * }>
 */
function stock_on_hand_summary(int $company_id, ?int $warehouse_id = null, ?int $product_id = null): array
{
    $where  = ["l.status='ACTIVE'", 'l.company_id = ?'];
    $params = [$company_id];
    if ($warehouse_id) { $where[] = 'l.warehouse_id = ?'; $params[] = $warehouse_id; }
    if ($product_id)   { $where[] = 'l.product_id = ?';   $params[] = $product_id; }

    $sql = "SELECT
              p.id   AS product_id,
              p.sku_code,
              p.name,
              l.warehouse_id,
              w.code AS warehouse_code,
              w.name AS warehouse_name,
              SUM(l.qty_remaining)                  AS qty_total,
              SUM(l.qty_remaining * l.unit_cost)    AS value_total,
              CASE WHEN SUM(l.qty_remaining) > 0
                   THEN SUM(l.qty_remaining * l.unit_cost) / SUM(l.qty_remaining)
                   ELSE 0 END                       AS avg_cost,
              COUNT(*)                              AS layer_count
            FROM stock_layers l
            JOIN products   p ON p.id = l.product_id
            JOIN warehouses w ON w.id = l.warehouse_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY p.id, l.warehouse_id, p.sku_code, p.name, w.code, w.name
            HAVING qty_total > 0
            ORDER BY p.sku_code, w.code";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Per-bin breakdown of active layers for a single (product, warehouse).
 * Used by the stock-on-hand drilldown.
 */
function stock_layer_drilldown(int $product_id, int $warehouse_id): array
{
    $stmt = db()->prepare(
        "SELECT l.id, l.qty_received, l.qty_remaining, l.unit_cost,
                l.received_at, l.source_type, l.source_ref_id,
                b.full_code AS bin_full_code, b.code AS bin_code
           FROM stock_layers l
           JOIN bins b ON b.id = l.bin_id
          WHERE l.product_id = ? AND l.warehouse_id = ? AND l.status = 'ACTIVE'
          ORDER BY l.received_at, l.id"
    );
    $stmt->execute([$product_id, $warehouse_id]);
    return $stmt->fetchAll();
}
