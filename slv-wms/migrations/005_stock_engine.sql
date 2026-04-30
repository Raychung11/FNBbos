-- SLV WMS — Migration 005: stock engine
-- Run AFTER 004_password_resets.sql.
-- Creates the four append-only structures that drive FIFO valuation:
--   stock_movements        — append-only ledger (every quantity change)
--   stock_layers           — FIFO-ordered receipts with qty_remaining
--   stock_layer_movements  — which layer(s) each issue consumed
--   inventory              — denormalised current qty per (product, bin)
--
-- Direct writes to stock_layers / inventory / stock_movements MUST go
-- through lib/stock.php only.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- stock_movements  (append-only ledger)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_movements (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    INT UNSIGNED    NOT NULL,
  warehouse_id  INT UNSIGNED    NOT NULL,
  scan_uuid     CHAR(36)        DEFAULT NULL,
  product_id    INT UNSIGNED    NOT NULL,
  bin_id        INT UNSIGNED    NOT NULL,
  qty_delta     DECIMAL(14,4)   NOT NULL,
  movement_type ENUM(
                  'PUTAWAY','PICK','TRANSFER_OUT','TRANSFER_IN',
                  'IW_DISPATCH','IW_RECEIVE','ADJUST_PLUS','ADJUST_MINUS',
                  'COUNT_PLUS','COUNT_MINUS','RETURN_IN','OPENING'
                ) NOT NULL,
  ref_type      VARCHAR(32)     DEFAULT NULL,
  ref_id        BIGINT UNSIGNED DEFAULT NULL,
  ref_line_id   BIGINT UNSIGNED DEFAULT NULL,
  user_id       INT UNSIGNED    DEFAULT NULL,
  notes         TEXT            DEFAULT NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_sm_scan (scan_uuid),
  KEY idx_sm_product_warehouse_time (product_id, warehouse_id, created_at),
  KEY idx_sm_warehouse_time         (warehouse_id, created_at),
  KEY idx_sm_bin_time               (bin_id, created_at),
  KEY idx_sm_ref                    (ref_type, ref_id),
  CONSTRAINT fk_sm_company   FOREIGN KEY (company_id)   REFERENCES companies(id),
  CONSTRAINT fk_sm_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_sm_product   FOREIGN KEY (product_id)   REFERENCES products(id),
  CONSTRAINT fk_sm_bin       FOREIGN KEY (bin_id)       REFERENCES bins(id),
  CONSTRAINT fk_sm_user      FOREIGN KEY (user_id)      REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- stock_layers  (FIFO ledger)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_layers (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id     INT UNSIGNED    NOT NULL,
  warehouse_id   INT UNSIGNED    NOT NULL,
  product_id     INT UNSIGNED    NOT NULL,
  bin_id         INT UNSIGNED    NOT NULL,
  qty_received   DECIMAL(14,4)   NOT NULL,
  qty_remaining  DECIMAL(14,4)   NOT NULL,
  unit_cost      DECIMAL(14,4)   NOT NULL,
  received_at    DATETIME        NOT NULL,
  source_type    ENUM('GRN','OPENING','ADJUST','IW_RECEIVE','RETURN') NOT NULL,
  source_ref_id  BIGINT UNSIGNED DEFAULT NULL,
  status         ENUM('ACTIVE','DEPLETED') NOT NULL DEFAULT 'ACTIVE',
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sl_fifo (product_id, warehouse_id, status, received_at, id),
  KEY idx_sl_bin  (bin_id, status, received_at),
  CONSTRAINT fk_sl_company   FOREIGN KEY (company_id)   REFERENCES companies(id),
  CONSTRAINT fk_sl_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_sl_product   FOREIGN KEY (product_id)   REFERENCES products(id),
  CONSTRAINT fk_sl_bin       FOREIGN KEY (bin_id)       REFERENCES bins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- stock_layer_movements  (which layer was consumed by which issue movement)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_layer_movements (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  stock_movement_id  BIGINT UNSIGNED NOT NULL,
  layer_id           BIGINT UNSIGNED NOT NULL,
  qty_consumed       DECIMAL(14,4)   NOT NULL,
  unit_cost          DECIMAL(14,4)   NOT NULL,
  PRIMARY KEY (id),
  KEY idx_slm_movement (stock_movement_id),
  KEY idx_slm_layer    (layer_id),
  CONSTRAINT fk_slm_movement FOREIGN KEY (stock_movement_id) REFERENCES stock_movements(id) ON DELETE CASCADE,
  CONSTRAINT fk_slm_layer    FOREIGN KEY (layer_id)          REFERENCES stock_layers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- inventory  (current qty per product+bin; cached, recomputed by lib/stock.php)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   INT UNSIGNED    NOT NULL,
  warehouse_id INT UNSIGNED    NOT NULL,
  product_id   INT UNSIGNED    NOT NULL,
  bin_id       INT UNSIGNED    NOT NULL,
  qty          DECIMAL(14,4)   NOT NULL DEFAULT 0,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_inv_product_bin (product_id, bin_id),
  KEY idx_inv_warehouse (warehouse_id, product_id),
  CONSTRAINT fk_inv_company   FOREIGN KEY (company_id)   REFERENCES companies(id),
  CONSTRAINT fk_inv_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_inv_product   FOREIGN KEY (product_id)   REFERENCES products(id),
  CONSTRAINT fk_inv_bin       FOREIGN KEY (bin_id)       REFERENCES bins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
