-- =============================================================================
-- Phase 4: platform API integration + forecasting + leakage prediction.
-- Apply on existing databases. Fresh installs get this via schema.sql.
-- =============================================================================

CREATE TABLE IF NOT EXISTS platform_credentials (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    INT UNSIGNED NOT NULL,
  platform_id   INT UNSIGNED NOT NULL,
  credentials   JSON NOT NULL,                -- { api_key, store_id, ... } per-platform shape
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  last_sync_at  DATETIME NULL,
  last_status   VARCHAR(40) NULL,
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pc (company_id, platform_id),
  CONSTRAINT fk_pc_company  FOREIGN KEY (company_id)  REFERENCES companies(id),
  CONSTRAINT fk_pc_platform FOREIGN KEY (platform_id) REFERENCES platforms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platform_sync_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    INT UNSIGNED NOT NULL,
  platform_id   INT UNSIGNED NOT NULL,
  triggered_by  INT UNSIGNED NULL,
  date_from     DATE NULL,
  date_to       DATE NULL,
  orders_pulled INT UNSIGNED NOT NULL DEFAULT 0,
  orders_new    INT UNSIGNED NOT NULL DEFAULT 0,
  status        VARCHAR(40) NOT NULL DEFAULT 'running',
  message       TEXT NULL,
  started_at    DATETIME NOT NULL,
  finished_at   DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_psl_lookup (company_id, platform_id, started_at),
  CONSTRAINT fk_psl_company  FOREIGN KEY (company_id)  REFERENCES companies(id),
  CONSTRAINT fk_psl_platform FOREIGN KEY (platform_id) REFERENCES platforms(id),
  CONSTRAINT fk_psl_user     FOREIGN KEY (triggered_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_forecasts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   INT UNSIGNED NOT NULL,
  outlet_id    INT UNSIGNED NULL,
  platform_id  INT UNSIGNED NULL,
  forecast_date DATE NOT NULL,
  forecast_low DECIMAL(14,2) NOT NULL DEFAULT 0,
  forecast_mid DECIMAL(14,2) NOT NULL DEFAULT 0,
  forecast_high DECIMAL(14,2) NOT NULL DEFAULT 0,
  generated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_sf_lookup (company_id, outlet_id, platform_id, forecast_date),
  CONSTRAINT fk_sf_company  FOREIGN KEY (company_id)  REFERENCES companies(id),
  CONSTRAINT fk_sf_outlet   FOREIGN KEY (outlet_id)   REFERENCES outlets(id),
  CONSTRAINT fk_sf_platform FOREIGN KEY (platform_id) REFERENCES platforms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leakage_findings (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   INT UNSIGNED NOT NULL,
  finding_type VARCHAR(60) NOT NULL,        -- e.g. outlet_margin, platform_discrepancy, claim_growth, budget_burn
  severity     ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  entity_type  VARCHAR(40) NULL,
  entity_id    INT UNSIGNED NULL,
  projected_impact DECIMAL(14,2) NOT NULL DEFAULT 0,
  evidence     TEXT NULL,
  acknowledged_at DATETIME NULL,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_lf_lookup (company_id, severity, created_at),
  CONSTRAINT fk_lf_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- New permissions
INSERT INTO permissions (slug, name) VALUES
  ('platforms.sync',  'Trigger platform API sync'),
  ('forecast.view',   'View sales / claim forecasts'),
  ('leakage.view',    'View profit leakage predictions')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Grant to super_admin (already gets everything via seed CROSS JOIN), and
-- explicitly grant to company_admin and finance_admin so they can use them.
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN
    ('platforms.sync','forecast.view','leakage.view')
  WHERE r.slug IN ('super_admin','company_admin','finance_admin')
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);
