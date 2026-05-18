<?php
// SLV WMS — lib/so.php
// Purpose: Sales-order helpers: line save (with tax compute), stock-
//          availability check, pick-list generation with FIFO bin
//          allocation and walk-path ordering, status recompute.
// Last updated: 2026-04-30

declare(strict_types=1);

/**
 * Compute each line's tax via lib/tax.php and persist subtotal/tax/total
 * + the SO header totals. Caller is responsible for the surrounding
 * transaction (we don't open one — typical use is during create/edit).
 */
function so_recompute_totals(int $so_id): array
{
    $stmt = db()->prepare(
        'SELECT id, qty_ordered, unit_price, tax_group_id FROM so_items WHERE so_id = ? ORDER BY id'
    );
    $stmt->execute([$so_id]);
    $lines = $stmt->fetchAll();

    $upd = db()->prepare(
        'UPDATE so_items SET line_subtotal = ?, line_tax = ?, line_total = ? WHERE id = ?'
    );
    $lineResults = [];
    foreach ($lines as $l) {
        $sub = (float)$l['qty_ordered'] * (float)$l['unit_price'];
        $r   = tax_compute_line($sub, $l['tax_group_id'] === null ? null : (int)$l['tax_group_id']);
        $upd->execute([$r['subtotal'], $r['tax_total'], $r['line_total'], (int)$l['id']]);
        $lineResults[] = $r;
    }
    $agg = tax_aggregate($lineResults);

    db()->prepare(
        'UPDATE sales_orders
            SET subtotal = ?, tax_total = ?, grand_total = ?
          WHERE id = ?'
    )->execute([$agg['subtotal'], $agg['tax_total'], $agg['grand_total'], $so_id]);

    return $agg;
}

/**
 * For each SO line, return how much active stock is available in the SO's
 * warehouse. Used to flag shortages on the SO view page and at confirm.
 *
 * @return array<int,array{
 *   so_item_id:int, product_id:int, qty_ordered:float,
 *   qty_available:float, shortfall:float
 * }>
 */
function so_stock_availability(int $so_id): array
{
    $head = db()->prepare('SELECT warehouse_id FROM sales_orders WHERE id = ?');
    $head->execute([$so_id]);
    $wid = (int)($head->fetchColumn() ?: 0);
    if (!$wid) return [];

    $stmt = db()->prepare(
        "SELECT si.id AS so_item_id, si.product_id, si.qty_ordered,
                COALESCE((SELECT SUM(qty_remaining) FROM stock_layers
                           WHERE product_id = si.product_id
                             AND warehouse_id = ?
                             AND status = 'ACTIVE'), 0) AS qty_available
           FROM so_items si
          WHERE si.so_id = ?
       ORDER BY si.id"
    );
    $stmt->execute([$wid, $so_id]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $available = (float)$r['qty_available'];
        $ordered   = (float)$r['qty_ordered'];
        $out[] = [
            'so_item_id'    => (int)$r['so_item_id'],
            'product_id'    => (int)$r['product_id'],
            'qty_ordered'   => $ordered,
            'qty_available' => $available,
            'shortfall'     => max(0, round($ordered - $available, 4)),
        ];
    }
    return $out;
}

/**
 * Generate a pick list for the SO. One pick_item per (so_item, source bin),
 * allocating qty FIFO across the active layers. Walk-path ordered by
 * zone code → rack code → bin code (as MySQL VARCHAR sort, which matches
 * how we name them).
 *
 * Returns the new pick_list_id. Throws if a pick list already exists for
 * this SO (one-per-SO for now).
 */
function so_generate_pick_list(int $so_id, ?int $assignedUserId = null): int
{
    return db_tx(function () use ($so_id, $assignedUserId) {
        $stmt = db()->prepare(
            'SELECT id, company_id, warehouse_id, status, so_no
               FROM sales_orders WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$so_id]);
        $so = $stmt->fetch();
        if (!$so) throw new RuntimeException('Sales order not found.');
        if (!in_array($so['status'], ['CONFIRMED','PICKING'], true)) {
            throw new RuntimeException("Cannot generate pick list while SO is {$so['status']}.");
        }

        // One pick list per SO (Phase 6). Reject if already present.
        $exist = db()->prepare(
            "SELECT id FROM pick_lists WHERE so_id = ? AND status <> 'CANCELLED' LIMIT 1"
        );
        $exist->execute([$so_id]);
        if ($exist->fetchColumn() !== false) {
            throw new RuntimeException('A pick list already exists for this SO.');
        }

        $pickNo = next_doc_no('PICK', ['warehouse_code' => so_warehouse_code((int)$so['warehouse_id'])]);
        // 'PICK' may not be seeded; fall back to a generated one if needed.
        if ($pickNo === '' || strpos($pickNo, '{') !== false) {
            $pickNo = 'PICK/' . date('y') . '/' . str_pad((string)$so_id, 5, '0', STR_PAD_LEFT);
        }

        db()->prepare(
            'INSERT INTO pick_lists
               (company_id, warehouse_id, so_id, pick_no, assigned_user_id, status, created_by)
             VALUES (?, ?, ?, ?, ?, "DRAFT", ?)'
        )->execute([
            (int)$so['company_id'], (int)$so['warehouse_id'],
            $so_id, $pickNo, $assignedUserId,
            (int)(current_user()['id'] ?? 0) ?: null,
        ]);
        $pick_id = (int)db()->lastInsertId();

        // Build the pick_items rows.
        $items = db()->prepare(
            'SELECT id, product_id, qty_ordered FROM so_items WHERE so_id = ? ORDER BY id'
        );
        $items->execute([$so_id]);

        $insPick = db()->prepare(
            'INSERT INTO pick_items
               (pick_list_id, so_item_id, product_id, suggested_bin_id,
                qty_to_pick, walk_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $candidates = []; // collected rows with ordering metadata for sort

        foreach ($items->fetchAll() as $line) {
            $remaining = (float)$line['qty_ordered'];
            // Layers in FIFO order, with the bin's walk-path tuple.
            $layers = db()->prepare(
                "SELECT l.id        AS layer_id,
                        l.qty_remaining,
                        b.id        AS bin_id,
                        b.full_code AS bin_code,
                        z.code      AS zone_code,
                        r.code      AS rack_code,
                        b.code      AS short_code
                   FROM stock_layers l
                   JOIN bins  b ON b.id = l.bin_id
                   JOIN racks r ON r.id = b.rack_id
                   JOIN zones z ON z.id = r.zone_id
                  WHERE l.product_id   = ?
                    AND l.warehouse_id = ?
                    AND l.status       = 'ACTIVE'
                    AND l.qty_remaining > 0
               ORDER BY l.received_at ASC, l.id ASC"
            );
            $layers->execute([(int)$line['product_id'], (int)$so['warehouse_id']]);

            // Group by bin so we don't generate one pick_item per layer.
            $perBin = [];
            foreach ($layers->fetchAll() as $lyr) {
                $bid = (int)$lyr['bin_id'];
                if (!isset($perBin[$bid])) {
                    $perBin[$bid] = [
                        'bin_id'    => $bid,
                        'bin_code'  => $lyr['bin_code'],
                        'zone_code' => $lyr['zone_code'],
                        'rack_code' => $lyr['rack_code'],
                        'short_code'=> $lyr['short_code'],
                        'available' => 0.0,
                    ];
                }
                $perBin[$bid]['available'] += (float)$lyr['qty_remaining'];
            }

            // Allocate across bins until $remaining hits 0 (or we run out).
            foreach ($perBin as $b) {
                if ($remaining <= 0) break;
                $take = min($remaining, (float)$b['available']);
                if ($take <= 0) continue;
                $candidates[] = [
                    'so_item_id'   => (int)$line['id'],
                    'product_id'   => (int)$line['product_id'],
                    'bin_id'       => (int)$b['bin_id'],
                    'qty_to_pick'  => round($take, 4),
                    'zone_code'    => $b['zone_code'],
                    'rack_code'    => $b['rack_code'],
                    'short_code'   => $b['short_code'],
                ];
                $remaining = round($remaining - $take, 4);
            }

            // Short-stock fallback — emit a pick_item with no suggested_bin
            // so the picker can fill it from wherever once stock arrives.
            if ($remaining > 0) {
                $candidates[] = [
                    'so_item_id'  => (int)$line['id'],
                    'product_id'  => (int)$line['product_id'],
                    'bin_id'      => null,
                    'qty_to_pick' => round($remaining, 4),
                    'zone_code'   => 'ZZZ',  // sort to the end
                    'rack_code'   => 'ZZ',
                    'short_code'  => 'ZZ',
                ];
            }
        }

        // Walk-path sort.
        usort($candidates, function ($a, $b) {
            return [$a['zone_code'], $a['rack_code'], $a['short_code']]
               <=> [$b['zone_code'], $b['rack_code'], $b['short_code']];
        });
        $walk = 1;
        foreach ($candidates as $c) {
            $insPick->execute([
                $pick_id,
                $c['so_item_id'],
                $c['product_id'],
                $c['bin_id'],
                $c['qty_to_pick'],
                $walk++,
            ]);
        }

        // Move the SO into PICKING state.
        db()->prepare('UPDATE sales_orders SET status = "PICKING" WHERE id = ?')->execute([$so_id]);

        audit_log('pick_list_create', 'pick_lists', $pick_id, [
            'so_id' => $so_id, 'lines' => count($candidates),
        ]);
        return $pick_id;
    });
}

function so_warehouse_code(int $warehouse_id): string
{
    $stmt = db()->prepare('SELECT code FROM warehouses WHERE id = ? LIMIT 1');
    $stmt->execute([$warehouse_id]);
    return (string)($stmt->fetchColumn() ?: '');
}

// -----------------------------------------------------------------------------
// Phase 7 — pick execution
// -----------------------------------------------------------------------------

/**
 * Execute one pick. Wraps record_issue(movement_type=PICK) and updates the
 * pick_item + the parent pick_list / sales_order status.
 *
 * Idempotency: if the same scan_uuid is seen twice on the same pick_item,
 * the second call is a no-op and returns the previously-recorded
 * movement_id. record_issue() also has its own scan_uuid UNIQUE guard,
 * but checking here lets us short-circuit before any work.
 *
 * @return array{movement_id:int, pick_item_id:int, pick_list_id:int,
 *               pick_list_status:string, already?:bool}
 */
function pick_execute(
    int $pick_item_id,
    int $bin_id,
    float $qty,
    ?string $scan_uuid = null,
    ?int $user_id = null
): array {
    if ($qty <= 0) throw new InvalidArgumentException('qty must be > 0.');

    return db_tx(function () use ($pick_item_id, $bin_id, $qty, $scan_uuid, $user_id) {
        $stmt = db()->prepare(
            'SELECT pi.id           AS pick_item_id,
                    pi.so_item_id,
                    pi.product_id,
                    pi.qty_to_pick,
                    pi.qty_picked,
                    pi.suggested_bin_id,
                    pi.scan_uuid    AS existing_uuid,
                    pi.movement_id  AS existing_movement,
                    pl.id           AS pick_list_id,
                    pl.warehouse_id,
                    pl.company_id,
                    pl.status       AS pl_status,
                    pl.so_id
               FROM pick_items pi
               JOIN pick_lists pl ON pl.id = pi.pick_list_id
              WHERE pi.id = ?
              FOR UPDATE'
        );
        $stmt->execute([$pick_item_id]);
        $pi = $stmt->fetch();
        if (!$pi)                                                              throw new RuntimeException('Pick item not found.');
        require_warehouse_access((int)$pi['warehouse_id']);
        if (in_array($pi['pl_status'], ['CANCELLED', 'COMPLETED'], true)) {
            throw new RuntimeException("Pick list is {$pi['pl_status']}; no more picks accepted.");
        }

        // Same-uuid replay → return the previous result.
        if ($scan_uuid !== null && $scan_uuid !== '' && $pi['existing_uuid'] === $scan_uuid) {
            return [
                'movement_id'      => (int)$pi['existing_movement'],
                'pick_item_id'     => $pick_item_id,
                'pick_list_id'     => (int)$pi['pick_list_id'],
                'pick_list_status' => (string)$pi['pl_status'],
                'already'          => true,
            ];
        }

        $remaining = (float)$pi['qty_to_pick'] - (float)$pi['qty_picked'];
        if ($qty > $remaining + 1e-9) {
            throw new RuntimeException(sprintf(
                'Pick qty %s exceeds remaining %s on this line.',
                rtrim(rtrim(number_format($qty, 4, '.', ''),       '0'), '.'),
                rtrim(rtrim(number_format($remaining, 4, '.', ''), '0'), '.')
            ));
        }

        // FIFO consume in the chosen bin (record_issue handles the bin-in-
        // warehouse check too, but failing fast here gives a clearer error).
        $issue = record_issue(
            (int)$pi['company_id'],
            (int)$pi['warehouse_id'],
            (int)$pi['product_id'],
            $bin_id,
            $qty,
            'PICK',
            'pick_list',
            (int)$pi['pick_list_id'],
            $pick_item_id,
            $user_id,
            $scan_uuid,
            'Pick item #' . $pick_item_id
        );
        $movement_id = (int)$issue['movement_id'];

        // Update the pick_item.
        db()->prepare(
            'UPDATE pick_items
                SET qty_picked         = qty_picked + ?,
                    picked_from_bin_id = ?,
                    movement_id        = ?,
                    scan_uuid          = COALESCE(?, scan_uuid),
                    picked_at          = NOW(),
                    picked_by          = ?
              WHERE id = ?'
        )->execute([$qty, $bin_id, $movement_id, $scan_uuid, $user_id, $pick_item_id]);

        // Bump the SO line's qty_picked so the SO header stays accurate.
        db()->prepare(
            'UPDATE so_items SET qty_picked = qty_picked + ? WHERE id = ?'
        )->execute([$qty, (int)$pi['so_item_id']]);

        $newStatus = pick_list_recompute_status((int)$pi['pick_list_id']);

        audit_log('pick_execute', 'pick_items', $pick_item_id, [
            'pick_list_id' => (int)$pi['pick_list_id'],
            'qty'          => $qty,
            'bin_id'       => $bin_id,
            'movement_id'  => $movement_id,
        ]);

        return [
            'movement_id'      => $movement_id,
            'pick_item_id'     => $pick_item_id,
            'pick_list_id'     => (int)$pi['pick_list_id'],
            'pick_list_status' => $newStatus,
        ];
    });
}

/**
 * Recompute the pick list's status from its items.
 *   no items touched           → unchanged (stays DRAFT or whatever)
 *   any item touched           → IN_PROGRESS, started_at stamped
 *   every item fully picked    → COMPLETED, completed_at stamped, then
 *                                pick_list_maybe_close_so() may flip the
 *                                parent SO PICKING → PICKED.
 *
 * CANCELLED / COMPLETED are terminal and never auto-revert.
 */
function pick_list_recompute_status(int $pick_list_id): string
{
    $stmt = db()->prepare(
        'SELECT pl.status AS cur_status, pl.so_id,
                COUNT(pi.id) AS line_count,
                SUM(CASE WHEN pi.qty_picked >= pi.qty_to_pick AND pi.qty_to_pick > 0 THEN 1 ELSE 0 END) AS lines_full,
                SUM(CASE WHEN pi.qty_picked > 0 THEN 1 ELSE 0 END) AS lines_touched
           FROM pick_lists pl
      LEFT JOIN pick_items pi ON pi.pick_list_id = pl.id
          WHERE pl.id = ?
       GROUP BY pl.id'
    );
    $stmt->execute([$pick_list_id]);
    $row = $stmt->fetch();
    if (!$row) return '';

    $cur = (string)$row['cur_status'];
    if (in_array($cur, ['CANCELLED', 'COMPLETED'], true)) return $cur;

    $lineCount    = (int)$row['line_count'];
    $linesFull    = (int)$row['lines_full'];
    $linesTouched = (int)$row['lines_touched'];

    if ($lineCount > 0 && $linesFull === $lineCount) {
        db()->prepare(
            'UPDATE pick_lists
                SET status = "COMPLETED",
                    started_at   = COALESCE(started_at, NOW()),
                    completed_at = COALESCE(completed_at, NOW())
              WHERE id = ?'
        )->execute([$pick_list_id]);
        pick_list_maybe_close_so((int)$row['so_id']);
        return 'COMPLETED';
    }

    if ($linesTouched > 0) {
        db()->prepare(
            'UPDATE pick_lists
                SET status = "IN_PROGRESS",
                    started_at = COALESCE(started_at, NOW())
              WHERE id = ?'
        )->execute([$pick_list_id]);
        return 'IN_PROGRESS';
    }

    return $cur;
}

/**
 * If every so_item has been fully picked, flip the SO PICKING → PICKED.
 */
function pick_list_maybe_close_so(int $so_id): void
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS line_count,
                SUM(CASE WHEN qty_picked >= qty_ordered AND qty_ordered > 0 THEN 1 ELSE 0 END) AS lines_full
           FROM so_items
          WHERE so_id = ?'
    );
    $stmt->execute([$so_id]);
    $row = $stmt->fetch();
    if ($row && (int)$row['line_count'] > 0 && (int)$row['lines_full'] === (int)$row['line_count']) {
        db()->prepare(
            'UPDATE sales_orders SET status = "PICKED" WHERE id = ? AND status = "PICKING"'
        )->execute([$so_id]);
    }
}
