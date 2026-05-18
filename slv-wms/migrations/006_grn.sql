-- SLV WMS — Migration 006: Goods Receipt Note
-- Run AFTER 005_stock_engine.sql.
-- Adds the three tables that drive the GRN desktop flow:
--   grn          — header (one per receipt)
--   grn_items    — one row per SKU expected/received
--   grn_putaway  — log of every putaway action against an item
--
-- Stock-side writes still go exclusively through lib/stock.php.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- grn  (header)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS grn (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    INT UNSIGNED    NOT NULL,
  warehouse_id  INT UNSIGNED    NOT NULL,
  grn_no        VARCHAR(64)     NOT NULL,
  supplier_id   INT UNSIGNED    DEFAULT NULL,
  ref_po        VARCHAR(64)     DEFAULT NULL,
  status        ENUM('DRAFT','RECEIVING','RECEIVED','PUTAWAY','CLOSED','CANCELLED')
                NOT NULL DEFAULT 'DRAFT',
  received_at   DATETIME        DEFAULT NULL,
  received_by   INT UNSIGNED    DEFAULT NULL,
  total_value   DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  notes         TEXT            DEFAULT NULL,
  created_by    INT UNSIGNED    DEFAULT NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_grn_company_no (company_id, grn_no),
  KEY idx_grn_warehouse_status (warehouse_id, status),
  KEY idx_grn_supplier         (supplier_id),
  KEY idx_grn_status_time      (status, created_at),
  CONSTRAINT fk_grn_company     FOREIGN KEY (company_id)   REFERENCES companies(id),
  CONSTRAINT fk_grn_warehouse   FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_grn_supplier    FOREIGN KEY (supplier_id)  REFERENCES suppliers(id) ON DELETE SET NULL,
  CONSTRAINT fk_grn_received_by FOREIGN KEY (received_by)  REFERENCES users(id)     ON DELETE SET NULL,
  CONSTRAINT fk_grn_created_by  FOREIGN KEY (created_by)   REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- grn_items
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS grn_items (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  grn_id        BIGINT UNSIGNED NOT NULL,
  product_id    INT UNSIGNED    NOT NULL,
  qty_expected  DECIMAL(14,4)   NOT NULL DEFAULT 0,
  qty_received  DECIMAL(14,4)   NOT NULL DEFAULT 0,
  qty_putaway   DECIMAL(14,4)   NOT NULL DEFAULT 0,
  unit_cost     DECIMAL(14,4)   NOT NULL DEFAULT 0,
  notes         VARCHAR(255)    DEFAULT NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_gi_grn     (grn_id),
  KEY idx_gi_product (product_id),
  CONSTRAINT fk_gi_grn     FOREIGN KEY (grn_id)     REFERENCES grn(id)      ON DELETE CASCADE,
  CONSTRAINT fk_gi_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- grn_putaway  (one row per executed putaway action)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS grn_putaway (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  grn_item_id  BIGINT UNSIGNED NOT NULL,
  bin_id       INT UNSIGNED    NOT NULL,
  qty          DECIMAL(14,4)   NOT NULL,
  unit_cost    DECIMAL(14,4)   NOT NULL,
  layer_id     BIGINT UNSIGNED DEFAULT NULL,
  movement_id  BIGINT UNSIGNED DEFAULT NULL,
  scan_uuid    CHAR(36)        DEFAULT NULL,
  user_id      INT UNSIGNED    DEFAULT NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_gp_scan (scan_uuid),
  KEY idx_gp_item     (grn_item_id),
  KEY idx_gp_bin      (bin_id),
  KEY idx_gp_movement (movement_id),
  CONSTRAINT fk_gp_item     FOREIGN KEY (grn_item_id) REFERENCES grn_items(id)        ON DELETE CASCADE,
  CONSTRAINT fk_gp_bin      FOREIGN KEY (bin_id)      REFERENCES bins(id),
  CONSTRAINT fk_gp_layer    FOREIGN KEY (layer_id)    REFERENCES stock_layers(id)     ON DELETE SET NULL,
  CONSTRAINT fk_gp_movement FOREIGN KEY (movement_id) REFERENCES stock_movements(id)  ON DELETE SET NULL,
  CONSTRAINT fk_gp_user     FOREIGN KEY (user_id)     REFERENCES users(id)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
