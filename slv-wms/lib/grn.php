<?php
// SLV WMS — lib/grn.php
// Purpose: GRN (Goods Receipt) helpers — putaway-suggestion engine,
//          status-recompute, putaway execution. The actual stock writes
//          go through lib/stock.php (record_putaway).
// Last updated: 2026-04-30

declare(strict_types=1);

/**
 * Rank putaway candidates for one (product, warehouse, qty). Returns up
 * to $limit suggestions, ordered by preference. Each suggestion includes
 * a `reason` so the UI can explain WHY it picked that bin.
 *
 * Selection order (per spec §5.3):
 *   1. Existing bin already holding this SKU — same default zone → highest preference
 *      (stock consolidation; FIFO will pick the oldest layer first anyway).
 *   2. Existing bin already holding this SKU — anywhere in this warehouse.
 *   3. First empty pickable bin in the SKU's default zone.
 *   4. First empty pickable bin in any active zone.
 *
 * Bins are de-duplicated (a bin won't appear twice in different reasons).
 *
 * @return array<int,array{
 *   bin_id:int, full_code:string, code:string, zone_code:string,
 *   capacity_units:?float, current_qty:float, reason:string
 * }>
 */
function grn_putaway_suggestions(int $product_id, int $warehouse_id, float $qty, int $limit = 5): array
{
    $cid = company_id();

    // Default zone for this SKU in this warehouse, if any.
    $stmt = db()->prepare(
        'SELECT default_zone_id FROM product_warehouse_settings
          WHERE product_id = ? AND warehouse_id = ? LIMIT 1'
    );
    $stmt->execute([$product_id, $warehouse_id]);
    $defaultZoneId = (int)($stmt->fetchColumn() ?: 0);

    $seen   = [];
    $picked = [];

    $add = function (array $row, string $reason) use (&$seen, &$picked, $limit): void {
        $id = (int)$row['bin_id'];
        if (isset($seen[$id])) return;
        $seen[$id] = true;
        $row['reason']   = $reason;
        $row['bin_id']   = $id;
        $row['current_qty']   = isset($row['current_qty'])   ? (float)$row['current_qty']   : 0.0;
        $row['capacity_units']= $row['capacity_units'] === null ? null : (float)$row['capacity_units'];
        $picked[] = $row;
    };

    // 1 + 2: existing bin holding this SKU.
    $sql = "SELECT b.id AS bin_id, b.full_code, b.code,
                   b.capacity_units,
                   COALESCE(i.qty, 0) AS current_qty,
                   z.code AS zone_code, z.id AS zone_id
              FROM inventory i
              JOIN bins  b ON b.id = i.bin_id
              JOIN racks r ON r.id = b.rack_id
              JOIN zones z ON z.id = r.zone_id
             WHERE z.warehouse_id = ?
               AND i.product_id = ?
               AND i.qty > 0
               AND b.status = 'ACTIVE'
          ORDER BY CASE WHEN z.id = ? THEN 0 ELSE 1 END, i.updated_at DESC
             LIMIT 50";
    $stmt = db()->prepare($sql);
    $stmt->execute([$warehouse_id, $product_id, $defaultZoneId]);
    foreach ($stmt->fetchAll() as $row) {
        if ($defaultZoneId && (int)$row['zone_id'] === $defaultZoneId) {
            $add($row, 'consolidate (default zone)');
        } else {
            $add($row, 'consolidate (existing bin)');
        }
        if (count($picked) >= $limit) return $picked;
    }

    // 3 + 4: empty pickable bins.
    $sql = "SELECT b.id AS bin_id, b.full_code, b.code,
                   b.capacity_units,
                   0 AS current_qty,
                   z.code AS zone_code, z.id AS zone_id
              FROM bins b
              JOIN racks r ON r.id = b.rack_id
              JOIN zones z ON z.id = r.zone_id
         LEFT JOIN inventory i ON i.bin_id = b.id AND i.qty > 0
             WHERE z.warehouse_id = ?
               AND b.status   = 'ACTIVE'
               AND b.pickable = 1
               AND z.status   = 'ACTIVE'
               AND i.id IS NULL
          ORDER BY CASE WHEN z.id = ? THEN 0 ELSE 1 END, z.code, b.full_code
             LIMIT 50";
    $stmt = db()->prepare($sql);
    $stmt->execute([$warehouse_id, $defaultZoneId]);
    foreach ($stmt->fetchAll() as $row) {
        $reason = ($defaultZoneId && (int)$row['zone_id'] === $defaultZoneId)
            ? 'empty bin (default zone)'
            : 'empty bin';
        $add($row, $reason);
        if (count($picked) >= $limit) return $picked;
    }

    return $picked;
}

/**
 * Pick the single best suggestion (or null if none possible).
 */
function grn_suggest_bin(int $product_id, int $warehouse_id, float $qty): ?array
{
    $list = grn_putaway_suggestions($product_id, $warehouse_id, $qty, 1);
    return $list[0] ?? null;
}

/**
 * Recompute the GRN's status from its items' qty_received / qty_putaway,
 * UPDATEs the row, and returns the new status.
 *
 *   no items received yet                         → DRAFT (untouched)
 *   any item received, not all received           → RECEIVING
 *   all items fully received, none putaway        → RECEIVED
 *   any putaway, not all qty putaway              → PUTAWAY
 *   all received qty has been putaway             → CLOSED
 */
function grn_recompute_status(int $grn_id): string
{
    $stmt = db()->prepare(
        'SELECT g.status,
                SUM(gi.qty_expected)  AS exp_total,
                SUM(gi.qty_received)  AS rcv_total,
                SUM(gi.qty_putaway)   AS put_total,
                SUM(CASE WHEN gi.qty_received >= gi.qty_expected AND gi.qty_expected > 0
                          THEN 1 ELSE 0 END) AS lines_full,
                COUNT(*) AS line_count
           FROM grn g
      LEFT JOIN grn_items gi ON gi.grn_id = g.id
          WHERE g.id = ?
       GROUP BY g.id'
    );
    $stmt->execute([$grn_id]);
    $row = $stmt->fetch();
    if (!$row) return '';

    $cur = (string)$row['status'];
    if ($cur === 'CANCELLED' || $cur === 'CLOSED') return $cur;

    $rcv = (float)$row['rcv_total'];
    $put = (float)$row['put_total'];
    $exp = (float)$row['exp_total'];
    $linesFull  = (int)$row['lines_full'];
    $lineCount  = (int)$row['line_count'];
    $allReceived = $lineCount > 0 && $linesFull === $lineCount;

    $next = $cur;
    if ($put > 0 && abs($put - $rcv) < 1e-6 && $allReceived) {
        $next = 'CLOSED';
    } elseif ($put > 0) {
        $next = 'PUTAWAY';
    } elseif ($allReceived) {
        $next = 'RECEIVED';
    } elseif ($rcv > 0) {
        $next = 'RECEIVING';
    } elseif ($cur !== 'PUTAWAY' && $cur !== 'CLOSED') {
        $next = 'DRAFT';
    }

    if ($next !== $cur) {
        db()->prepare('UPDATE grn SET status = ? WHERE id = ?')->execute([$next, $grn_id]);
    }
    // Always keep the cached total_value in sync.
    db()->prepare(
        'UPDATE grn g
            SET total_value = (SELECT COALESCE(SUM(qty_received * unit_cost), 0)
                                 FROM grn_items WHERE grn_id = g.id)
          WHERE g.id = ?'
    )->execute([$grn_id]);

    return $next;
}

/**
 * Putaway a quantity from a GRN line into a bin. Wraps record_putaway
 * with source_type='GRN' so the resulting layer's source_ref_id points
 * back at the GRN id.
 */
function grn_putaway_execute(
    int $grn_item_id,
    int $bin_id,
    float $qty,
    ?float $unit_cost_override,
    ?string $scan_uuid,
    ?int $user_id
): int {
    if ($qty <= 0) {
        throw new InvalidArgumentException('qty must be > 0.');
    }

    return db_tx(function () use ($grn_item_id, $bin_id, $qty, $unit_cost_override, $scan_uuid, $user_id) {
        // Load the line + parent GRN.
        $stmt = db()->prepare(
            'SELECT gi.id            AS gi_id,
                    gi.product_id,
                    gi.qty_received,
                    gi.qty_putaway,
                    gi.unit_cost     AS line_cost,
                    g.id             AS grn_id,
                    g.company_id,
                    g.warehouse_id,
                    g.status
               FROM grn_items gi
               JOIN grn g ON g.id = gi.grn_id
              WHERE gi.id = ?
              FOR UPDATE'
        );
        $stmt->execute([$grn_item_id]);
        $row = $stmt->fetch();
        if (!$row)                                                          throw new RuntimeException('GRN line not found.');
        if (in_array($row['status'], ['DRAFT','CANCELLED','CLOSED'], true)) throw new RuntimeException("Cannot putaway a {$row['status']} GRN.");

        $remaining = (float)$row['qty_received'] - (float)$row['qty_putaway'];
        if ($qty - $remaining > 1e-6) {
            throw new RuntimeException(sprintf(
                'Putaway qty %s exceeds remaining %s on this line.',
                rtrim(rtrim(number_format($qty, 4, '.', ''),       '0'), '.'),
                rtrim(rtrim(number_format($remaining, 4, '.', ''), '0'), '.')
            ));
        }

        $unitCost = $unit_cost_override !== null ? (float)$unit_cost_override : (float)$row['line_cost'];

        $movement_id = record_putaway(
            (int)$row['company_id'],
            (int)$row['warehouse_id'],
            (int)$row['product_id'],
            $bin_id,
            $qty,
            $unitCost,
            'GRN',
            (int)$row['grn_id'],
            $user_id,
            $scan_uuid,
            null,
            'GRN line #' . (int)$row['gi_id']
        );

        // Find the layer this movement just created.
        $layerStmt = db()->prepare(
            "SELECT id FROM stock_layers
              WHERE source_type = 'GRN' AND source_ref_id = ?
                AND product_id = ? AND bin_id = ?
              ORDER BY id DESC LIMIT 1"
        );
        $layerStmt->execute([(int)$row['grn_id'], (int)$row['product_id'], $bin_id]);
        $layer_id = (int)($layerStmt->fetchColumn() ?: 0) ?: null;

        // Audit row in grn_putaway.
        $insGp = db()->prepare(
            'INSERT INTO grn_putaway
               (grn_item_id, bin_id, qty, unit_cost, layer_id, movement_id, scan_uuid, user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insGp->execute([
            $grn_item_id, $bin_id, $qty, $unitCost,
            $layer_id, $movement_id, $scan_uuid, $user_id,
        ]);

        // Bump qty_putaway on the line.
        db()->prepare(
            'UPDATE grn_items SET qty_putaway = qty_putaway + ? WHERE id = ?'
        )->execute([$qty, $grn_item_id]);

        grn_recompute_status((int)$row['grn_id']);

        return (int)db()->lastInsertId();
    });
}
