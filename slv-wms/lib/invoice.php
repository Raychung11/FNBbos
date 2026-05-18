<?php
// SLV WMS — lib/invoice.php
// Purpose: Phase 8 invoice + DO engine. Generates an invoice from a fully-
//          picked SO using qty_picked (not qty_ordered, in case of short
//          picks), persists the per-tax-code breakdown into invoice_taxes
//          for SST filing, and offers do_create / do_dispatch / do_deliver
//          helpers to drive the DO state machine.
// Last updated: 2026-04-30

declare(strict_types=1);

/**
 * Generate an invoice from a picked SO. Uses so_items.qty_picked as the
 * authoritative qty (skip lines with qty_picked = 0) so a short-pick
 * produces a short-bill rather than billing for unsupplied stock.
 *
 * Flips the SO status PICKED → INVOICED on success.
 *
 * @return int new invoice id
 */
function invoice_generate_from_so(int $so_id, ?int $user_id = null): int
{
    return db_tx(function () use ($so_id, $user_id) {
        $stmt = db()->prepare(
            'SELECT id, company_id, warehouse_id, customer_id, status
               FROM sales_orders WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$so_id]);
        $so = $stmt->fetch();
        if (!$so) throw new RuntimeException('Sales order not found.');
        if (!in_array($so['status'], ['PICKED','PICKING'], true)) {
            throw new RuntimeException("Cannot invoice an SO in {$so['status']}.");
        }

        // Refuse if there's already an active invoice for this SO.
        $existing = db()->prepare(
            "SELECT id FROM invoices WHERE so_id = ? AND status NOT IN ('CANCELLED','VOID') LIMIT 1"
        );
        $existing->execute([$so_id]);
        if ($existing->fetchColumn() !== false) {
            throw new RuntimeException('An active invoice already exists for this SO.');
        }

        // Pull billable lines (qty_picked > 0).
        $items = db()->prepare(
            'SELECT id, product_id, qty_picked, unit_price, tax_group_id, notes
               FROM so_items
              WHERE so_id = ? AND qty_picked > 0
           ORDER BY id'
        );
        $items->execute([$so_id]);
        $lines = $items->fetchAll();
        if (!$lines) throw new RuntimeException('No picked qty to invoice.');

        $invoiceNo = next_doc_no('INV');
        db()->prepare(
            'INSERT INTO invoices
               (company_id, warehouse_id, invoice_no, so_id, customer_id,
                invoice_date, status, created_by)
             VALUES (?, ?, ?, ?, ?, CURDATE(), "DRAFT", ?)'
        )->execute([
            (int)$so['company_id'], (int)$so['warehouse_id'], $invoiceNo,
            (int)$so['id'], (int)$so['customer_id'], $user_id,
        ]);
        $invoice_id = (int)db()->lastInsertId();

        // Items + tax compute.
        $insLine = db()->prepare(
            'INSERT INTO invoice_items
               (invoice_id, product_id, qty, unit_price, tax_group_id,
                line_subtotal, line_tax, line_total, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $lineResults = [];
        foreach ($lines as $l) {
            $sub = (float)$l['qty_picked'] * (float)$l['unit_price'];
            $r   = tax_compute_line($sub, $l['tax_group_id'] === null ? null : (int)$l['tax_group_id']);
            $insLine->execute([
                $invoice_id, (int)$l['product_id'], (float)$l['qty_picked'],
                (float)$l['unit_price'], $l['tax_group_id'],
                $r['subtotal'], $r['tax_total'], $r['line_total'],
                $l['notes'] ?: null,
            ]);
            $lineResults[] = $r;
        }
        $agg = tax_aggregate($lineResults);

        // Persist the per-code breakdown for SST reporting.
        $insTax = db()->prepare(
            'INSERT INTO invoice_taxes
               (invoice_id, tax_code_id, rate, taxable_amount, tax_amount)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($agg['per_code'] as $b) {
            $insTax->execute([
                $invoice_id, (int)$b['tax_code_id'], (float)$b['rate'],
                (float)$b['taxable_amount'], (float)$b['tax_amount'],
            ]);
        }

        // Header totals.
        db()->prepare(
            'UPDATE invoices
                SET subtotal = ?, tax_total = ?, grand_total = ?
              WHERE id = ?'
        )->execute([$agg['subtotal'], $agg['tax_total'], $agg['grand_total'], $invoice_id]);

        // Flip the SO.
        db()->prepare('UPDATE sales_orders SET status = "INVOICED" WHERE id = ?')->execute([$so_id]);

        audit_log('invoice_create', 'invoices', $invoice_id, [
            'invoice_no' => $invoiceNo, 'so_id' => $so_id,
            'lines' => count($lines), 'grand_total' => $agg['grand_total'],
        ]);

        return $invoice_id;
    });
}

/**
 * Create a Delivery Order from an invoice. Lines mirror invoice_items.
 * One DO per invoice for now (Phase 8). Returns new do_id.
 */
function do_create_from_invoice(int $invoice_id, ?int $user_id = null): int
{
    return db_tx(function () use ($invoice_id, $user_id) {
        $stmt = db()->prepare(
            'SELECT id, company_id, warehouse_id, customer_id, status
               FROM invoices WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$invoice_id]);
        $inv = $stmt->fetch();
        if (!$inv) throw new RuntimeException('Invoice not found.');
        if (in_array($inv['status'], ['VOID','CANCELLED'], true)) {
            throw new RuntimeException("Cannot create DO for a {$inv['status']} invoice.");
        }

        $existing = db()->prepare(
            "SELECT id FROM delivery_orders WHERE invoice_id = ? AND status <> 'CANCELLED' LIMIT 1"
        );
        $existing->execute([$invoice_id]);
        if ($existing->fetchColumn() !== false) {
            throw new RuntimeException('A delivery order already exists for this invoice.');
        }

        $doNo = next_doc_no('DO');
        db()->prepare(
            'INSERT INTO delivery_orders
               (company_id, warehouse_id, do_no, invoice_id, customer_id,
                status, created_by)
             VALUES (?, ?, ?, ?, ?, "READY", ?)'
        )->execute([
            (int)$inv['company_id'], (int)$inv['warehouse_id'], $doNo,
            (int)$inv['id'], (int)$inv['customer_id'], $user_id,
        ]);
        $do_id = (int)db()->lastInsertId();

        $items = db()->prepare(
            'SELECT product_id, qty FROM invoice_items WHERE invoice_id = ? ORDER BY id'
        );
        $items->execute([$invoice_id]);
        $insLine = db()->prepare(
            'INSERT INTO do_items (do_id, product_id, qty) VALUES (?, ?, ?)'
        );
        foreach ($items->fetchAll() as $r) {
            $insLine->execute([$do_id, (int)$r['product_id'], (float)$r['qty']]);
        }

        audit_log('do_create', 'delivery_orders', $do_id, [
            'do_no' => $doNo, 'invoice_id' => $invoice_id,
        ]);
        return $do_id;
    });
}

/**
 * Mark a DO as dispatched. Sets driver + vehicle, status READY → IN_TRANSIT,
 * stamps dispatched_at.
 */
function do_dispatch(int $do_id, ?int $driver_user_id, ?string $vehicle, ?int $user_id = null): void
{
    db_tx(function () use ($do_id, $driver_user_id, $vehicle, $user_id) {
        $stmt = db()->prepare('SELECT status FROM delivery_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$do_id]);
        $cur = (string)($stmt->fetchColumn() ?: '');
        if ($cur === '')           throw new RuntimeException('DO not found.');
        if ($cur !== 'READY')      throw new RuntimeException("Cannot dispatch a {$cur} DO.");

        db()->prepare(
            'UPDATE delivery_orders
                SET status = "IN_TRANSIT",
                    driver_user_id = ?, vehicle = ?, dispatched_at = NOW()
              WHERE id = ?'
        )->execute([$driver_user_id, $vehicle, $do_id]);

        audit_log('do_dispatch', 'delivery_orders', $do_id, [
            'driver_user_id' => $driver_user_id, 'vehicle' => $vehicle,
        ]);
    });
}

/**
 * Mark a DO as delivered. Stamps delivered_at; flips parent SO INVOICED →
 * DELIVERED if every active DO for the invoice is delivered.
 */
function do_mark_delivered(int $do_id, ?int $user_id = null): void
{
    db_tx(function () use ($do_id) {
        $stmt = db()->prepare(
            'SELECT id, invoice_id, status FROM delivery_orders WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$do_id]);
        $row = $stmt->fetch();
        if (!$row)                            throw new RuntimeException('DO not found.');
        if ($row['status'] !== 'IN_TRANSIT')  throw new RuntimeException("Cannot deliver a {$row['status']} DO.");

        db()->prepare(
            'UPDATE delivery_orders SET status = "DELIVERED", delivered_at = NOW() WHERE id = ?'
        )->execute([$do_id]);

        // Flip parent SO if every DO for the invoice is now DELIVERED.
        $sCheck = db()->prepare(
            'SELECT i.so_id,
                    COUNT(d.id) AS total,
                    SUM(CASE WHEN d.status = "DELIVERED" THEN 1 ELSE 0 END) AS done
               FROM invoices i
          LEFT JOIN delivery_orders d ON d.invoice_id = i.id AND d.status <> "CANCELLED"
              WHERE i.id = ?
           GROUP BY i.id'
        );
        $sCheck->execute([(int)$row['invoice_id']]);
        $s = $sCheck->fetch();
        if ($s && (int)$s['total'] > 0 && (int)$s['done'] === (int)$s['total']) {
            db()->prepare(
                'UPDATE sales_orders SET status = "DELIVERED" WHERE id = ? AND status = "INVOICED"'
            )->execute([(int)$s['so_id']]);
        }
        audit_log('do_delivered', 'delivery_orders', $do_id);
    });
}
