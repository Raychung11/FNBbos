-- =============================================================================
-- Drop every table defined by schema.sql, in any order (FK checks disabled).
-- Use this on shared hosts (eg Hostinger) where DROP DATABASE is blocked.
-- After running this, run schema.sql then seed.sql to rebuild from scratch.
-- =============================================================================
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS report_exports;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS audit_logs;

DROP TABLE IF EXISTS claim_approvals;
DROP TABLE IF EXISTS approval_rules;
DROP TABLE IF EXISTS claim_risk_factors;
DROP TABLE IF EXISTS claim_risk_scores;
DROP TABLE IF EXISTS claim_attachments;
DROP TABLE IF EXISTS claims;
DROP TABLE IF EXISTS claim_types;

DROP TABLE IF EXISTS settlement_reconciliations;
DROP TABLE IF EXISTS bank_transactions;
DROP TABLE IF EXISTS bank_statement_imports;

DROP TABLE IF EXISTS sales_fee_calculations;
DROP TABLE IF EXISTS sales_orders;
DROP TABLE IF EXISTS sales_import_errors;
DROP TABLE IF EXISTS sales_import_batches;

DROP TABLE IF EXISTS tax_rules;
DROP TABLE IF EXISTS platform_fee_rules;
DROP TABLE IF EXISTS platforms;

DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS permissions;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS outlets;
DROP TABLE IF EXISTS tax_profiles;
DROP TABLE IF EXISTS brands;
DROP TABLE IF EXISTS companies;

SET FOREIGN_KEY_CHECKS = 1;
