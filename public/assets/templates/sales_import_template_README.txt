SALES IMPORT TEMPLATE — INSTRUCTIONS
====================================

This template is used to upload daily/weekly sales orders from any platform
into the F&B BOS. The system will then run the Fee Engine and Tax Engine on
each row and produce a reconciliation snapshot.

FILE FORMAT
-----------
- UTF-8 CSV. Use Excel "Save As" → "CSV UTF-8" or Google Sheets "Download CSV".
- The first row MUST be the header (column names exactly as below).
- Decimal separator: dot (.). Do NOT use commas or "RM" prefix in amounts.
- Date format: YYYY-MM-DD (e.g. 2025-04-28).
- Empty fields are allowed for optional columns. Leave them blank, not 0.

REQUIRED COLUMNS (must be present, must have a value in every row)
------------------------------------------------------------------
platform_code   Code of the platform (must match an existing platform set up
                in the system). Default seeded codes: grabfood, foodpanda,
                shopeefood, pos, website, whatsapp, manual, catering.
outlet_code     Code of the outlet (must match outlets.code in the system,
                e.g. KL-01).
order_id        Unique order ID from the platform. Used to detect duplicates.
order_date      Date the order was placed (YYYY-MM-DD).
gross_sales     Total amount the customer paid (item subtotal + service
                charge + tax, before any platform deduction).

OPTIONAL COLUMNS (leave blank if not applicable)
------------------------------------------------
item_subtotal         Items only, before tax and service charge.
service_charge        If charged.
sst_amount            SST/tax amount as reported by the platform. The system
                      also recalculates this with the Tax Engine; mismatches
                      flag a "tax_discrepancy" status.
discount              Promotional discount applied (merchant cost).
voucher               Voucher value redeemed.
refund                Refund processed.
platform_commission   Commission as reported by the platform.
payment_fee           Payment gateway fee (cards, e-wallets, etc.).
delivery_fee          Delivery fee (only fill in if the merchant bears it;
                      otherwise leave blank).
adjustment            Manual adjustments (positive or negative).
net_settlement        Net amount the platform reports it will settle. The
                      system compares this with its own calculation and
                      flags discrepancies (over_deducted, under_deducted,
                      fee_discrepancy, tax_discrepancy).
settlement_date       Date the platform paid out (YYYY-MM-DD).
bank_reference        Reference number on the bank credit, used for bank
                      reconciliation later.

VALIDATION RULES
----------------
- Duplicate order_id (same company)         -> rejected
- Unknown platform_code or outlet_code      -> rejected
- Negative gross_sales or net_settlement    -> rejected
- Empty order_id                            -> rejected

TIPS FOR PLATFORM EXPORTS
-------------------------
- GrabFood / Foodpanda: download the weekly settlement statement as CSV,
  then re-map columns into this template before uploading.
- ShopeeFood: use the merchant order export for the date range.
- POS: most POS systems export daily Z-reports; map order_id from the
  receipt number, gross_sales from total, sst_amount from tax line.
- Website / WhatsApp Order: export from your storefront or order log;
  payment_fee usually comes from the payment gateway statement.

A sample CSV with realistic values is in the same folder as this file
(sales_import_template.csv). Open it in Excel or Google Sheets, replace
the example rows with your actual data, save as CSV (UTF-8) and upload
through Sales > Sales Import.
