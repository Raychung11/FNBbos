<?php
// SLV WMS — /api/v1/scan/grn_receive.php
// POST — bump qty_received / unit_cost on a GRN line. Idempotent only at
//        the form level (we don't write to stock here — receiving just
//        updates the line ahead of putaway, where stock actually moves).
// Body (JSON or form):
//   grn_id       (required)
//   line_id      (required)
//   qty_received (required, may equal current value to no-op)
//   unit_cost    (required, may equal current value)
// Roles allowed: super_admin, warehouse_manager, receiver
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/_common.php';
$body = api_scan_boot(['super_admin','warehouse_manager','receiver']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST only.');

$grn_id  = (int)($body['grn_id']  ?? 0);
$line_id = (int)($body['line_id'] ?? 0);
$qtyRaw  = $body['qty_received'] ?? null;
$cstRaw  = $body['unit_cost']    ?? null;

if ($grn_id <= 0 || $line_id <= 0)                   json_error(400, 'grn_id and line_id are required.');
if (!is_numeric($qtyRaw) || (float)$qtyRaw < 0)      json_error(400, 'qty_received must be >= 0.');
if (!is_numeric($cstRaw) || (float)$cstRaw < 0)      json_error(400, 'unit_cost must be >= 0.');

$qty  = (float)$qtyRaw;
$cost = (float)$cstRaw;

try {
    $result = db_tx(function () use ($grn_id, $line_id, $qty, $cost) {
        $stmt = db()->prepare(
            'SELECT g.id AS grn_id, g.warehouse_id, g.status,
                    gi.id AS line_id, gi.qty_putaway, gi.product_id
               FROM grn g
               JOIN grn_items gi ON gi.grn_id = g.id
              WHERE g.id = ? AND gi.id = ? AND g.company_id = ?
              FOR UPDATE'
        );
        $stmt->execute([$grn_id, $line_id, company_id()]);
        $row = $stmt->fetch();
        if (!$row)                                                                    throw new RuntimeException('Line not found.');
        require_warehouse_access((int)$row['warehouse_id']);
        if (in_array($row['status'], ['DRAFT','CLOSED','CANCELLED'], true))           throw new RuntimeException("GRN is {$row['status']}; cannot receive.");
        if ($qty + 1e-9 < (float)$row['qty_putaway']) {
            throw new RuntimeException(
                'qty_received cannot be less than qty already putaway (' . (float)$row['qty_putaway'] . ').'
            );
        }

        db()->prepare(
            'UPDATE grn_items SET qty_received = ?, unit_cost = ? WHERE id = ?'
        )->execute([$qty, $cost, $line_id]);

        // Stamp received_at / received_by on the parent if first hit.
        $userId = (int)(current_user()['id'] ?? 0) ?: null;
        db()->prepare(
            'UPDATE grn SET received_at = COALESCE(received_at, NOW()),
                            received_by = COALESCE(received_by, ?)
              WHERE id = ?'
        )->execute([$userId, $grn_id]);

        $newStatus = grn_recompute_status($grn_id);
        audit_log('grn_receive_line_scan', 'grn_items', $line_id, [
            'grn_id' => $grn_id, 'qty' => $qty, 'unit_cost' => $cost, 'status' => $newStatus,
        ]);
        return ['status' => $newStatus, 'line_id' => $line_id];
    });
    json_ok($result);
} catch (Throwable $e) {
    json_error(400, $e->getMessage());
}
