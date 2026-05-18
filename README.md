# F&B Revenue & Claim Control BOS

A web-based Business Operating System for Malaysian F&B groups operating
multiple brands and outlets. It centralises sales from delivery platforms,
calculates platform fees / SST / settlement automatically, reconciles bank
receipts, and runs an abnormal-claim risk engine that flags suspicious or
loss-making transactions before money leaves the business.

> **Tech**: PHP 8.2+, MySQL 8 / MariaDB 10.6+, vanilla JS, no build step.

## What it does

- Centralises sales from GrabFood, Foodpanda, ShopeeFood, POS, Website,
  WhatsApp Order, Manual Sales, Catering and any custom platform.
- Computes platform commission, payment-gateway fees, fixed fees, voucher /
  delivery / refund treatments and SST — every rate is configurable, **never
  hard-coded**.
- Compares system-calculated net settlement vs platform-reported settlement
  and labels each order as `matched`, `partially_matched`, `unmatched`,
  `fee_discrepancy`, `tax_discrepancy`, `over_deducted`, `under_deducted` or
  `missing_settlement`.
- Reconciles expected settlements against bank statement CSVs.
- Runs a 0–100 risk score on every claim against ten signals (amount,
  frequency, duplicate receipt, split claim, supplier anomaly, time anomaly,
  budget anomaly, role mismatch, OCR mismatch, profit impact) with a
  human-readable explanation stored alongside the claim.
- Routes each claim to the correct approver based on configurable
  amount-range / claim-type / risk-threshold rules.
- Generates daily sales, platform settlement, claim summary, abnormal
  claim, and outlet profit reports — exportable to CSV (PDF rendering is
  hooked but stubbed).
- Records every state change in an append-only audit log.

## Roles

`Super Admin · Company Admin · Finance Admin · Area Manager · Outlet Manager
· Staff · Auditor`. Permissions live in the `permissions` table; role-permission
joins live in `role_permissions`. RBAC is enforced via
`Rbac::require('claims.review')` style guards on every page.

## Repository layout

```
config/                      App config (config.example.php)
public/                      Web root
├── assets/                  CSS + JS
├── partials/                Shared header / footer
├── pages/                   All admin pages (one per concern)
├── index.php                Dashboard
├── login.php / logout.php
src/                         Application code
├── bootstrap.php            Loads config, autoloader, sessions
├── Auth.php                 Session auth
├── Rbac.php                 Role-based permission check
├── Csrf.php                 Per-session CSRF tokens
├── AuditLog.php             Append-only trail
├── ApprovalRouter.php       Resolves next required approver
├── Engine/                  Pure business logic
│   ├── TaxEngine.php        Configurable SST / tax engine
│   ├── FeeEngine.php        Platform fee + reconciliation
│   ├── Importer.php         CSV ingest with validation
│   ├── Reconciler.php       Bank vs settlement matcher
│   ├── RiskEngine.php       Claim risk scoring (0–100)
│   ├── Reporter.php         Report queries + CSV export
│   └── Notifier.php         In-app + WhatsApp + email
└── Support/helpers.php      Procedural helpers (e, money, db, etc.)
sql/
├── schema.sql               All tables (InnoDB / utf8mb4)
└── seed.sql                 Roles, permissions, claim types,
                             super-admin, default approval matrix
storage/                     Uploads + exports (gitignored contents)
docs/                        INSTALL.md, ARCHITECTURE.md
```

## Quick install

See [docs/INSTALL.md](docs/INSTALL.md).

## Architecture

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Phasing

This MVP delivers Phase 1 + Phase 2 of the roadmap and the structure for
Phase 3 (OCR receipt reading, WhatsApp alerts, AI-style explanations are
already wired through stubs you can plug real services into).

| Phase | Status |
|-------|--------|
| 1. Company / outlet / user / role / platform / sales import / fee calc / settlement | Done |
| 2. Bank reconciliation / claim submission / approval / risk scoring | Done |
| 3. OCR receipt reading / WhatsApp alerts / AI explanation / advanced dashboard | Hooks in place |
| 4. API integration with platforms / forecasting / leakage prediction | Future |
