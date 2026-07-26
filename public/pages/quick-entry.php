<?php
require __DIR__ . '/../../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Rbac;
use FNBBOS\Csrf;
use FNBBOS\AuditLog;
use FNBBOS\Engine\QuickEntryParser;
use FNBBOS\Engine\FeeEngine;
use FNBBOS\Engine\TaxEngine;
use FNBBOS\Engine\RiskEngine;
use FNBBOS\ApprovalRouter;

Auth::requireLogin();

// A user can quick-enter anything they can normally enter.
$canClaim = Rbac::can('claims.submit');
$canSales = Rbac::can('sales.import');
$canBank  = Rbac::can('bank.import');
if (!$canClaim && !$canSales && !$canBank) Rbac::require('claims.submit'); // 403

$companyId = Auth::companyId();
$type      = (string)(input('type') ?: 'claim');
$text      = (string)input('text', '');
$parsed    = null;
$errors    = [];

// Step 1: PARSE (idempotent).
if ($text !== '' && (string)input('do') === 'parse') {
    if ($type === 'claim' && !$canClaim) $errors[] = 'You do not have permission to submit claims.';
    if ($type === 'sales' && !$canSales) $errors[] = 'You do not have permission to import sales.';
    if ($type === 'bank'  && !$canBank)  $errors[] = 'You do not have permission to import bank transactions.';
    if (!$errors) {
        $parsed = match ($type) {
            'sales' => QuickEntryParser::parseSales($text, $companyId),
            'bank'  => QuickEntryParser::parseBank($text, $companyId),
            default => QuickEntryParser::parseClaim($text, $companyId),
        };
    }
}

// Step 2: COMMIT after user confirms.
if (requestMethod() === 'POST' && (string)input('do') === 'commit') {
    Csrf::requireValid();
    $type   = (string)input('type');
    try {
        if ($type === 'claim') { $id = commitClaim($companyId); flash('ok', "Claim submitted (#{$id})."); }
        elseif ($type === 'sales') { $id = commitSalesOrder($companyId); flash('ok', "Sales order created (#{$id})."); }
        elseif ($type === 'bank')  { $id = commitBankTransaction($companyId); flash('ok', "Bank transaction recorded (#{$id})."); }
        else throw new RuntimeException('Unknown target type: ' . $type);
    } catch (Throwable $e) {
        flash('error', 'Commit failed: ' . $e->getMessage());
    }
    redirect('pages/quick-entry.php?type=' . urlencode($type));
}

function commitClaim(int $companyId): int
{
    $type   = (string)input('claim_type', 'other');
    $amount = asFloat(input('amount'));
    $date   = (string)(input('claim_date') ?: date('Y-m-d'));
    $outletCode = strtolower(trim((string)input('outlet_code')));
    $supplier   = (string)input('supplier');
    $payment    = (string)input('payment_method');
    $desc       = (string)input('description');

    if ($amount <= 0) throw new RuntimeException('Amount must be greater than 0.');

    $outletId = resolveOutletId($companyId, $outletCode);
    if (!$outletId) throw new RuntimeException('Outlet code not recognised: ' . $outletCode);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $claimCode = 'CLM-' . date('Ymd') . '-' . strtoupper(substr(uuid(), 0, 6));
        $now = nowDb();
        $pdo->prepare('
            INSERT INTO claims
              (company_id, claimant_id, outlet_id, claim_id, claim_type, claim_date, amount,
               supplier, description, payment_method,
               approval_status, paid_status, submitted_at, created_at)
            VALUES (?,?,?,?,?,?,?, ?, ?, ?, "submitted","unpaid", ?, ?)
        ')->execute([
            $companyId, Auth::id(), $outletId, $claimCode, $type, $date, $amount,
            $supplier ?: null, $desc ?: null, $payment ?: null,
            $now, $now,
        ]);
        $claimId = (int)$pdo->lastInsertId();

        // Fire a light risk score (no OCR / receipt available in quick-entry).
        $risk = RiskEngine::score(
            ['amount' => $amount, 'claim_type' => $type, 'submitted_at' => $now],
            ['outlet_monthly_budget' => 0, 'claimant_role' => Rbac::role()]
        );
        $pdo->prepare('UPDATE claims SET risk_score = ?, risk_level = ?, risk_explanation = ? WHERE id = ?')
            ->execute([$risk['score'], $risk['level'], $risk['explanation'], $claimId]);
        $pdo->prepare('INSERT INTO claim_risk_scores (claim_id, score, level, explanation, created_at) VALUES (?,?,?,?,?)')
            ->execute([$claimId, $risk['score'], $risk['level'], $risk['explanation'], $now]);

        AuditLog::record('claim.quick_entry', 'claim', $claimId, ['text' => (string)input('source_text')]);
        $pdo->commit();
        return $claimId;
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

function commitSalesOrder(int $companyId): int
{
    $platformCode = strtolower(trim((string)input('platform_code')));
    $outletCode   = strtolower(trim((string)input('outlet_code')));
    $orderId      = (string)input('order_id');
    $orderDate    = (string)(input('order_date') ?: date('Y-m-d'));
    $gross        = asFloat(input('gross_sales'));

    if ($orderId === '') throw new RuntimeException('Order ID is required.');
    if ($gross <= 0)     throw new RuntimeException('Gross sales must be greater than 0.');

    $pdo = db();
    $platformId = resolvePlatformId($companyId, $platformCode);
    $outletId   = resolveOutletId($companyId, $outletCode);
    if (!$platformId) throw new RuntimeException('Platform code not recognised: ' . $platformCode);
    if (!$outletId)   throw new RuntimeException('Outlet code not recognised: ' . $outletCode);

    $dup = $pdo->prepare('SELECT 1 FROM sales_orders WHERE company_id = ? AND order_id = ?');
    $dup->execute([$companyId, $orderId]);
    if ($dup->fetchColumn()) throw new RuntimeException('Duplicate order ID.');

    $rule    = FeeEngine::resolveRule($platformId, $orderDate);
    $taxRule = TaxEngine::resolveRule($outletId, $platformId, $orderDate);
    $tax     = $taxRule ? TaxEngine::calculate($gross, $taxRule) : ['tax' => 0.0, 'taxable' => $gross, 'rate' => 0.0, 'inclusive' => false];
    $sys     = FeeEngine::calculate(['gross_sales' => $gross], $rule, $tax);
    $rec     = FeeEngine::reconcile($sys['net_settlement'], $sys['net_settlement'], $tax['tax'], $sys['tax_amount']);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('
            INSERT INTO sales_orders
                (company_id, platform_id, outlet_id, order_id, order_date,
                 gross_sales, sst_amount, net_settlement_imported, created_at)
            VALUES (?,?,?,?,?, ?, ?, ?, ?)')
            ->execute([$companyId, $platformId, $outletId, $orderId, $orderDate,
                       $gross, $sys['tax_amount'], $sys['net_settlement'], nowDb()]);
        $orderRowId = (int)$pdo->lastInsertId();

        $pdo->prepare('
            INSERT INTO sales_fee_calculations
                (sales_order_id, gross_sales, commission_amount, payment_fee_amount, service_fee_amount,
                 voucher_cost, promotion_cost, refund_amount, delivery_subsidy,
                 adjustment_amount, tax_amount, net_settlement_system,
                 net_settlement_imported, difference, variance_pct,
                 reconciliation_status, created_at)
            VALUES (?,?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?,?,?)')
            ->execute([
                $orderRowId,
                $sys['gross_sales'], $sys['commission_amount'], $sys['payment_fee_amount'], $sys['service_fee_amount'],
                $sys['voucher_cost'], $sys['promotion_cost'], $sys['refund_amount'], $sys['delivery_subsidy'],
                $sys['adjustment_amount'], $sys['tax_amount'], $sys['net_settlement'],
                $sys['net_settlement'], $rec['difference'], $rec['variance_pct'],
                $rec['status'], nowDb(),
            ]);
        AuditLog::record('sales.quick_entry', 'sales_order', $orderRowId, ['text' => (string)input('source_text')]);
        $pdo->commit();
        return $orderRowId;
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

function commitBankTransaction(int $companyId): int
{
    $date       = (string)(input('value_date') ?: date('Y-m-d'));
    $amount     = asFloat(input('amount'));
    $direction  = (string)input('direction');
    $reference  = (string)input('reference');
    $outletCode   = strtolower(trim((string)input('outlet_code')));
    $platformCode = strtolower(trim((string)input('platform_code')));

    if ($amount <= 0) throw new RuntimeException('Amount must be greater than 0.');
    if (!in_array($direction, ['credit','debit'], true)) $direction = 'credit';

    $platformId = $platformCode ? resolvePlatformId($companyId, $platformCode) : null;
    $outletId   = $outletCode   ? resolveOutletId($companyId, $outletCode)     : null;

    $pdo = db();
    $pdo->prepare('INSERT INTO bank_transactions (company_id, value_date, amount, direction, reference, platform_id, outlet_id, created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$companyId, $date, $amount, $direction, $reference ?: null, $platformId, $outletId, nowDb()]);
    $id = (int)$pdo->lastInsertId();
    AuditLog::record('bank.quick_entry', 'bank_transaction', $id, ['text' => (string)input('source_text')]);
    return $id;
}

function resolveOutletId(int $companyId, string $code): ?int
{
    if ($code === '') return null;
    $s = db()->prepare('SELECT id FROM outlets WHERE company_id = ? AND LOWER(code) = ? LIMIT 1');
    $s->execute([$companyId, $code]);
    $v = $s->fetchColumn();
    return $v !== false ? (int)$v : null;
}
function resolvePlatformId(int $companyId, string $code): ?int
{
    if ($code === '') return null;
    $s = db()->prepare('SELECT id FROM platforms WHERE company_id = ? AND LOWER(code) = ? LIMIT 1');
    $s->execute([$companyId, $code]);
    $v = $s->fetchColumn();
    return $v !== false ? (int)$v : null;
}

$pageTitle = 'Quick Entry';
$active    = 'quick-entry';
include __DIR__ . '/../partials/header.php';
?>
<div class="page-header"><div><h2>Quick entry</h2><p>Type a natural sentence — the parser extracts fields, you confirm, done. No forms.</p></div></div>

<div class="card">
  <form method="get" class="form-grid">
    <input type="hidden" name="do" value="parse">
    <div class="form-row"><label>Target</label>
      <select name="type">
        <?php if ($canClaim): ?><option value="claim" <?= $type==='claim'?'selected':'' ?>>Claim</option><?php endif; ?>
        <?php if ($canSales): ?><option value="sales" <?= $type==='sales'?'selected':'' ?>>Sales order</option><?php endif; ?>
        <?php if ($canBank):  ?><option value="bank"  <?= $type==='bank' ?'selected':'' ?>>Bank transaction</option><?php endif; ?>
      </select>
    </div>
    <div class="form-row" style="grid-column: 2 / -1;">
      <label>Text</label>
      <input type="text" name="text" value="<?= e($text) ?>" autofocus
             placeholder="<?= $type==='sales'
                  ? 'e.g. grabfood KL-01 GF-000123 45.00 today'
                  : ($type==='bank'
                      ? 'e.g. credit RM 500 today MAIN-01 grabfood ref SETT-001'
                      : 'e.g. petrol RM 45 KL-01 shell yesterday cash') ?>">
    </div>
    <div class="form-row" style="align-self:end;"><button class="btn btn--primary" type="submit">Parse</button></div>
  </form>
  <p class="hint" style="margin-top:10px;">Recognised: <strong>amount</strong> (RM 45, 45.00), <strong>date</strong> (today, yesterday, 2025-05-01, 15 Jan), <strong>outlet code</strong> (matched against your outlets), <strong>platform code</strong> (matched against your platforms), <strong>supplier</strong> (after @ or "at"), <strong>payment</strong> (cash / card / ewallet), <strong>claim type</strong> (petrol / meal / supplier / etc). Anything missing you can fill in the preview below.</p>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert--error"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if ($parsed): ?>
<div class="card">
  <h3>Preview &amp; confirm</h3>
  <form method="post" class="form-grid">
    <?= Csrf::field() ?>
    <input type="hidden" name="do" value="commit">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <input type="hidden" name="source_text" value="<?= e($text) ?>">

    <?php if ($type === 'claim'): ?>
      <div class="form-row"><label>Claim type</label>
        <select name="claim_type">
          <?php foreach (['staff_meal','petrol','transport','supplier_purchase','petty_cash','marketing','delivery','maintenance','voucher_redemption','other'] as $t): ?>
            <option value="<?= e($t) ?>" <?= $parsed['claim_type']===$t?'selected':'' ?>><?= e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row"><label>Amount (RM)</label><input type="number" step="0.01" name="amount" value="<?= e((string)($parsed['amount'] ?? '')) ?>" required></div>
      <div class="form-row"><label>Outlet code</label><input type="text" name="outlet_code" value="<?= e((string)($parsed['outlet_code'] ?? '')) ?>" required></div>
      <div class="form-row"><label>Claim date</label><input type="date" name="claim_date" value="<?= e($parsed['claim_date']) ?>" required></div>
      <div class="form-row"><label>Supplier</label><input type="text" name="supplier" value="<?= e((string)($parsed['supplier'] ?? '')) ?>"></div>
      <div class="form-row"><label>Payment method</label>
        <select name="payment_method">
          <option value="">—</option>
          <?php foreach (['cash','card','bank_transfer','ewallet','other'] as $p): ?>
            <option value="<?= e($p) ?>" <?= $parsed['payment_method']===$p?'selected':'' ?>><?= e($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row" style="grid-column: 1 / -1;"><label>Description</label><textarea name="description"><?= e((string)($parsed['description'] ?? '')) ?></textarea></div>
    <?php elseif ($type === 'sales'): ?>
      <div class="form-row"><label>Platform code</label><input type="text" name="platform_code" value="<?= e((string)($parsed['platform_code'] ?? '')) ?>" required></div>
      <div class="form-row"><label>Outlet code</label><input type="text" name="outlet_code" value="<?= e((string)($parsed['outlet_code'] ?? '')) ?>" required></div>
      <div class="form-row"><label>Order ID</label><input type="text" name="order_id" value="<?= e((string)($parsed['order_id'] ?? '')) ?>" required></div>
      <div class="form-row"><label>Order date</label><input type="date" name="order_date" value="<?= e($parsed['order_date']) ?>" required></div>
      <div class="form-row"><label>Gross sales (RM)</label><input type="number" step="0.01" name="gross_sales" value="<?= e((string)($parsed['gross_sales'] ?? '')) ?>" required></div>
    <?php elseif ($type === 'bank'): ?>
      <div class="form-row"><label>Value date</label><input type="date" name="value_date" value="<?= e($parsed['value_date']) ?>" required></div>
      <div class="form-row"><label>Amount (RM)</label><input type="number" step="0.01" name="amount" value="<?= e((string)($parsed['amount'] ?? '')) ?>" required></div>
      <div class="form-row"><label>Direction</label>
        <select name="direction">
          <option value="credit" <?= $parsed['direction']==='credit'?'selected':'' ?>>Credit</option>
          <option value="debit"  <?= $parsed['direction']==='debit' ?'selected':'' ?>>Debit</option>
        </select>
      </div>
      <div class="form-row"><label>Reference</label><input type="text" name="reference" value="<?= e((string)($parsed['reference'] ?? '')) ?>"></div>
      <div class="form-row"><label>Platform code</label><input type="text" name="platform_code" value="<?= e((string)($parsed['platform_code'] ?? '')) ?>"></div>
      <div class="form-row"><label>Outlet code</label><input type="text" name="outlet_code" value="<?= e((string)($parsed['outlet_code'] ?? '')) ?>"></div>
    <?php endif; ?>

    <div class="form-row" style="align-self:end;"><button class="btn btn--success" type="submit">Confirm &amp; save</button></div>
  </form>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
