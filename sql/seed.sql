-- =============================================================================
-- Seed data: roles, permissions, default claim types, a starter super admin.
-- Run AFTER schema.sql.
-- =============================================================================

INSERT INTO roles (slug, name) VALUES
  ('super_admin',    'Super Admin'),
  ('company_admin',  'Company Admin'),
  ('finance_admin',  'Finance Admin'),
  ('area_manager',   'Area Manager'),
  ('outlet_manager', 'Outlet Manager'),
  ('staff',          'Staff'),
  ('auditor',        'Auditor / Viewer')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO permissions (slug, name) VALUES
  ('dashboard.view',      'View dashboard'),
  ('companies.manage',    'Manage companies and brands'),
  ('outlets.manage',      'Manage outlets'),
  ('users.manage',        'Manage users and roles'),
  ('platforms.manage',    'Manage platforms and fee rules'),
  ('tax.manage',          'Manage tax profiles and rules'),
  ('approvals.manage',    'Manage approval rules'),
  ('sales.import',        'Import sales CSVs'),
  ('sales.view',          'View sales orders and reconciliation'),
  ('bank.import',         'Import bank statements'),
  ('claims.submit',       'Submit claims'),
  ('claims.view',         'View claims'),
  ('claims.review',       'Review and approve/reject claims'),
  ('claims.pay',          'Mark claims as paid'),
  ('reports.view',        'View and export reports'),
  ('audit.view',          'View audit logs'),
  ('demo.review',         'View and manage demo requests')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Super Admin: every permission.
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
  WHERE r.slug = 'super_admin'
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

-- Company Admin: everything except super-admin-only management of cross-company entities.
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN
    ('dashboard.view','outlets.manage','users.manage','platforms.manage','tax.manage',
     'approvals.manage','sales.import','sales.view','bank.import','claims.view',
     'claims.review','reports.view','audit.view','demo.review')
  WHERE r.slug = 'company_admin'
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

-- Finance Admin: claims review/pay, reconciliation, reports.
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN
    ('dashboard.view','sales.view','bank.import','claims.view','claims.review',
     'claims.pay','reports.view','audit.view','tax.manage')
  WHERE r.slug = 'finance_admin'
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

-- Area Manager: review small/medium claims, view sales.
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN
    ('dashboard.view','sales.view','claims.view','claims.review','reports.view')
  WHERE r.slug = 'area_manager'
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

-- Outlet Manager: see own outlet, submit + review small claims.
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN
    ('dashboard.view','sales.view','claims.submit','claims.view','claims.review')
  WHERE r.slug = 'outlet_manager'
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

-- Staff: submit + view own.
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN
    ('dashboard.view','claims.submit','claims.view')
  WHERE r.slug = 'staff'
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

-- Auditor: read-only.
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN
    ('dashboard.view','sales.view','claims.view','reports.view','audit.view')
  WHERE r.slug = 'auditor'
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

INSERT INTO claim_types (slug, name) VALUES
  ('staff_meal',         'Staff meal'),
  ('petrol',             'Petrol'),
  ('transport',          'Transport'),
  ('supplier_purchase',  'Supplier purchase'),
  ('petty_cash',         'Petty cash'),
  ('marketing',          'Marketing'),
  ('delivery',           'Delivery'),
  ('maintenance',        'Maintenance'),
  ('voucher_redemption', 'Voucher redemption'),
  ('other',              'Other')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- A starter company + super admin user.
-- Default password: ChangeMe123! (bcrypt hash below).
INSERT INTO companies (id, name, registration_no, sst_number, created_at)
  VALUES (1, 'Demo F&B Group Sdn Bhd', '123456-A', NULL, NOW())
  ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Bcrypt hash for "ChangeMe123!"
INSERT INTO users (company_id, role_id, name, email, password_hash, is_active, created_at)
  SELECT 1, r.id, 'Super Admin', 'admin@example.com',
         '$2y$12$wHVbXuqf3I5b4Z5JmuDNeOQO9D5w5kPj2wGTZcVf1tWZ7f3jGMTfa',
         1, NOW()
  FROM roles r WHERE r.slug = 'super_admin'
  ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash);

-- Default approval matrix (lowest priority first).
INSERT INTO approval_rules (company_id, claim_type, amount_min, amount_max, required_role_id, risk_threshold, auto_approve, priority, is_active, created_at)
  SELECT 1, '*',     0,    100,    r.id, 'low,medium,high,critical', 0, 10, 1, NOW() FROM roles r WHERE r.slug='outlet_manager'
  UNION ALL
  SELECT 1, '*',   100,    500,    r.id, 'low,medium,high,critical', 0, 20, 1, NOW() FROM roles r WHERE r.slug='area_manager'
  UNION ALL
  SELECT 1, '*',   500,   2000,    r.id, 'low,medium,high,critical', 0, 30, 1, NOW() FROM roles r WHERE r.slug='finance_admin'
  UNION ALL
  SELECT 1, '*',  2000,   NULL,    r.id, 'low,medium,high,critical', 0, 40, 1, NOW() FROM roles r WHERE r.slug='company_admin'
  UNION ALL
  SELECT 1, '*',     0,   NULL,    r.id, 'high,critical',            0, 5,  1, NOW() FROM roles r WHERE r.slug='finance_admin';

-- A starter SST profile (8% standard, exclusive). Rates are configurable — never hard-coded.
INSERT INTO tax_profiles (company_id, name, category, is_inclusive, is_active, created_at)
  VALUES (1, 'Standard SST 8%', 'standard', 0, 1, NOW())
  ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO tax_rules (tax_profile_id, rate, effective_from, is_active, created_at)
  SELECT id, 0.08, '2024-03-01', 1, NOW() FROM tax_profiles WHERE company_id=1 AND name='Standard SST 8%';

-- Common platforms with empty rules — fill in via the UI.
INSERT INTO platforms (company_id, code, name, settlement_cycle_days, is_active, created_at) VALUES
  (1, 'grabfood',     'GrabFood',         7, 1, NOW()),
  (1, 'foodpanda',    'Foodpanda',        7, 1, NOW()),
  (1, 'shopeefood',   'ShopeeFood',       7, 1, NOW()),
  (1, 'pos',          'POS',              1, 1, NOW()),
  (1, 'website',      'Website',          3, 1, NOW()),
  (1, 'whatsapp',     'WhatsApp Order',   3, 1, NOW()),
  (1, 'manual',       'Manual Sales',     1, 1, NOW()),
  (1, 'catering',     'Catering / Event', 7, 1, NOW())
  ON DUPLICATE KEY UPDATE name = VALUES(name);
