<?php
// SLV WMS — pages/invoices/print.php
// Purpose: A4 print view of one invoice. Designed for browser
//          "Print → Save as PDF" until mPDF is dropped into vendor_local/
//          (Phase 12 polish). Reads logo + colours from app_settings so
//          the output is on-brand.
// Roles allowed: super_admin, warehouse_manager, sales, packer, viewer
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','sales','packer','viewer']);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); echo 'Missing id.'; exit; }

$stmt = db()->prepare(
    'SELECT inv.*, w.code AS warehouse_code, w.name AS warehouse_name,
            w.address AS warehouse_address,
            c.code AS customer_code, c.name AS customer_name,
            c.billing_address, c.shipping_address, c.tax_no AS customer_tax_no,
            c.contact_person AS customer_contact, c.phone AS customer_phone,
            so.so_no, so.order_date
       FROM invoices inv
       JOIN warehouses w   ON w.id = inv.warehouse_id
       JOIN customers   c  ON c.id = inv.customer_id
       JOIN sales_orders so ON so.id = inv.so_id
      WHERE inv.id = ? AND inv.company_id = ?'
);
$stmt->execute([$id, company_id()]);
$inv = $stmt->fetch();
if (!$inv) { http_response_code(404); echo 'Not found.'; exit; }
require_warehouse_access((int)$inv['warehouse_id']);

$lines = db()->prepare(
    'SELECT ii.*, p.sku_code, p.name AS product_name, p.uom, tg.code AS tg_code
       FROM invoice_items ii
       JOIN products p ON p.id = ii.product_id
  LEFT JOIN tax_groups tg ON tg.id = ii.tax_group_id
      WHERE ii.invoice_id = ? ORDER BY ii.id'
);
$lines->execute([$id]);
$items = $lines->fetchAll();

$tStmt = db()->prepare(
    'SELECT it.*, tc.code, tc.name FROM invoice_taxes it
       JOIN tax_codes tc ON tc.id = it.tax_code_id
      WHERE it.invoice_id = ? ORDER BY tc.code'
);
$tStmt->execute([$id]);
$taxes = $tStmt->fetchAll();

$company   = company_record();
$primary   = setting('brand.primary_color', '#6D28D9');
$secondary = setting('brand.secondary_color', '#1F2937');
$logoPath  = setting('brand.logo_path', '');
$logoAbs   = $logoPath !== '' ? dirname(__DIR__, 2) . $logoPath : '';
$hasLogo   = $logoPath !== '' && is_file($logoAbs);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice <?= e_($inv['invoice_no']) ?> · <?= e_($company['name'] ?? '') ?></title>
<style>
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #1f2937; font-size: 11pt; }
  .page { padding: 18mm 15mm; max-width: 210mm; margin: 0 auto; }

  .row { display: flex; justify-content: space-between; align-items: flex-start; gap: 16mm; }
  .brand { flex: 1; }
  .brand .logo { height: 18mm; margin-bottom: 6px; }
  .brand-name { font-size: 16pt; font-weight: 700; color: <?= e_($secondary) ?>; }
  .brand-meta { color: #4b5563; font-size: 9.5pt; line-height: 1.45; margin-top: 4px; white-space: pre-line; }

  .title-box { text-align: right; min-width: 65mm; }
  .title-box h1 { margin: 0; font-size: 20pt; color: <?= e_($primary) ?>; letter-spacing: 1px; }
  .title-box .meta { font-size: 10pt; color: #4b5563; margin-top: 4px; line-height: 1.5; }
  .title-box .meta strong { color: #111827; }

  .blocks { display: flex; gap: 10mm; margin-top: 10mm; }
  .block { flex: 1; border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px 10px; }
  .block .label { font-size: 8pt; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
  .block .body  { font-size: 10pt; line-height: 1.45; white-space: pre-line; }

  table.lines { width: 100%; border-collapse: collapse; margin-top: 10mm; }
  table.lines thead th {
    background: <?= e_($secondary) ?>; color: #fff; font-size: 9pt; font-weight: 600;
    padding: 6px 8px; text-align: left; letter-spacing: 0.4px;
  }
  table.lines tbody td {
    padding: 6px 8px; border-bottom: 1px solid #e5e7eb; font-size: 10pt; vertical-align: top;
  }
  .ralign { text-align: right; }
  .calign { text-align: center; }
  .mono   { font-family: 'SFMono-Regular', Menlo, Consolas, monospace; }

  .totals { width: 80mm; margin-left: auto; margin-top: 6mm; }
  .totals .row { display: flex; justify-content: space-between; padding: 3px 0; font-size: 10pt; }
  .totals .row.grand { border-top: 2px solid <?= e_($secondary) ?>; padding-top: 6px; margin-top: 4px; font-size: 12pt; font-weight: 700; color: <?= e_($primary) ?>; }

  .tax-table { width: 100%; border-collapse: collapse; margin-top: 6mm; font-size: 9pt; }
  .tax-table th, .tax-table td { padding: 4px 8px; border-bottom: 1px solid #e5e7eb; text-align: left; }
  .tax-table th { background: #f9fafb; color: #374151; font-weight: 600; }

  .foot { margin-top: 14mm; font-size: 9pt; color: #6b7280; }
  .foot .small { font-size: 8pt; }

  .actions { padding: 10px 15mm; background: #f3f4f6; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; font-size: 11pt; }
  .actions a, .actions button { font-size: 10pt; }

  @media print {
    .actions { display: none; }
    @page { size: A4; margin: 0; }
    .page { padding: 18mm 15mm; }
    body  { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>

<div class="actions">
  <a href="/pages/invoices/view.php?id=<?= e_($id) ?>">&larr; Back to invoice</a>
  <button onclick="window.print()" style="padding: 6px 14px; background: <?= e_($primary) ?>; color:#fff; border:0; border-radius:4px; cursor:pointer;">
    Print &middot; or Save as PDF
  </button>
</div>

<div class="page">
  <div class="row">
    <div class="brand">
      <?php if ($hasLogo): ?>
        <img src="<?= e_($logoPath) ?>" class="logo" alt="<?= e_($company['name'] ?? '') ?>">
      <?php else: ?>
        <div style="display:inline-block;background:<?= e_($primary) ?>;color:#fff;font-weight:700;padding:6px 10px;border-radius:6px;">SLV</div>
      <?php endif; ?>
      <div class="brand-name"><?= e_($company['name'] ?? 'SLV Group Sdn. Bhd.') ?></div>
      <div class="brand-meta"><?php
        $bits = array_filter([
            $company['address']         ?? '',
            $company['phone']           ?? '',
            $company['email']           ?? '',
            ($company['registration_no'] ?? '') ? 'Reg no: ' . $company['registration_no'] : '',
            ($company['tax_no']          ?? '') ? 'Tax no: ' . $company['tax_no'] : '',
        ]);
        echo e_(implode("\n", $bits));
      ?></div>
    </div>
    <div class="title-box">
      <h1>TAX INVOICE</h1>
      <div class="meta">
        <div><strong>No:</strong> <span class="mono"><?= e_($inv['invoice_no']) ?></span></div>
        <div><strong>Date:</strong> <?= e_($inv['invoice_date']) ?></div>
        <div><strong>SO:</strong> <span class="mono"><?= e_($inv['so_no']) ?></span></div>
        <div><strong>Status:</strong> <?= e_($inv['status']) ?></div>
      </div>
    </div>
  </div>

  <div class="blocks">
    <div class="block">
      <div class="label">Bill to</div>
      <div class="body"><strong><?= e_($inv['customer_name']) ?></strong>
        <?= "\n" ?>Code: <span class="mono"><?= e_($inv['customer_code']) ?></span><?php
        if ($inv['billing_address']) echo "\n" . e_($inv['billing_address']);
        if ($inv['customer_tax_no']) echo "\nTax no: " . e_($inv['customer_tax_no']);
        if ($inv['customer_contact']) echo "\nAttn: " . e_($inv['customer_contact']);
        if ($inv['customer_phone']) echo "\nPhone: " . e_($inv['customer_phone']);
?></div>
    </div>
    <div class="block">
      <div class="label">Ship from</div>
      <div class="body"><strong><?= e_($inv['warehouse_name']) ?></strong>
<?= "\n" ?>Code: <span class="mono"><?= e_($inv['warehouse_code']) ?></span><?php
        if ($inv['warehouse_address']) echo "\n" . e_($inv['warehouse_address']);
?></div>
    </div>
  </div>

  <table class="lines">
    <thead>
      <tr>
        <th style="width: 8mm;">#</th>
        <th>SKU / description</th>
        <th class="ralign" style="width: 20mm;">Qty</th>
        <th class="calign" style="width: 12mm;">UoM</th>
        <th class="ralign" style="width: 24mm;">Unit price</th>
        <th class="calign" style="width: 16mm;">Tax</th>
        <th class="ralign" style="width: 26mm;">Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $i => $l): ?>
        <tr>
          <td><?= ($i + 1) ?></td>
          <td>
            <div class="mono"><?= e_($l['sku_code']) ?></div>
            <div style="color:#6b7280; font-size:9pt;"><?= e_($l['product_name']) ?></div>
          </td>
          <td class="ralign"><?= e_(qty($l['qty'])) ?></td>
          <td class="calign"><?= e_($l['uom']) ?></td>
          <td class="ralign"><?= e_(money($l['unit_price'])) ?></td>
          <td class="calign mono" style="font-size:9pt;"><?= e_($l['tg_code'] ?: '—') ?></td>
          <td class="ralign"><?= e_(money($l['line_total'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="totals">
    <div class="row"><span>Subtotal</span><span class="mono"><?= e_(money($inv['subtotal'])) ?></span></div>
    <?php foreach ($taxes as $t): ?>
      <div class="row">
        <span><?= e_($t['code']) ?> <span style="color:#6b7280;">(<?= e_(number_format((float)$t['rate'] * 100, 2)) ?>%)</span></span>
        <span class="mono"><?= e_(money($t['tax_amount'])) ?></span>
      </div>
    <?php endforeach; ?>
    <div class="row grand"><span>GRAND TOTAL</span><span class="mono"><?= e_(money($inv['grand_total'])) ?></span></div>
  </div>

  <?php if ($taxes): ?>
  <table class="tax-table">
    <thead><tr><th>SST breakdown</th><th class="ralign">Taxable</th><th class="ralign">Tax</th></tr></thead>
    <tbody>
      <?php foreach ($taxes as $t): ?>
      <tr>
        <td><?= e_($t['code']) ?> &mdash; <?= e_($t['name']) ?> @ <?= e_(number_format((float)$t['rate'] * 100, 2)) ?>%</td>
        <td class="ralign mono"><?= e_(money($t['taxable_amount'])) ?></td>
        <td class="ralign mono"><?= e_(money($t['tax_amount'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if ($inv['notes']): ?>
    <div class="foot"><strong>Notes:</strong> <?= e_($inv['notes']) ?></div>
  <?php endif; ?>

  <div class="foot">
    Generated on <?= e_(date('Y-m-d H:i')) ?>.
    <span class="small">This document was produced by SLV WMS.</span>
  </div>
</div>

</body>
</html>
