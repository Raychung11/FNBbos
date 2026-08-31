# Architecture

This document captures the why behind the structure. The codebase is small
and intentionally procedural where it doesn't help to be more abstract.

## Layers

```
HTTP            public/*.php                    (one page = one concern)
Application     src/Auth, Rbac, Csrf, AuditLog  (cross-cutting)
Engines         src/Engine/*                    (pure business logic)
Persistence     PDO via db()                    (prepared statements only)
```

Every page entry point follows the same shape:

1. `require __DIR__ . '/../src/bootstrap.php';`
2. `Auth::requireLogin();`
3. `Rbac::require('<permission>');`
4. If `POST`, validate CSRF + run mutation + audit + redirect.
5. Render via shared `partials/header.php` and `partials/footer.php`.

This keeps reasoning about security trivial: if a page lacks the
`Rbac::require()` line, it's a bug — and they all have it.

## Engines

The `src/Engine` namespace holds the rules of the business. They are
**pure** in the sense that they take input data + database lookups and
produce results — they don't print HTML, don't read `$_POST`, don't manage
session state.

### `TaxEngine`

Resolves the most-specific active tax rule for `(outlet, platform, date)`,
honouring effective dates and inclusive-vs-exclusive modes. **Tax rates are
never hard-coded.** Switching from 6% to 8% to 0% is a UI change, not a
deploy.

### `FeeEngine`

Looks up `platform_fee_rules` (also effective-dated), applies the
spec's formula:

```
gross
  - commission (gross * commission_rate)
  - payment fee (gross * payment_fee_rate)
  - fixed service fee
  - voucher cost (per treatment)
  - promotion cost
  - refund (per treatment)
  - delivery subsidy (per treatment)
  - adjustment
  - tax
  = net settlement
```

…then `reconcile()` compares system vs imported and returns a status from
the spec's eight-value enum.

### `Importer`

CSV ingest. Required-column check, per-row validation
(`duplicate_order_id`, `missing_outlet`, `missing_platform`,
`invalid_amount`, `negative_settlement`), persists `sales_orders` + a
matching `sales_fee_calculations` row in a single transaction. Each
rejected row is recorded in `sales_import_errors` so finance can chase
exactly what failed.

### `Reconciler`

Joins `sales_fee_calculations` with `bank_transactions` aggregated by
`(platform, outlet, date)` to label settlements as `received`, `underpaid`,
`overpaid`, `pending`, `delayed` or `unmatched`.

### `RiskEngine`

Ten weighted factors (see `RiskEngine::score()`), each contributing a score
and a reason. Reasons are concatenated into a human explanation stored on
`claims.risk_explanation` and surfaced verbatim in the approval UI.
Factors are also written to `claim_risk_factors` so finance can audit
**why** a claim was flagged — not just the final score.

The thresholds for low/medium/high/critical and the multipliers used by
the amount-anomaly factor live in `config('risk.*')`, so tuning the
sensitivity is config, not code.

### `Notifier`

Persists every alert to `notifications` (always, even if delivery is
disabled), then optionally pushes to the WhatsApp Evolution API and
email. The Evolution API call is a single `POST` to
`/message/sendText/{instance}` with `{number, text}` — wire your
instance + API key in `config/config.php`.

### `Reporter`

One method per report listed in the spec. CSV export writes to
`storage/exports/...` and streams the file back as a download. PDF
rendering goes through `renderPdf()` which today returns a CSV — drop in
dompdf or mpdf and replace the body of that one method.

## Data model

The schema follows the table list in the spec verbatim:

- `companies`, `brands`, `outlets`, `users`, `roles`, `permissions`,
  `role_permissions`
- `platforms`, `platform_fee_rules`
- `tax_profiles`, `tax_rules`
- `sales_import_batches`, `sales_import_errors`, `sales_orders`,
  `sales_fee_calculations`
- `bank_statement_imports`, `bank_transactions`,
  `settlement_reconciliations`
- `claim_types`, `claims`, `claim_attachments`,
  `claim_risk_scores`, `claim_risk_factors`
- `approval_rules`, `claim_approvals`
- `audit_logs`, `notifications`, `report_exports`, `system_settings`

A few specific decisions:

- **Effective-dated rules** for both fees and tax. We never overwrite an
  old rule — saving a new one deactivates the previous. This keeps the
  audit trail of "what rate was active when this order was settled" intact
  forever.
- **`sales_fee_calculations` is 1:1 with `sales_orders`** so we can look
  up the system snapshot in the same query without recomputing.
- **`receipt_hash` lives on `claims`** (in addition to
  `claim_attachments`) so the duplicate-receipt detector is a fast index
  hit, not a join.

## Security

- All forms POST through CSRF: `Csrf::field()` to render, `Csrf::requireValid()` to check.
- Passwords are bcrypt at cost 12, transparently rehashed on login when
  the cost increases.
- Sessions use `httponly`, `samesite=Lax`, and `secure` when HTTPS is
  detected. `session_regenerate_id(true)` runs on login.
- All SQL goes through PDO prepared statements; emulation is off.
- Receipt files are stored under `storage/uploads/receipts/` which is
  outside the public document root — they are never directly URL-addressable.
- Every state mutation goes through `AuditLog::record()`; audit failures
  log to `error_log` but never block the user action (so an audit table
  outage can't lock the company out of paying their staff).

## Extensibility

- Add a new platform: create a row in `platforms`, then a fee rule in
  `platform_fee_rules`. No code change.
- Change SST rate next year: add a new row to `tax_rules` with a new
  `effective_from`. The old rate stays as history.
- Add a risk factor: add a method to `RiskEngine`, call it from `score()`,
  return `['key' => ..., 'score' => N, 'reason' => '...']`. New factor is
  immediately surfaced in the approval UI and stored alongside historical
  ones.
- Replace the PDF stub: implement `Reporter::renderPdf()` with dompdf
  or mpdf; nothing else changes.
- Replace WhatsApp transport: swap the body of `Notifier::sendWhatsApp()`.
