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
