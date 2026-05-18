-- SLV WMS — Migration 003: Master data
-- Run AFTER 002_seed.sql.
-- Adds: categories, products, product_barcodes, product_warehouse_settings,
--       zones, racks, bins, suppliers, customers, import_jobs.
-- Stock-related tables (stock_movements, stock_layers, inventory) ship in
-- migration 004 with the FIFO engine (Phase 3).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- categories  (self-referential tree; parent_id NULL = top level)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  company_id  INT UNSIGNED  NOT NULL,
  name        VARCHAR(191)  NOT NULL,
  parent_id   INT UNSIGNED  DEFAULT NULL,
  status      ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_categories_company_name_parent (company_id, name, parent_id),
  KEY idx_categories_company_parent (company_id, parent_id),
  CONSTRAINT fk_categories_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_categories_parent  FOREIGN KEY (parent_id)  REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- products  (SKU master; stock is per warehouse via inventory/stock_layers)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
  id                    INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  company_id            INT UNSIGNED   NOT NULL,
  sku_code              VARCHAR(64)    NOT NULL,
  name                  VARCHAR(255)   NOT NULL,
  category_id           INT UNSIGNED   DEFAULT NULL,
  uom                   VARCHAR(16)    NOT NULL DEFAULT 'pcs',
  pack_size             DECIMAL(14,4)  NOT NULL DEFAULT 1.0000,
  weight_kg             DECIMAL(12,4)  DEFAULT NULL,
  selling_price         DECIMAL(14,2)  NOT NULL DEFAULT 0.00,
  min_qty               DECIMAL(14,4)  NOT NULL DEFAULT 0,
  max_qty               DECIMAL(14,4)  NOT NULL DEFAULT 0,
  reorder_point         DECIMAL(14,4)  NOT NULL DEFAULT 0,
  default_tax_group_id  INT UNSIGNED   DEFAULT NULL,
  status                ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  notes                 TEXT           DEFAULT NULL,
  created_at            DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_products_company_sku (company_id, sku_code),
  KEY idx_products_company   (company_id),
  KEY idx_products_category  (category_id),
  KEY idx_products_taxgroup  (default_tax_group_id),
  KEY idx_products_status    (company_id, status),
  CONSTRAINT fk_products_company  FOREIGN KEY (company_id)           REFERENCES companies(id),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id)          REFERENCES categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_products_taxgroup FOREIGN KEY (default_tax_group_id) REFERENCES tax_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- product_barcodes  (multiple barcodes per SKU; one is primary)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_barcodes (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  product_id  INT UNSIGNED  NOT NULL,
  barcode     VARCHAR(128)  NOT NULL,
  type        ENUM('EAN','UPC','QR','INTERNAL','OTHER') NOT NULL DEFAULT 'INTERNAL',
  is_primary  TINYINT(1)    NOT NULL DEFAULT 0,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_barcode (barcode),
  KEY idx_pb_product (product_id),
  CONSTRAINT fk_pb_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- product_warehouse_settings  (per-warehouse overrides for min/max/reorder
-- and the SKU's default putaway zone in that warehouse)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_warehouse_settings (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  product_id      INT UNSIGNED  NOT NULL,
  warehouse_id    INT UNSIGNED  NOT NULL,
  default_zone_id INT UNSIGNED  DEFAULT NULL,
  min_qty         DECIMAL(14,4) NOT NULL DEFAULT 0,
  max_qty         DECIMAL(14,4) NOT NULL DEFAULT 0,
  reorder_point   DECIMAL(14,4) NOT NULL DEFAULT 0,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_pws_product_warehouse (product_id, warehouse_id),
  KEY idx_pws_warehouse (warehouse_id),
  CONSTRAINT fk_pws_product   FOREIGN KEY (product_id)      REFERENCES products(id)    ON DELETE CASCADE,
  CONSTRAINT fk_pws_warehouse FOREIGN KEY (warehouse_id)    REFERENCES warehouses(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- zones / racks / bins  (warehouse → zone → rack → bin)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS zones (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  warehouse_id INT UNSIGNED  NOT NULL,
  code         VARCHAR(32)   NOT NULL,
  name         VARCHAR(191)  NOT NULL,
  type         ENUM('GENERAL','COLD','DRY','BULK','RETURNS','PACKING','STAGING','OTHER') NOT NULL DEFAULT 'GENERAL',
  status       ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_zones_warehouse_code (warehouse_id, code),
  CONSTRAINT fk_zones_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS racks (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  zone_id     INT UNSIGNED  NOT NULL,
  code        VARCHAR(32)   NOT NULL,
  name        VARCHAR(191)  DEFAULT NULL,
  status      ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_racks_zone_code (zone_id, code),
  CONSTRAINT fk_racks_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bins (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  rack_id         INT UNSIGNED  NOT NULL,
  code            VARCHAR(32)   NOT NULL,
  full_code       VARCHAR(160)  NOT NULL,
  barcode         VARCHAR(128)  NOT NULL,
  capacity_units  DECIMAL(14,4) DEFAULT NULL,
  pickable        TINYINT(1)    NOT NULL DEFAULT 1,
  status          ENUM('ACTIVE','BLOCKED','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_bins_rack_code (rack_id, code),
  UNIQUE KEY uniq_bins_barcode   (barcode),
  UNIQUE KEY uniq_bins_full_code (full_code),
  CONSTRAINT fk_bins_rack FOREIGN KEY (rack_id) REFERENCES racks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- suppliers
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS suppliers (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  company_id      INT UNSIGNED  NOT NULL,
  code            VARCHAR(64)   NOT NULL,
  name            VARCHAR(191)  NOT NULL,
  contact_person  VARCHAR(191)  DEFAULT NULL,
  phone           VARCHAR(64)   DEFAULT NULL,
  email           VARCHAR(191)  DEFAULT NULL,
  address         TEXT          DEFAULT NULL,
  tax_no          VARCHAR(64)   DEFAULT NULL,
  payment_terms   VARCHAR(64)   DEFAULT NULL,
  status          ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  notes           TEXT          DEFAULT NULL,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_suppliers_company_code (company_id, code),
  KEY idx_suppliers_status (company_id, status),
  CONSTRAINT fk_suppliers_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- customers
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  company_id        INT UNSIGNED  NOT NULL,
  code              VARCHAR(64)   NOT NULL,
  name              VARCHAR(191)  NOT NULL,
  contact_person    VARCHAR(191)  DEFAULT NULL,
  phone             VARCHAR(64)   DEFAULT NULL,
  email             VARCHAR(191)  DEFAULT NULL,
  billing_address   TEXT          DEFAULT NULL,
  shipping_address  TEXT          DEFAULT NULL,
  tax_no            VARCHAR(64)   DEFAULT NULL,
  payment_terms     VARCHAR(64)   DEFAULT NULL,
  default_tax_group_id INT UNSIGNED DEFAULT NULL,
  credit_limit      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  status            ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  notes             TEXT          DEFAULT NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_customers_company_code (company_id, code),
  KEY idx_customers_status   (company_id, status),
  KEY idx_customers_taxgroup (default_tax_group_id),
  CONSTRAINT fk_customers_company  FOREIGN KEY (company_id)           REFERENCES companies(id),
  CONSTRAINT fk_customers_taxgroup FOREIGN KEY (default_tax_group_id) REFERENCES tax_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- import_jobs  (tracks every CSV import + per-row results)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS import_jobs (
  id              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  company_id      INT UNSIGNED   NOT NULL,
  type            VARCHAR(32)    NOT NULL,
  filename        VARCHAR(255)   NOT NULL,
  file_path       VARCHAR(500)   NOT NULL,
  total_rows      INT UNSIGNED   NOT NULL DEFAULT 0,
  processed_rows  INT UNSIGNED   NOT NULL DEFAULT 0,
  success_rows    INT UNSIGNED   NOT NULL DEFAULT 0,
  error_rows      INT UNSIGNED   NOT NULL DEFAULT 0,
  status          ENUM('PENDING','RUNNING','COMPLETED','FAILED') NOT NULL DEFAULT 'PENDING',
  options_json    TEXT           DEFAULT NULL,
  error_csv_path  VARCHAR(500)   DEFAULT NULL,
  created_by      INT UNSIGNED   DEFAULT NULL,
  created_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  finished_at     DATETIME       DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_import_company_status (company_id, status),
  CONSTRAINT fk_import_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
