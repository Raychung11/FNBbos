-- SLV WMS — Migration 002: Seed defaults
-- Run AFTER 001_init.sql.
-- Idempotent: safe to re-run; existing rows are preserved (INSERT IGNORE / UPDATE).

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- Default company: SLV Group Sdn. Bhd.
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO companies (id, code, name, registered_name, status)
VALUES (1, 'SLV', 'SLV Group Sdn. Bhd.', 'SLV Group Sdn. Bhd.', 'ACTIVE');

-- -----------------------------------------------------------------------------
-- Default super_admin user
--   Email   : admin@slv.local
--   Password: ChangeMe!2026
-- Hash generated with: password_hash('ChangeMe!2026', PASSWORD_BCRYPT) on PHP 8.4.
-- CHANGE THIS PASSWORD ON FIRST LOGIN.
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO users (id, company_id, name, email, password_hash, role, status)
VALUES (
  1, 1, 'SLV Admin', 'admin@slv.local',
  '$2y$12$qH2aqyG5I3UIbWWZpvhR/O10WQ/kSsBpqhY9NvGDOSE5wxp8jBism',
  'super_admin', 'ACTIVE'
);

-- -----------------------------------------------------------------------------
-- Default branding (read by lib/helpers.php setting() with fallbacks).
-- These rows make UI/PDF output explicitly configured rather than fallback-only.
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO app_settings (company_id, key_name, value_text, value_type) VALUES
  (1, 'brand.company_name',    'SLV Group Sdn. Bhd.', 'string'),
  (1, 'brand.primary_color',   '#6D28D9',             'color'),
  (1, 'brand.secondary_color', '#1F2937',             'color'),
  (1, 'brand.accent_color',    '#F59E0B',             'color'),
  (1, 'brand.logo_path',       '',                    'path'),
  (1, 'app.timezone',          'Asia/Kuala_Lumpur',   'string'),
  (1, 'app.currency',          'RM',                  'string'),
  (1, 'app.locale',            'en-MY',               'string');

-- -----------------------------------------------------------------------------
-- Default document sequences (per Section 5.2(b)).
-- Sequence increment must be atomic — handled in lib/docnum.php.
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO document_sequences
  (company_id, doc_type, format_template, current_seq, reset_yearly)
VALUES
  (1, 'INV', 'INV/{YY}/{seq:5}', 0, 1),
  (1, 'DO',  'DO/{YY}/{seq:5}',  0, 1),
  (1, 'SO',  'SO/{YY}/{seq:5}',  0, 1),
  (1, 'GRN', 'GRN/{YY}/{seq:5}', 0, 1),
  (1, 'PO',  'PO/{YY}/{seq:5}',  0, 1),
  (1, 'TRF', 'TRF/{YY}/{seq:5}', 0, 1),
  (1, 'ADJ', 'ADJ/{YY}/{seq:5}', 0, 0),
  (1, 'CNT', 'CNT/{YY}/{seq:5}', 0, 0);

-- -----------------------------------------------------------------------------
-- Default tax codes for Malaysia (SST regime).
-- Rate stored as decimal fraction (0.06 = 6%).
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO tax_codes (company_id, code, name, rate, type, is_compound, is_active) VALUES
  (1, 'SST6',  'Sales Tax 6%',     0.0600, 'output_tax', 0, 1),
  (1, 'SVC6',  'Service Tax 6%',   0.0600, 'output_tax', 0, 1),
  (1, 'ZERO',  'Zero-Rated',       0.0000, 'output_tax', 0, 1);

-- -----------------------------------------------------------------------------
-- Default tax groups
--   STD   = SST 6%        (typical goods)
--   SVC   = Service Tax 6% (services)
--   ZR    = Zero-Rated
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO tax_groups (company_id, code, name) VALUES
  (1, 'STD', 'Standard SST'),
  (1, 'SVC', 'Service Tax'),
  (1, 'ZR',  'Zero-Rated');

-- Bind tax codes into groups (idempotent via PK).
INSERT IGNORE INTO tax_group_codes (group_id, tax_code_id, sort_order)
SELECT g.id, c.id, 0
FROM tax_groups g JOIN tax_codes c
  ON g.company_id = c.company_id
 AND ((g.code = 'STD' AND c.code = 'SST6')
   OR (g.code = 'SVC' AND c.code = 'SVC6')
   OR (g.code = 'ZR'  AND c.code = 'ZERO'))
WHERE g.company_id = 1;

-- -----------------------------------------------------------------------------
-- Default seed warehouse so the dashboard has something to show.
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO warehouses (id, company_id, code, name, status)
VALUES (1, 1, 'WH01', 'Main Warehouse', 'ACTIVE');

INSERT IGNORE INTO user_warehouse_access (user_id, warehouse_id)
VALUES (1, 1);
