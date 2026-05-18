-- =============================================================================
-- Migration: add demo_requests table + demo.review permission.
-- Apply this on existing databases (already past schema.sql v1).
-- New installs get this automatically via schema.sql + seed.sql.
-- =============================================================================

CREATE TABLE IF NOT EXISTS demo_requests (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name     VARCHAR(120) NOT NULL,
  company_name  VARCHAR(160) NOT NULL,
  email         VARCHAR(160) NOT NULL,
  phone         VARCHAR(40)  NULL,
  outlet_count  INT UNSIGNED NULL,
  platforms     VARCHAR(255) NULL,
  message       TEXT         NULL,
  status        ENUM('new','contacted','demoed','converted','rejected') NOT NULL DEFAULT 'new',
  ip_address    VARCHAR(60)  NULL,
  user_agent    VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_dr_status (status, created_at),
  KEY ix_dr_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO permissions (slug, name) VALUES
  ('demo.review', 'View and manage demo requests')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Super Admin already has all permissions via the seed's CROSS JOIN.
-- This grants demo.review to Company Admin so the SaaS operator can review
-- requests from a company-admin login if they don't want to use super admin.
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug = 'demo.review'
  WHERE r.slug IN ('super_admin','company_admin')
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);
