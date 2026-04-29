-- SLV WMS — Migration 001: Identity, Settings, Tax, Audit
-- Run order: first migration on a fresh database.
-- Engine: InnoDB / utf8mb4 / utf8mb4_unicode_ci.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- companies
-- Single-company today, multi-company-ready. Every operational table FKs here.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS companies (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  code            VARCHAR(64)   NOT NULL,
  name            VARCHAR(191)  NOT NULL,
  registered_name VARCHAR(191)  DEFAULT NULL,
  address         TEXT          DEFAULT NULL,
  phone           VARCHAR(64)   DEFAULT NULL,
  email           VARCHAR(191)  DEFAULT NULL,
  registration_no VARCHAR(64)   DEFAULT NULL,
  tax_no          VARCHAR(64)   DEFAULT NULL,
  status          ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_companies_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- warehouses
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS warehouses (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  company_id  INT UNSIGNED  NOT NULL,
  code        VARCHAR(64)   NOT NULL,
  name        VARCHAR(191)  NOT NULL,
  address     TEXT          DEFAULT NULL,
  contact     VARCHAR(191)  DEFAULT NULL,
  status      ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_warehouses_company_code (company_id, code),
  KEY idx_warehouses_company (company_id),
  CONSTRAINT fk_warehouses_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- users
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  company_id     INT UNSIGNED  NOT NULL,
  name           VARCHAR(191)  NOT NULL,
  email          VARCHAR(191)  NOT NULL,
  phone          VARCHAR(64)   DEFAULT NULL,
  password_hash  VARCHAR(255)  NOT NULL,
  role           ENUM(
                   'super_admin','warehouse_manager','receiver','picker',
                   'packer','driver','sales','viewer'
                 ) NOT NULL,
  status         ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  last_login_at  DATETIME      DEFAULT NULL,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_users_email (email),
  KEY idx_users_company (company_id),
  CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- user_warehouse_access
-- ANDed with role permissions. super_admin bypasses this check at runtime.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_warehouse_access (
  user_id      INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  granted_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  granted_by   INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (user_id, warehouse_id),
  KEY idx_uwa_warehouse (warehouse_id),
  CONSTRAINT fk_uwa_user      FOREIGN KEY (user_id)      REFERENCES users(id)      ON DELETE CASCADE,
  CONSTRAINT fk_uwa_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- app_settings  (key/value, per-company)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_settings (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  company_id  INT UNSIGNED  NOT NULL,
  key_name    VARCHAR(128)  NOT NULL,
  value_text  TEXT          DEFAULT NULL,
  value_type  ENUM('string','int','decimal','bool','json','color','path')
              NOT NULL DEFAULT 'string',
  updated_by  INT UNSIGNED  DEFAULT NULL,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_settings_company_key (company_id, key_name),
  KEY idx_settings_company (company_id),
  CONSTRAINT fk_settings_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- document_sequences  (atomic counters per doc_type)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS document_sequences (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  company_id       INT UNSIGNED  NOT NULL,
  doc_type         VARCHAR(32)   NOT NULL,
  format_template  VARCHAR(128)  NOT NULL,
  current_seq      INT UNSIGNED  NOT NULL DEFAULT 0,
  reset_yearly     TINYINT(1)    NOT NULL DEFAULT 0,
  last_reset_year  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_seq_company_doctype (company_id, doc_type),
  CONSTRAINT fk_seq_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- tax_codes
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tax_codes (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  company_id   INT UNSIGNED  NOT NULL,
  code         VARCHAR(32)   NOT NULL,
  name         VARCHAR(191)  NOT NULL,
  rate         DECIMAL(7,4)  NOT NULL DEFAULT 0,
  type         ENUM('output_tax','withholding','flat') NOT NULL DEFAULT 'output_tax',
  is_compound  TINYINT(1)    NOT NULL DEFAULT 0,
  is_active    TINYINT(1)    NOT NULL DEFAULT 1,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_tax_company_code (company_id, code),
  CONSTRAINT fk_tax_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- tax_groups
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tax_groups (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  company_id  INT UNSIGNED  NOT NULL,
  code        VARCHAR(32)   NOT NULL,
  name        VARCHAR(191)  NOT NULL,
  is_active   TINYINT(1)    NOT NULL DEFAULT 1,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_tg_company_code (company_id, code),
  CONSTRAINT fk_tg_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- tax_group_codes  (many-to-many)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tax_group_codes (
  group_id    INT UNSIGNED NOT NULL,
  tax_code_id INT UNSIGNED NOT NULL,
  sort_order  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (group_id, tax_code_id),
  KEY idx_tgc_tax (tax_code_id),
  CONSTRAINT fk_tgc_group FOREIGN KEY (group_id)    REFERENCES tax_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_tgc_tax   FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- audit_logs  (append-only)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    INT UNSIGNED    NOT NULL,
  user_id       INT UNSIGNED    DEFAULT NULL,
  action        VARCHAR(64)     NOT NULL,
  entity_type   VARCHAR(64)     NOT NULL,
  entity_id     BIGINT UNSIGNED DEFAULT NULL,
  payload_json  MEDIUMTEXT      DEFAULT NULL,
  ip            VARCHAR(64)     DEFAULT NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_company (company_id, created_at),
  KEY idx_audit_entity  (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
