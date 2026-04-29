# SLV WMS

Warehouse Management System for **SLV Group Sdn. Bhd.** Deployed on Hostinger
shared hosting (plain PHP 8.x + MySQL, no Composer, no Node build step).

> This repository directory is the **Phase 0 foundation drop**. Subsequent
> phases will land alongside it without breaking existing files.

---

## Phase 0 — what's in this drop

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

**Phase 0 does NOT include** any product / inventory / GRN / pick / invoice
flows. Those land in Phase 1+ per `Section 12 — BUILD ORDER` of CLAUDE.md.

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

### After-login smoke checks (Phase 0 acceptance)

- [ ] `/login.php` accepts `admin@slv.local` / `ChangeMe!2026`.
- [ ] `/index.php` shows the dashboard with warehouse selector.
- [ ] Warehouse selector saves to session and reloads with the choice intact.
- [ ] `/m/login.php` works on a real phone; you can switch to standalone mode via "Add to Home Screen".
- [ ] `/logout.php` returns you to login.
- [ ] No PHP warnings in `storage/logs/php-error.log`.
- [ ] `https://your-domain/config/app.php` returns 404 (blocked by .htaccess).
- [ ] `https://your-domain/lib/db.php`     returns 404 (blocked by .htaccess).

If any check fails, do not move on to Phase 1 — file an issue with the symptom and the relevant log line.

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
│   ├── settings/        ⏳ Phase 1
│   ├── warehouses/      ⏳ Phase 1
│   ├── users/           ⏳ Phase 1
│   ├── products/        ⏳ Phase 2
│   ├── bins/            ⏳ Phase 2
│   ├── grn/             ⏳ Phase 4
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

## Next: Phase 1

Phase 1 will add the **Settings backend** (branding upload, document-number editor, tax codes & groups CRUD, SMTP, warehouse master CRUD, user & warehouse-access management). Hold here until Phase 0 deploys cleanly.
