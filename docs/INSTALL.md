# Install

## Requirements

- PHP 8.2+ (with `pdo_mysql`, `mbstring`, `fileinfo`, `curl` extensions)
- MySQL 8.0+ or MariaDB 10.6+
- A web server with PHP enabled (Apache, Nginx + PHP-FPM, Caddy, or `php -S`
  for local dev)
- Read/write access to `storage/uploads` and `storage/exports`

## 1. Clone

```bash
git clone <this-repo> fnbbos
cd fnbbos
```

## 2. Database

```bash
mysql -uroot -p -e "CREATE DATABASE fnb_bos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -uroot -p fnb_bos < sql/schema.sql
mysql -uroot -p fnb_bos < sql/seed.sql
```

The seed creates a starter Super Admin:

| Email             | Password       |
|-------------------|----------------|
| admin@example.com | ChangeMe123!   |

**Change this password on first login.**

## 3. Config

```bash
cp config/config.example.php config/config.php
```

Edit `config/config.php`:

- `app.base_url` — public URL of the `/public` folder (e.g. `https://bos.example.com` or `http://localhost:8000`).
- `db.*` — host, name, user, pass.
- `notifications.evolution_api` — set `enabled => true` and fill in
  `base_url`, `api_key`, `instance` to send WhatsApp alerts via Evolution API.
- `notifications.email.enabled` — `true` to use PHP's `mail()`.
- `risk.*` — tweak risk-engine thresholds without touching code.

## 4. Permissions on storage

```bash
mkdir -p storage/uploads/receipts storage/exports
chmod -R 0775 storage
```

For Apache/Nginx ensure the web user (`www-data`, `nginx`, etc.) owns this
tree.

## 5. Run

For local development:

```bash
php -S localhost:8000 -t public
```

Then open <http://localhost:8000/login.php> and sign in.

For production behind Apache/Nginx, point the document root at `public/`.
Receipts are stored under `storage/uploads/receipts/YYYY/MM/...` — keep this
**outside** the document root in production (already the default).

## 6. Sales CSV format

Required columns:

```
platform_code, outlet_code, order_id, order_date, gross_sales
```

Optional columns:

```
item_subtotal, service_charge, sst_amount, discount, voucher,
refund, platform_commission, payment_fee, delivery_fee, adjustment,
net_settlement, settlement_date, bank_reference
```

`platform_code` and `outlet_code` must match `platforms.code` and
`outlets.code` already configured in the system (case-insensitive).

## 7. Bank statement CSV format

```
value_date, amount, direction, reference, platform_code, outlet_code
```

`direction` is `credit` or `debit`. Only `credit` lines are matched against
expected settlements.

## 8. Hardening

- Set `app.debug = false` in `config/config.php` for production.
- Force HTTPS at the reverse proxy.
- Restrict the MySQL user to this database only.
- Schedule a backup of `fnb_bos` and `storage/uploads/receipts` together —
  losing one without the other invalidates the audit trail.
