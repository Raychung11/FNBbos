-- =============================================================================
-- F&B Revenue & Claim Control BOS — Database schema
-- Engine: InnoDB, Charset: utf8mb4
-- =============================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- Identity, scope & access control
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS companies (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name            VARCHAR(160) NOT NULL,
  registration_no VARCHAR(60)  NULL,
  sst_number      VARCHAR(60)  NULL,
  created_at      DATETIME NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS brands (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  INT UNSIGNED NOT NULL,
  name        VARCHAR(160) NOT NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_brands_company (company_id),
  CONSTRAINT fk_brands_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- tax_profiles must be created before outlets because outlets has a FK to it.
CREATE TABLE IF NOT EXISTS tax_profiles (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   INT UNSIGNED NOT NULL,
  name         VARCHAR(120) NOT NULL,
  category     VARCHAR(40)  NOT NULL DEFAULT 'standard',
  is_inclusive TINYINT(1)   NOT NULL DEFAULT 0,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_tp_company (company_id),
  CONSTRAINT fk_tp_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS outlets (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id              INT UNSIGNED NOT NULL,
  brand_id                INT UNSIGNED NULL,
  code                    VARCHAR(40)  NOT NULL,
  name                    VARCHAR(160) NOT NULL,
  address                 VARCHAR(255) NULL,
  pic_name                VARCHAR(120) NULL,
  phone                   VARCHAR(40)  NULL,
  sst_registered          TINYINT(1)   NOT NULL DEFAULT 0,
  tax_profile_id          INT UNSIGNED NULL,
  operating_cost_target   DECIMAL(14,2) NOT NULL DEFAULT 0,
  monthly_claim_budget    DECIMAL(14,2) NOT NULL DEFAULT 0,
  bank_account            VARCHAR(80)  NULL,
  is_active               TINYINT(1)   NOT NULL DEFAULT 1,
  created_at              DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_outlets_company_code (company_id, code),
  KEY ix_outlets_brand (brand_id),
  KEY ix_outlets_tax_profile (tax_profile_id),
  CONSTRAINT fk_outlets_company     FOREIGN KEY (company_id)     REFERENCES companies(id),
  CONSTRAINT fk_outlets_brand       FOREIGN KEY (brand_id)       REFERENCES brands(id),
  CONSTRAINT fk_outlets_tax_profile FOREIGN KEY (tax_profile_id) REFERENCES tax_profiles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS roles (
  id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug  VARCHAR(40) NOT NULL,
  name  VARCHAR(80) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS permissions (
  id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug  VARCHAR(80) NOT NULL,
  name  VARCHAR(160) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id       INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id)       REFERENCES roles(id),
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id        INT UNSIGNED NOT NULL,
  role_id           INT UNSIGNED NOT NULL,
  default_outlet_id INT UNSIGNED NULL,
  name              VARCHAR(120) NOT NULL,
  email             VARCHAR(160) NOT NULL,
  password_hash     VARCHAR(255) NOT NULL,
  phone             VARCHAR(40)  NULL,
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY ix_users_company (company_id),
  CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_users_role    FOREIGN KEY (role_id)    REFERENCES roles(id),
  CONSTRAINT fk_users_outlet  FOREIGN KEY (default_outlet_id) REFERENCES outlets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Platforms & fee rules (no hard-coded percentages)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS platforms (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id             INT UNSIGNED NOT NULL,
  code                   VARCHAR(40)  NOT NULL,
  name                   VARCHAR(120) NOT NULL,
  merchant_id            VARCHAR(120) NULL,
  bank_account           VARCHAR(80)  NULL,
  settlement_cycle_days  INT UNSIGNED NOT NULL DEFAULT 7,
  is_active              TINYINT(1)   NOT NULL DEFAULT 1,
  created_at             DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_platforms_company_code (company_id, code),
  CONSTRAINT fk_platforms_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platform_fee_rules (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  platform_id         INT UNSIGNED NOT NULL,
  commission_rate     DECIMAL(8,6)  NOT NULL DEFAULT 0,  -- stored as fraction, eg 0.25
  payment_fee_rate    DECIMAL(8,6)  NOT NULL DEFAULT 0,
  fixed_fee           DECIMAL(12,4) NOT NULL DEFAULT 0,
  voucher_treatment   ENUM('merchant_bears','platform_bears') NOT NULL DEFAULT 'merchant_bears',
  delivery_treatment  ENUM('merchant_bears','platform_bears') NOT NULL DEFAULT 'platform_bears',
  refund_treatment    ENUM('merchant_bears','platform_bears') NOT NULL DEFAULT 'merchant_bears',
  effective_from      DATE NOT NULL,
  effective_to        DATE NULL,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  created_at          DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_pfr_platform_active (platform_id, is_active, effective_from),
  CONSTRAINT fk_pfr_platform FOREIGN KEY (platform_id) REFERENCES platforms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Tax engine — tax_profiles is defined earlier (before outlets).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tax_rules (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tax_profile_id  INT UNSIGNED NOT NULL,
  rate            DECIMAL(6,4) NOT NULL DEFAULT 0,
  effective_from  DATE NOT NULL,
  effective_to    DATE NULL,
  outlet_id       INT UNSIGNED NULL,
  platform_id     INT UNSIGNED NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_tr_lookup (tax_profile_id, is_active, effective_from),
  KEY ix_tr_outlet (outlet_id),
  KEY ix_tr_platform (platform_id),
  CONSTRAINT fk_tr_profile  FOREIGN KEY (tax_profile_id) REFERENCES tax_profiles(id),
  CONSTRAINT fk_tr_outlet   FOREIGN KEY (outlet_id)      REFERENCES outlets(id),
  CONSTRAINT fk_tr_platform FOREIGN KEY (platform_id)    REFERENCES platforms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Sales import & fee calculation snapshots
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sales_import_batches (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id     INT UNSIGNED NOT NULL,
  uploaded_by    INT UNSIGNED NULL,
  file_name      VARCHAR(255) NOT NULL,
  total_rows     INT UNSIGNED NOT NULL DEFAULT 0,
  accepted_rows  INT UNSIGNED NOT NULL DEFAULT 0,
  rejected_rows  INT UNSIGNED NOT NULL DEFAULT 0,
  status         VARCHAR(40) NOT NULL DEFAULT 'processing',
  created_at     DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_sib_company (company_id),
  CONSTRAINT fk_sib_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_sib_user    FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_import_errors (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id      INT UNSIGNED NOT NULL,
  row_number    INT UNSIGNED NOT NULL,
  order_id      VARCHAR(80) NULL,
  error_code    VARCHAR(60) NOT NULL,
  message       VARCHAR(255) NOT NULL,
  created_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_sie_batch (batch_id),
  CONSTRAINT fk_sie_batch FOREIGN KEY (batch_id) REFERENCES sales_import_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_orders (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id               INT UNSIGNED NOT NULL,
  batch_id                 INT UNSIGNED NULL,
  platform_id              INT UNSIGNED NOT NULL,
  outlet_id                INT UNSIGNED NOT NULL,
  order_id                 VARCHAR(80) NOT NULL,
  order_date               DATE NOT NULL,
  gross_sales              DECIMAL(14,4) NOT NULL DEFAULT 0,
  item_subtotal            DECIMAL(14,4) NOT NULL DEFAULT 0,
  service_charge           DECIMAL(14,4) NOT NULL DEFAULT 0,
  sst_amount               DECIMAL(14,4) NOT NULL DEFAULT 0,
  discount                 DECIMAL(14,4) NOT NULL DEFAULT 0,
  voucher                  DECIMAL(14,4) NOT NULL DEFAULT 0,
  refund                   DECIMAL(14,4) NOT NULL DEFAULT 0,
  platform_commission      DECIMAL(14,4) NOT NULL DEFAULT 0,
  payment_fee              DECIMAL(14,4) NOT NULL DEFAULT 0,
  delivery_fee             DECIMAL(14,4) NOT NULL DEFAULT 0,
  adjustment               DECIMAL(14,4) NOT NULL DEFAULT 0,
  net_settlement_imported  DECIMAL(14,4) NOT NULL DEFAULT 0,
  settlement_date          DATE NULL,
  bank_reference           VARCHAR(120) NULL,
  created_at               DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_so_company_order (company_id, order_id),
  KEY ix_so_company_date (company_id, order_date),
  KEY ix_so_outlet  (outlet_id),
  KEY ix_so_platform(platform_id),
  KEY ix_so_settle  (settlement_date),
  CONSTRAINT fk_so_company  FOREIGN KEY (company_id)  REFERENCES companies(id),
  CONSTRAINT fk_so_batch    FOREIGN KEY (batch_id)    REFERENCES sales_import_batches(id),
  CONSTRAINT fk_so_platform FOREIGN KEY (platform_id) REFERENCES platforms(id),
  CONSTRAINT fk_so_outlet   FOREIGN KEY (outlet_id)   REFERENCES outlets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_fee_calculations (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sales_order_id           BIGINT UNSIGNED NOT NULL,
  gross_sales              DECIMAL(14,4) NOT NULL DEFAULT 0,
  commission_amount        DECIMAL(14,4) NOT NULL DEFAULT 0,
  payment_fee_amount       DECIMAL(14,4) NOT NULL DEFAULT 0,
  service_fee_amount       DECIMAL(14,4) NOT NULL DEFAULT 0,
  voucher_cost             DECIMAL(14,4) NOT NULL DEFAULT 0,
  promotion_cost           DECIMAL(14,4) NOT NULL DEFAULT 0,
  refund_amount            DECIMAL(14,4) NOT NULL DEFAULT 0,
  delivery_subsidy         DECIMAL(14,4) NOT NULL DEFAULT 0,
  adjustment_amount        DECIMAL(14,4) NOT NULL DEFAULT 0,
  tax_amount               DECIMAL(14,4) NOT NULL DEFAULT 0,
  net_settlement_system    DECIMAL(14,4) NOT NULL DEFAULT 0,
  net_settlement_imported  DECIMAL(14,4) NOT NULL DEFAULT 0,
  difference               DECIMAL(14,4) NOT NULL DEFAULT 0,
  variance_pct             DECIMAL(10,4) NOT NULL DEFAULT 0,
  reconciliation_status    VARCHAR(40)   NOT NULL DEFAULT 'matched',
  created_at               DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sfc_order (sales_order_id),
  KEY ix_sfc_status (reconciliation_status),
  CONSTRAINT fk_sfc_order FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Bank statements & settlement reconciliation
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bank_statement_imports (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   INT UNSIGNED NOT NULL,
  uploaded_by  INT UNSIGNED NULL,
  file_name    VARCHAR(255) NOT NULL,
  status       VARCHAR(40) NOT NULL DEFAULT 'completed',
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_bsi_company (company_id),
  CONSTRAINT fk_bsi_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_bsi_user    FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bank_transactions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  INT UNSIGNED NOT NULL,
  import_id   INT UNSIGNED NULL,
  value_date  DATE NOT NULL,
  amount      DECIMAL(14,4) NOT NULL DEFAULT 0,
  direction   ENUM('credit','debit') NOT NULL DEFAULT 'credit',
  reference   VARCHAR(160) NULL,
  platform_id INT UNSIGNED NULL,
  outlet_id   INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_bt_company_date (company_id, value_date),
  CONSTRAINT fk_bt_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_bt_import  FOREIGN KEY (import_id)  REFERENCES bank_statement_imports(id),
  CONSTRAINT fk_bt_platform FOREIGN KEY (platform_id) REFERENCES platforms(id),
  CONSTRAINT fk_bt_outlet   FOREIGN KEY (outlet_id)   REFERENCES outlets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settlement_reconciliations (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    INT UNSIGNED NOT NULL,
  platform_id   INT UNSIGNED NOT NULL,
  outlet_id     INT UNSIGNED NOT NULL,
  settle_date   DATE NOT NULL,
  expected      DECIMAL(14,4) NOT NULL DEFAULT 0,
  received      DECIMAL(14,4) NOT NULL DEFAULT 0,
  difference    DECIMAL(14,4) NOT NULL DEFAULT 0,
  delay_days    INT NOT NULL DEFAULT 0,
  status        VARCHAR(40) NOT NULL DEFAULT 'pending',
  created_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sr (company_id, platform_id, outlet_id, settle_date),
  CONSTRAINT fk_sr_company  FOREIGN KEY (company_id)  REFERENCES companies(id),
  CONSTRAINT fk_sr_platform FOREIGN KEY (platform_id) REFERENCES platforms(id),
  CONSTRAINT fk_sr_outlet   FOREIGN KEY (outlet_id)   REFERENCES outlets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Claims, attachments, risk scoring & approval workflow
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS claim_types (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(40) NOT NULL,
  name VARCHAR(80) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ct_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS claims (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id        INT UNSIGNED NOT NULL,
  claimant_id       INT UNSIGNED NOT NULL,
  outlet_id         INT UNSIGNED NOT NULL,
  claim_id          VARCHAR(40) NOT NULL,
  claim_type        VARCHAR(40) NOT NULL,
  claim_date        DATE NOT NULL,
  amount            DECIMAL(12,2) NOT NULL DEFAULT 0,
  supplier          VARCHAR(160) NULL,
  description       TEXT NULL,
  payment_method    VARCHAR(40) NULL,
  cost_center       VARCHAR(60) NULL,
  receipt_path      VARCHAR(255) NULL,
  receipt_hash      CHAR(64)     NULL,
  risk_score        INT NOT NULL DEFAULT 0,
  risk_level        ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
  risk_explanation  TEXT NULL,
  approval_status   ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
  paid_status       ENUM('unpaid','paid')   NOT NULL DEFAULT 'unpaid',
  submitted_at      DATETIME NULL,
  approved_at       DATETIME NULL,
  paid_at           DATETIME NULL,
  created_at        DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_claims_code (claim_id),
  KEY ix_claims_company (company_id, claim_date),
  KEY ix_claims_outlet  (outlet_id),
  KEY ix_claims_risk    (risk_level, risk_score),
  KEY ix_claims_status  (approval_status),
  KEY ix_claims_hash    (receipt_hash),
  CONSTRAINT fk_claims_company FOREIGN KEY (company_id)  REFERENCES companies(id),
  CONSTRAINT fk_claims_user    FOREIGN KEY (claimant_id) REFERENCES users(id),
  CONSTRAINT fk_claims_outlet  FOREIGN KEY (outlet_id)   REFERENCES outlets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS claim_attachments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  claim_id    INT UNSIGNED NOT NULL,
  file_path   VARCHAR(255) NOT NULL,
  file_hash   CHAR(64)     NULL,
  mime_type   VARCHAR(80)  NULL,
  ocr_json    JSON         NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_ca_claim (claim_id),
  CONSTRAINT fk_ca_claim FOREIGN KEY (claim_id) REFERENCES claims(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS claim_risk_scores (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  claim_id     INT UNSIGNED NOT NULL,
  score        INT NOT NULL,
  level        ENUM('low','medium','high','critical') NOT NULL,
  explanation  TEXT NULL,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_crs_claim (claim_id),
  CONSTRAINT fk_crs_claim FOREIGN KEY (claim_id) REFERENCES claims(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS claim_risk_factors (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  claim_risk_score_id INT UNSIGNED NOT NULL,
  factor_key          VARCHAR(60) NOT NULL,
  score               INT NOT NULL DEFAULT 0,
  reason              TEXT NULL,
  PRIMARY KEY (id),
  KEY ix_crf_score (claim_risk_score_id),
  CONSTRAINT fk_crf_score FOREIGN KEY (claim_risk_score_id) REFERENCES claim_risk_scores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS approval_rules (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id        INT UNSIGNED NOT NULL,
  claim_type        VARCHAR(40) NOT NULL DEFAULT '*',
  amount_min        DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount_max        DECIMAL(12,2) NULL,
  required_role_id  INT UNSIGNED NOT NULL,
  risk_threshold    VARCHAR(60) NOT NULL DEFAULT 'low,medium,high,critical',
  auto_approve      TINYINT(1)  NOT NULL DEFAULT 0,
  priority          INT UNSIGNED NOT NULL DEFAULT 100,
  is_active         TINYINT(1)  NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_ar_lookup (company_id, is_active, priority),
  CONSTRAINT fk_ar_company FOREIGN KEY (company_id)       REFERENCES companies(id),
  CONSTRAINT fk_ar_role    FOREIGN KEY (required_role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS claim_approvals (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  claim_id        INT UNSIGNED NOT NULL,
  actor_user_id   INT UNSIGNED NULL,
  decision        ENUM('approved','rejected','escalated') NOT NULL,
  note            TEXT NULL,
  created_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_capp_claim (claim_id),
  CONSTRAINT fk_capp_claim FOREIGN KEY (claim_id)      REFERENCES claims(id) ON DELETE CASCADE,
  CONSTRAINT fk_capp_user  FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Audit, notifications, exports & settings
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    INT UNSIGNED NULL,
  user_id       INT UNSIGNED NULL,
  action        VARCHAR(60)  NOT NULL,
  entity_type   VARCHAR(60)  NOT NULL,
  entity_id     BIGINT UNSIGNED NULL,
  context_json  JSON NULL,
  ip_address    VARCHAR(60) NULL,
  user_agent    VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_al_company_time (company_id, created_at),
  KEY ix_al_action (action),
  KEY ix_al_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   INT UNSIGNED NULL,
  user_id      INT UNSIGNED NULL,
  event        VARCHAR(60)  NOT NULL,
  title        VARCHAR(160) NOT NULL,
  message      TEXT NOT NULL,
  channels     VARCHAR(80)  NOT NULL DEFAULT 'in_app',
  entity_type  VARCHAR(60)  NULL,
  entity_id    BIGINT UNSIGNED NULL,
  read_at      DATETIME NULL,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_notif_user (user_id, read_at),
  KEY ix_notif_event (event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS report_exports (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NULL,
  report_name  VARCHAR(80) NOT NULL,
  format       VARCHAR(10) NOT NULL DEFAULT 'csv',
  file_path    VARCHAR(255) NOT NULL,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_re_company_time (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_settings (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  INT UNSIGNED NULL,
  setting_key   VARCHAR(80) NOT NULL,
  setting_value TEXT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ss (company_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
