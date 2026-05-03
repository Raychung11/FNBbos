# SLV WMS

Warehouse Management System for **SLV Group Sdn. Bhd.** Deployed on Hostinger
shared hosting (plain PHP 8.x + MySQL, no Composer, no Node build step).

> Currently shipped: **Phase 0 (foundation)** + **Phase 1 (settings backend)** + **Phase 2 (master data)** + **Phase 3 (FIFO engine + reports)** + **Phase 4 (GRN desktop)** + **Phase 5 (mobile receive + putaway scan)** + **Phase 6 (Sales orders + pick lists)** + **Phase 7 (mobile picking scan)**.
> Subsequent phases land alongside without breaking existing files.

---

## Phase 0 — foundation

| Layer            | What ships                                                                 |
|------------------|----------------------------------------------------------------------------|
| Database         | `migrations/001_init.sql`, `migrations/002_seed.sql`                       |
| Config           | `config/app.example.php`, `config/db.php`                                  |
| Library          | `lib/db.php`, `lib/auth.php`, `lib/csrf.php`, `lib/helpers.php`, `lib/docnum.php`, `lib/bootstrap.php` |
| Layout partials  | `partials/header.php`, `partials/footer.php`                               |
| Desktop entry    | `index.php`, `login.php`, `logout.php`                                     |
| Dashboard        | `pages/dashboard.php` (skeleton with warehouse filter)                     |
| Mobile (PWA)     | `m/login.php`, `m/home.php`, `manifest.json`, `service-worker.js`          |
| Hardening        | `.htaccess`, sensitive folders denied                                      |

## Phase 1 — settings backend

| Layer                  | What ships                                                                                |
|------------------------|-------------------------------------------------------------------------------------------|
| Settings landing       | `pages/settings/index.php` + `partials/settings_nav.php`                                  |
| Branding               | `pages/settings/branding.php` — company info, theme colours, logo upload                   |
| Document numbering     | `pages/settings/numbering.php` — edit `format_template` + reset_yearly with live preview   |
| Tax codes              | `pages/settings/tax_codes.php` — CRUD                                                      |
| Tax groups             | `pages/settings/tax_groups.php` — CRUD + tax-code membership                               |
| Email (SMTP)           | `pages/settings/smtp.php` — host/port/secure/auth + notification toggles                   |
| Warehouses             | `pages/warehouses/index.php`, `create.php`, `edit.php`                                     |
| Users & access         | `pages/users/index.php`, `create.php`, `edit.php` (role + per-warehouse grants)            |
| Helpers                | `setting_set()`, `setting_cache_clear()`, `company_record()` added to `lib/helpers.php`    |

## Phase 2 — master data

| Layer                  | What ships                                                                                |
|------------------------|-------------------------------------------------------------------------------------------|
| Schema                 | `migrations/003_master_data.sql` — categories, products, product_barcodes, product_warehouse_settings, zones, racks, bins, suppliers, customers, import_jobs |
| Categories             | `pages/categories/index.php` (combined list + inline create/edit, parent dropdown)        |
| Products + barcodes    | `pages/products/{index,create,edit,_form,_form_data,_save}.php` — multi-barcode rows with one primary, single shared form |
| Zones / racks / bins   | `pages/locations/index.php` — warehouse picker, hierarchical tree with inline CRUD at every level |
| Suppliers              | `pages/suppliers/{index,create,edit,_form,_save}.php`                                     |
| Customers              | `pages/customers/{index,create,edit,_form,_save}.php` (default tax group + credit limit)  |
| CSV imports            | `pages/imports/{index,upload,run,errors}.php` + `lib/csv.php` chunked runner              |
| Importer handlers      | `lib/import/products.php`, `lib/import/bins.php` (auto-creates parent zones/racks)        |
| Header nav             | `partials/header.php` — Master dropdown for products/categories/locations/suppliers/customers/imports |

## Phase 3 — FIFO engine + opening stock + reports

| Layer                  | What ships                                                                                |
|------------------------|-------------------------------------------------------------------------------------------|
| Schema                 | `migrations/005_stock_engine.sql` — `stock_movements`, `stock_layers`, `stock_layer_movements`, `inventory` |
| FIFO engine            | `lib/stock.php` — `record_putaway()` and `record_issue()`. The ONLY place in the codebase that may write to those four tables. Idempotent via `scan_uuid` UNIQUE; FOR-UPDATE-locked FIFO consume; layer cost-basis preserved across transfers. |
| Opening-stock import   | `lib/import/opening_stock.php` (registered in `csv_import_types()`). Each row creates one `stock_layer` via `record_putaway(source=OPENING)`. Auto-resolves bins by `full_code` or short code. |
| Reports landing        | `pages/reports/index.php`                                                                  |
| Stock on hand          | `pages/reports/stock_on_hand.php` — per-(SKU, warehouse) FIFO valuation with per-layer drilldown. |
| Stock movements ledger | `pages/reports/stock_movements.php` — filterable by warehouse / SKU / type / date range.   |
| Header nav             | `partials/header.php` — adds **Reports** to the top bar for super_admin / warehouse_manager / sales / viewer. |

## Phase 4 — GRN desktop flow

| Layer            | What ships                                                                                |
|------------------|-------------------------------------------------------------------------------------------|
| Schema           | `migrations/006_grn.sql` — `grn`, `grn_items`, `grn_putaway`                              |
| Engine           | `lib/grn.php` — `grn_putaway_suggestions()` (consolidate-first, then default zone, then any empty bin), `grn_suggest_bin()`, `grn_recompute_status()`, `grn_putaway_execute()` (wraps `record_putaway` with `source=GRN`) |
| List             | `pages/grn/index.php` — status / warehouse / supplier / search filters                    |
| Create           | `pages/grn/create.php` — Alpine line-item repeater with running total                     |
| View / receive / putaway | `pages/grn/view.php` — single page that adapts by status: edit lines (DRAFT) → receive qty + actual unit cost (RECEIVING/RECEIVED) → putaway with bin suggestion + override (RECEIVED/PUTAWAY) → CLOSED. Per-line putaway history is collapsible. |
| Header           | `partials/header.php` — adds `GRN` to super_admin / warehouse_manager / receiver navs.    |
| Dashboard        | `pages/dashboard.php` — "Pending GRN" tile now wired to a real count; super_admin and warehouse_manager get a `+ New GRN` quick action. |

Status machine: `DRAFT → RECEIVING → RECEIVED → PUTAWAY → CLOSED`, with
`CANCELLED` as an out at any pre-putaway stage. Status is recomputed from
`grn_items.qty_received` / `qty_putaway` after every action so it can
never drift.

## Phase 5 — mobile receive + putaway scan flows

| Layer            | What ships                                                                                |
|------------------|-------------------------------------------------------------------------------------------|
| Scanner shell    | `assets/js/scanner.js` — `SLVScanner.start/stop/beep/api`. Loads `html5-qrcode` from CDN on demand, debounces duplicate reads (1.5s window), beeps + vibrates, and provides a CSRF-aware `fetch` wrapper. |
| JSON endpoints   | `api/v1/scan/_common.php` (auth + JSON-body parsing), `grn_list.php`, `grn_get.php`, `sku_lookup.php`, `bin_lookup.php`, `grn_receive.php`, `grn_putaway.php`. All gated by role + per-warehouse access; CSRF via `X-CSRF-Token` header. |
| Mobile receive   | `m/receive.php` — pick GRN → scan SKU → enter qty + actual unit cost → submit. Status auto-flips DRAFT → RECEIVING → RECEIVED. |
| Mobile putaway   | `m/putaway.php` — pick GRN → scan SKU (resolves to its line) → suggested-bin chip with "use it" shortcut → scan or type bin → confirm qty + cost → submit. Idempotent via client-generated UUID-v4 `scan_uuid` (one button-tap, one stock layer). Status auto-flips RECEIVED → PUTAWAY → CLOSED. |
| Home tiles       | `m/home.php` — receive + putaway tiles now read "● ready to scan" instead of "ships in Phase 5"; tiles for unimplemented flows stay dashed and dimmed. |
| PWA cache        | `service-worker.js` bumped to `slvwms-v0.5.0`; pre-caches the new pages + `scanner.js`. |

## Phase 6 — Sales orders + pick lists desktop

| Layer            | What ships                                                                                |
|------------------|-------------------------------------------------------------------------------------------|
| Schema           | `migrations/007_sales_orders.sql` — `sales_orders`, `so_items`, `pick_lists`, `pick_items`. `migrations/008_pick_sequence.sql` adds the `PICK` doc-number sequence. |
| Tax engine       | `lib/tax.php` — `tax_compute_line()` with compound stacking (a `is_compound=1` code applies to subtotal + earlier taxes), `tax_aggregate()` produces grand totals + per-tax-code breakdown that Phase 8's `invoice_taxes` will store verbatim. |
| SO engine        | `lib/so.php` — `so_recompute_totals()` (line + header), `so_stock_availability()` (live FIFO availability per line for the warning), `so_generate_pick_list()` (FIFO across bins, one `pick_item` per (line, bin), walk-path sort `zone → rack → bin`, short-stock fallback rows with no suggested bin). |
| List + create    | `pages/sales_orders/{index,create}.php` — Alpine line repeater that auto-fills the line's tax group from the customer's default (falling back to the SKU's). Stored amounts re-computed on every save. |
| View / confirm   | `pages/sales_orders/view.php` — status-driven flow: edit lines (DRAFT) → Confirm (DRAFT → CONFIRMED) → Generate pick list (CONFIRMED → PICKING). Live tax-code breakdown card and per-line stock availability with shortage badges. |
| Pick lists       | `pages/pick_lists/{index,view}.php` — list with progress bars, walk-path view with bin / SKU / barcode / qty / picker columns. Read-only on desktop; the actual stock issue happens in Phase 7 mobile picking. |
| Header nav       | `partials/header.php` — adds `In ▾` (GRNs) + `Out ▾` (Sales orders, Pick lists) for super_admin; `Sales ▾` for warehouse_manager; flat `Sales orders` link for sales role. |
| Dashboard        | `pages/dashboard.php` — Open SOs + Pending pick lists tiles now wired to real counts; super_admin / manager / sales get a `+ New SO` quick action. |

## Phase 7 — mobile picking scan flow

| Layer            | What ships                                                                                |
|------------------|-------------------------------------------------------------------------------------------|
| Engine           | `lib/so.php` adds `pick_execute()` (wraps `record_issue(movement_type=PICK)` inside a `db_tx`; idempotent on `scan_uuid`; updates `pick_items` + `so_items.qty_picked`), `pick_list_recompute_status()` (DRAFT → IN_PROGRESS → COMPLETED), `pick_list_maybe_close_so()` (flips parent SO PICKING → PICKED when every line is fully picked). |
| JSON endpoints   | `api/v1/scan/pick_list_list.php` (GET — pickable lists for the user), `pick_list_get.php` (GET — items + walk path + suggested-bin info), `pick_execute.php` (POST — one pick, idempotent via UUID-v4). All gated by role + per-warehouse access. |
| Mobile pick      | `m/pick.php` — pick a list → strict three-step UI per line: scan bin → scan SKU (rejects wrong product) → confirm qty. Suggested bin is highlighted; scanning a different bin is allowed (override is logged). After every pick the page reloads from the server so progress + status stay authoritative. |
| Home tile        | `m/home.php` — Pick tile now reads "● ready to scan". |
| PWA cache        | `service-worker.js` bumped to `slvwms-v0.7.0`; pre-caches `/m/pick.php`. |

**Still pending** (Phase 8+): Invoicing + Delivery Order A4 PDFs /
mobile dispatch + POD / transfers / adjustments / counts / dashboard
charts / cron.

---

## Tables created

```
companies              warehouses           users          user_warehouse_access
app_settings           document_sequences   audit_logs
tax_codes              tax_groups           tax_group_codes
```

Default seed:
- Company `1` — SLV Group Sdn. Bhd.
- Warehouse `WH01` — Main Warehouse
- User `admin@slv.local` / **`ChangeMe!2026`** — role `super_admin`. **Change immediately.**
- Branding defaults (purple/grey/amber).
- Doc-number formats for INV/DO/SO/GRN/PO/TRF/ADJ/CNT (yearly reset where appropriate).
- SST 6%, Service Tax 6%, Zero-Rated tax codes + tax groups.

---

## Deploying to Hostinger (hPanel File Manager)

1. **Create a MySQL database** in hPanel (any name, e.g. `u123_slvwms`).
   Note the host, db name, user, password.
2. **Upload** the entire `slv-wms/` directory contents to your `public_html/`.
   Do NOT upload `slv-wms/` as a sub-folder — the contents of `slv-wms/` should
   sit at the document root (so `index.php` is `public_html/index.php`).
3. **Copy** `config/app.example.php` → `config/app.php` and edit:
   - `db.host`, `db.name`, `db.user`, `db.pass`
   - `app.base_url` to your real URL
   - `app.production` to `true`
4. **Run the migrations** in phpMyAdmin (in order):
   - Import `migrations/001_init.sql`
   - Import `migrations/002_seed.sql`
5. **Open** `https://your-domain/login.php`
   - Email: `admin@slv.local`
   - Password: `ChangeMe!2026`
6. You should land on the dashboard skeleton with the warehouse filter
   showing `WH01 — Main Warehouse`.
7. **Phone test**: open `https://your-domain/m/login.php` on Android Chrome
   or iOS Safari. After login you should see the mobile task tiles (most
   marked "ships in Phase X").

### After-login smoke checks

**Phase 0 acceptance:**
- [ ] `/login.php` accepts `admin@slv.local` / `ChangeMe!2026`.
- [ ] `/index.php` shows the dashboard with warehouse selector.
- [ ] Warehouse selector saves to session and reloads with the choice intact.
- [ ] `/m/login.php` works on a real phone; you can switch to standalone mode via "Add to Home Screen".
- [ ] `/logout.php` returns you to login.
- [ ] No PHP warnings in `storage/logs/php-error.log`.
- [ ] `https://your-domain/config/app.php` returns 404 (blocked by .htaccess).
- [ ] `https://your-domain/lib/db.php`     returns 404 (blocked by .htaccess).

**Phase 1 acceptance:**
- [ ] `/pages/settings/index.php` shows seven cards (only super_admin sees this).
- [ ] **Branding:** edit company name + colours + upload a PNG logo. Save reloads, header shows the new colour and logo.
- [ ] **Numbering:** edit `INV` template to `INV/{warehouse_code}/{YY}/{seq:5}` and the next-preview column updates immediately.
- [ ] **Tax codes:** create a `TST` code at 5%, edit it, then delete it (cannot delete if it's used by a group).
- [ ] **Tax groups:** create a group `STD2` with `SST6` selected; the codes column shows `SST6` in the list.
- [ ] **SMTP:** save host `smtp.hostinger.com`, port `587`, encryption `tls`, sender details. Reload the page → password is masked, host/port persist.
- [ ] **Warehouses:** create `WH02 — Warehouse Two`. Toggle status. Edit address. Code uniqueness enforced.
- [ ] **Users:** create a user with role `picker` and grant access only to `WH02`. Login as that user → only `WH02` shows in the dashboard selector.
- [ ] Self-edit guard: as `admin@slv.local`, the role dropdown is locked to `super_admin` and status is forced `ACTIVE`.

**Phase 2 acceptance:**
- [ ] Run `migrations/003_master_data.sql` cleanly on the existing DB.
- [ ] Header `Master ▾` dropdown shows Products / Categories / Zones-Racks-Bins / Suppliers / Customers / CSV imports.
- [ ] **Categories:** create `Frozen Food`, then a child category `Ice cream` (parent dropdown). Try to delete one with products attached — blocked.
- [ ] **Products:** create a SKU `SKU-001` with one primary barcode `1234567890123` (EAN). Add a second internal barcode and confirm only one stays marked primary. Edit the SKU; the primary radio is preselected on the original.
- [ ] **Locations:** pick `WH01`. Add zone `Z-A` (DRY), then rack `R01`, then bin `B03` with capacity 100 and pickable on. The `full_code` field shows `WH01/Z-A/R01/B03`. Edit a bin's barcode and verify uniqueness is enforced.
- [ ] **Suppliers** + **Customers:** create one of each, edit, deactivate, search by code/name.
- [ ] **CSV imports — products:** upload a 5-row CSV with columns `sku_code,name,category_name,uom,pack_size,selling_price,primary_barcode`. Run all chunks; success_rows = 5, errors = 0. The category is auto-created if missing.
- [ ] **CSV imports — bins:** upload a 5-row CSV with `warehouse_code,zone_code,rack_code,bin_code`. Zones and racks auto-create. Re-running the same CSV is a no-op (UPDATE on existing bins).
- [ ] **Errors CSV:** upload a products CSV with one row that uses a `sku_code` containing spaces. Job ends `COMPLETED` with `error_rows = 1`. The `Errors CSV` link downloads a CSV that includes a trailing `_error` column with the validation message.

**Phase 3 acceptance:**
- [ ] Run `migrations/005_stock_engine.sql` cleanly on the existing DB.
- [ ] **Opening-stock import:** upload a CSV with `warehouse_code,sku_code,bin_code,qty,unit_cost` for at least 3 SKUs split across 2 warehouses. Each row creates exactly one `stock_layers` row, one `stock_movements` row (type `OPENING`), and one `inventory` row.
- [ ] **Stock on hand:** Reports → Stock on hand shows one row per (SKU, warehouse). Total qty + total value = sum of imported rows. Click "Drilldown" — the layer table lists every layer with its `received_at`, bin, unit cost, qty remaining, and per-layer value.
- [ ] **Stock movements:** Reports → Stock movements lists every imported row as `OPENING` with positive Δqty. Filter by warehouse or SKU narrows the list.
- [ ] **Idempotency check (manual):** call `record_putaway(...)` from a script with the same `scan_uuid` twice — only one layer + one movement gets created. Verify in `stock_layers` and `stock_movements` tables.
- [ ] **Insufficient-stock check:** call `record_issue()` from a script for more qty than is in a bin — it throws `Insufficient stock` and rolls back; no `stock_movements` or `stock_layer_movements` row is created.
- [ ] **FIFO order:** add two `OPENING` layers for the same SKU+bin with different `received_at` dates and `unit_cost`. Issue half of the older layer's qty — the older layer's `qty_remaining` decrements first; the newer layer is untouched until the older is depleted.

**Phase 4 acceptance:**
- [ ] Run `migrations/006_grn.sql` cleanly on the existing DB.
- [ ] **Create:** as `manager@slv.local`, GRN → New GRN. Pick warehouse `WH01`, supplier Coca-Cola Bottlers, add 2 lines (e.g. SKU-COKE-330 × 240 @ 1.80, SKU-COKE-1500 × 60 @ 4.20). Save → lands on the view page in `DRAFT`.
- [ ] **Edit lines:** while in `DRAFT`, "Add a line" form works; "remove" link removes a line. The doc number (`GRN/26/00001`) is allocated at save and stays stable across reloads.
- [ ] **Confirm:** click "Confirm & start receiving →". Status flips to `RECEIVING`. Edit/Remove links disappear; receive form appears per line.
- [ ] **Receive:** type a different received qty than expected (e.g. 230 of 240) and a tweaked unit cost. Click save → status stays `RECEIVING` until *all* lines have qty_received >= qty_expected. Set the second line to its full expected qty → status auto-flips to `RECEIVED`.
- [ ] **Putaway suggestion:** with `RECEIVED`, each line shows a suggested bin and a reason. The first putaway of a SKU goes to an empty pickable bin in the SKU's default zone (or any empty pickable bin if no default). The *next* putaway of the same SKU into the same warehouse suggests the bin that already holds it ("consolidate (existing bin)").
- [ ] **Putaway execute:** keep the suggested bin, click "putaway" with the full remaining qty. Status flips to `PUTAWAY`. The action creates: one `grn_putaway` row, one `stock_movements` row (type `PUTAWAY`), one `stock_layers` row (`source_type=GRN, source_ref_id=<grn id>`), and increments `inventory`.
- [ ] **Close:** putaway every line in full → status auto-flips to `CLOSED`.
- [ ] **Cancel guard:** create another GRN, take it through partial receive + putaway. The "Cancel GRN" button refuses while putaway exists; it allows cancel during DRAFT/RECEIVING/RECEIVED.
- [ ] **Reports tie-up:** Reports → Stock on hand shows the new layers; Reports → Stock movements lists the corresponding `PUTAWAY` rows with the GRN's id in the Ref column.

---

## Repository layout (target — Phase 0 subset present)

```
/   (deploys to public_html/)
├── index.php            ✅ Phase 0
├── login.php            ✅ Phase 0
├── logout.php           ✅ Phase 0
├── manifest.json        ✅ Phase 0
├── service-worker.js    ✅ Phase 0
├── .htaccess            ✅ Phase 0
├── config/
│   ├── app.example.php  ✅
│   ├── app.php          ← create from example, gitignored
│   └── db.php           ✅ bootstrap loader
├── lib/
│   ├── bootstrap.php    ✅
│   ├── db.php           ✅ PDO singleton + db_tx()
│   ├── auth.php         ✅ login / require_login / require_role / require_warehouse_access
│   ├── csrf.php         ✅
│   ├── helpers.php      ✅ e_, money, qty, flash, setting, audit_log, uuid_v4
│   ├── docnum.php       ✅ next_doc_no() — atomic
│   └── stock.php        ⏳ Phase 3 (FIFO engine)
│   └── tax.php          ⏳ Phase 6
│   └── pdf.php          ⏳ Phase 8
│   └── csv.php          ⏳ Phase 2
├── migrations/
│   ├── 001_init.sql     ✅
│   ├── 002_seed.sql     ✅
│   └── 003+_*.sql       ⏳ subsequent phases
├── partials/
│   ├── header.php       ✅
│   └── footer.php       ✅
├── pages/
│   ├── dashboard.php    ✅ Phase 0 skeleton
│   ├── settings/        ✅ Phase 1 (branding/numbering/tax/smtp)
│   ├── warehouses/      ✅ Phase 1
│   ├── users/           ✅ Phase 1
│   ├── categories/      ✅ Phase 2
│   ├── products/        ✅ Phase 2
│   ├── locations/       ✅ Phase 2 (zones/racks/bins)
│   ├── suppliers/       ✅ Phase 2
│   ├── customers/       ✅ Phase 2
│   ├── imports/         ✅ Phase 2
│   ├── reports/         ✅ Phase 3 (stock on hand + movements)
│   ├── grn/             ✅ Phase 4 (list, create, view + receive + putaway)
│   └── …                ⏳
├── m/
│   ├── login.php        ✅ Phase 0
│   ├── home.php         ✅ Phase 0
│   └── …                ⏳ Phase 5+
├── api/v1/              ⏳ Phase 5+
├── assets/
│   ├── css/             (brand.css generated in Phase 1)
│   ├── js/              (scanner.js arrives in Phase 5)
│   └── img/             (icon-192.png, icon-512.png, favicon.svg — drop your assets here)
├── vendor_local/
│   ├── mpdf/            ⏳ Phase 8
│   └── phpmailer/       ⏳ Phase 1 (SMTP test)
├── storage/
│   ├── logs/
│   ├── invoices/
│   └── pods/
└── uploads/
```

---

## Locked decisions (from CLAUDE.md §14)

1. Multi-warehouse from day one.
2. Costing method: **FIFO** via `stock_layers` ledger.
3. Multi-tax via Tax Codes + Tax Groups; per-invoice breakdown stored.
4. Document numbering: configurable; defaults shipped in `002_seed.sql`.
5. PDF: A4 only.
6. Branding: configurable; defaults shipped in `002_seed.sql`.

---

## Phase 0 checklist — definition of done

- [x] SQL migrations run cleanly on a fresh MySQL 5.7+/MariaDB 10.x database.
- [x] All forms include CSRF (`csrf_field()`) and verify on POST.
- [x] PDO with prepared statements; no string concatenation.
- [x] Session cookies are HttpOnly + SameSite=Lax (Secure when configured).
- [x] Sensitive folders denied via `.htaccess` (config/lib/migrations/partials/storage).
- [x] Output escaped via `e_()`.
- [x] Branding & company name read from `app_settings` with fallbacks per §5.2(a).
- [x] Document number formats stored in `document_sequences` per §5.2(b).
- [x] Roles + per-warehouse access enforced (`require_login`, `require_role`, `require_warehouse_access`).
- [x] PWA manifest + service worker registered; `m/login.php` and `m/home.php` work.
- [x] Default super_admin seeded; password rotation documented.

---

## Troubleshooting

### "CSRF token mismatch" / "Session security check failed"

The browser isn't returning the session cookie, so each request gets a fresh
`_csrf` and the form's token never matches. Check, in order:

1. **Are you on plain HTTP?** Run the site over HTTPS, or temporarily set
   `cookie_secure => false` in `config/app.php`. As of `lib/auth.php`
   `session_boot()` the Secure flag auto-disables on plain-HTTP requests,
   so this should self-heal — but if you're behind a reverse proxy that
   terminates TLS, make sure it sends `X-Forwarded-Proto: https` (Hostinger
   does this by default).
2. **Are you in private/incognito mode with strict cookie blocking?** Allow
   cookies for the WMS host.
3. **Did you have the form open in two tabs?** Submit the most recently
   loaded one.
4. **Did your session expire?** Reload, log in again.

The error page at `lib/csrf.php` lists these hints inline whenever the
check fails.

---

## Next: Phase 8

Phase 8 wires **Invoicing + Delivery Order A4 PDF generation**: once a
pick list completes, a manager can generate an invoice from the
`qty_picked` (not `qty_ordered`, in case of short picks), with the tax
breakdown persisted into `invoice_taxes` for SST filing. mPDF gets
dropped into `vendor_local/`; the PDF generator reads the company logo
+ colours from app_settings. The DO follows with status flow READY →
IN_TRANSIT → DELIVERED → RETURNED. Hold here until Phase 7 deploys
cleanly.
