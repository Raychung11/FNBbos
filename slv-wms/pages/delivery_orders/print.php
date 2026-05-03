<?php
// SLV WMS — pages/delivery_orders/print.php
// Purpose: A4 print view of one delivery order. Includes a customer-
//          signature box on the page so the driver can have it signed
//          before Phase 9's mobile POD upload lands.
// Roles allowed: super_admin, warehouse_manager, packer, driver
// Last updated: 2026-04-30

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';
require_role(['super_admin','warehouse_manager','packer','driver','viewer']);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); echo 'Missing id.'; exit; }

$stmt = db()->prepare(
    'SELECT d.*, w.code AS warehouse_code, w.name AS warehouse_name,
            w.address AS warehouse_address,
            c.code AS customer_code, c.name AS customer_name,
            c.shipping_address, c.contact_person AS customer_contact,
            c.phone AS customer_phone,
            inv.invoice_no, inv.so_id,
            so.so_no,
            u.name AS driver_name
       FROM delivery_orders d
       JOIN warehouses w   ON w.id = d.warehouse_id
       JOIN customers   c  ON c.id = d.customer_id
       JOIN invoices   inv ON inv.id = d.invoice_id
       JOIN sales_orders so ON so.id = inv.so_id
  LEFT JOIN users       u  ON u.id = d.driver_user_id
      WHERE d.id = ? AND d.company_id = ?'
);
$stmt->execute([$id, company_id()]);
$do = $stmt->fetch();
if (!$do) { http_response_code(404); echo 'Not found.'; exit; }
require_warehouse_access((int)$do['warehouse_id']);

$lines = db()->prepare(
    'SELECT di.*, p.sku_code, p.name AS product_name, p.uom
       FROM do_items di
       JOIN products p ON p.id = di.product_id
      WHERE di.do_id = ? ORDER BY di.id'
);
$lines->execute([$id]);
$items = $lines->fetchAll();

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
<title>DO <?= e_($do['do_no']) ?> · <?= e_($company['name'] ?? '') ?></title>
<style>
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color:#1f2937; font-size: 11pt; }
  .page { padding: 18mm 15mm; max-width: 210mm; margin: 0 auto; }
  .row { display: flex; justify-content: space-between; align-items: flex-start; gap: 16mm; }
  .brand { flex:1; }
  .brand .logo { height: 18mm; margin-bottom: 6px; }
  .brand-name { font-size: 16pt; font-weight: 700; color: <?= e_($secondary) ?>; }
  .brand-meta { color: #4b5563; font-size: 9.5pt; line-height: 1.45; margin-top: 4px; white-space: pre-line; }
  .title-box { text-align: right; min-width: 65mm; }
  .title-box h1 { margin:0; font-size: 20pt; color: <?= e_($primary) ?>; letter-spacing: 1px; }
  .title-box .meta { font-size: 10pt; color:#4b5563; margin-top: 4px; line-height: 1.5; }
  .title-box .meta strong { color:#111827; }

  .blocks { display:flex; gap: 10mm; margin-top: 10mm; }
  .block  { flex: 1; border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px 10px; }
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

  .signoffs { display:flex; gap: 10mm; margin-top: 18mm; }
  .signoffs .box { flex:1; border-top: 1px solid #1f2937; padding-top: 5px; min-height: 30mm; }
  .signoffs .label { font-size: 9pt; color:#6b7280; }
  .signoffs .name  { font-size: 8pt; color:#9ca3af; margin-top: 2mm; }

  .actions { padding: 10px 15mm; background: #f3f4f6; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; font-size: 11pt; }

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
  <a href="/pages/delivery_orders/view.php?id=<?= e_($id) ?>">&larr; Back to DO</a>
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
        ]);
        echo e_(implode("\n", $bits));
      ?></div>
    </div>
    <div class="title-box">
      <h1>DELIVERY ORDER</h1>
      <div class="meta">
        <div><strong>No:</strong> <span class="mono"><?= e_($do['do_no']) ?></span></div>
        <div><strong>Invoice:</strong> <span class="mono"><?= e_($do['invoice_no']) ?></span></div>
        <div><strong>SO:</strong> <span class="mono"><?= e_($do['so_no']) ?></span></div>
        <div><strong>Status:</strong> <?= e_($do['status']) ?></div>
        <?php if ($do['dispatched_at']): ?><div><strong>Dispatched:</strong> <?= e_($do['dispatched_at']) ?></div><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="blocks">
    <div class="block">
      <div class="label">Deliver to</div>
      <div class="body"><strong><?= e_($do['customer_name']) ?></strong>
        <?= "\n" ?>Code: <span class="mono"><?= e_($do['customer_code']) ?></span><?php
        if ($do['shipping_address'])  echo "\n" . e_($do['shipping_address']);
        if ($do['customer_contact'])  echo "\nAttn: " . e_($do['customer_contact']);
        if ($do['customer_phone'])    echo "\nPhone: " . e_($do['customer_phone']);
?></div>
    </div>
    <div class="block">
      <div class="label">Ship from</div>
      <div class="body"><strong><?= e_($do['warehouse_name']) ?></strong>
<?= "\n" ?>Code: <span class="mono"><?= e_($do['warehouse_code']) ?></span><?php
        if ($do['warehouse_address'])  echo "\n" . e_($do['warehouse_address']);
?></div>
    </div>
    <div class="block">
      <div class="label">Driver / vehicle</div>
      <div class="body"><strong><?= e_($do['driver_name'] ?? '—') ?></strong>
        <?= "\n" ?>Vehicle: <?= e_($do['vehicle'] ?? '—') ?></div>
    </div>
  </div>

  <table class="lines">
    <thead>
      <tr>
        <th style="width: 8mm;">#</th>
        <th>SKU / description</th>
        <th class="ralign" style="width: 26mm;">Qty</th>
        <th class="calign" style="width: 14mm;">UoM</th>
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
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="signoffs">
    <div class="box">
      <div class="label">Driver signature</div>
      <div class="name"><?= e_($do['driver_name'] ?? 'Driver') ?></div>
    </div>
    <div class="box">
      <div class="label">Customer received</div>
      <div class="name">Print name + signature + date</div>
    </div>
  </div>

  <?php if ($do['notes']): ?>
    <div style="margin-top:8mm; font-size: 9pt; color:#374151;">
      <strong>Notes:</strong> <?= e_($do['notes']) ?>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
