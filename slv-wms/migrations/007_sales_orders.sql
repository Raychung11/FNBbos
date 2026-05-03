-- SLV WMS — Migration 007: Sales orders + pick lists
-- Run AFTER 006_grn.sql.
-- Adds the four tables that drive the sell-side flow up to the picking stage.
-- Invoicing, delivery orders, and the actual stock-issue mutations land in
-- subsequent migrations / phases (8 + onwards).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- sales_orders  (header)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sales_orders (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      INT UNSIGNED    NOT NULL,
  warehouse_id    INT UNSIGNED    NOT NULL,
  so_no           VARCHAR(64)     NOT NULL,
  customer_id     INT UNSIGNED    NOT NULL,
  status          ENUM('DRAFT','CONFIRMED','PICKING','PICKED','INVOICED','DELIVERED','CANCELLED')
                  NOT NULL DEFAULT 'DRAFT',
  order_date      DATE            NOT NULL,
  requested_date  DATE            DEFAULT NULL,
  subtotal        DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  tax_total       DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  grand_total     DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  notes           TEXT            DEFAULT NULL,
  created_by      INT UNSIGNED    DEFAULT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_so_company_no   (company_id, so_no),
  KEY idx_so_warehouse_status     (warehouse_id, status),
  KEY idx_so_customer             (customer_id),
  KEY idx_so_status_time          (status, created_at),
  CONSTRAINT fk_so_company    FOREIGN KEY (company_id)   REFERENCES companies(id),
  CONSTRAINT fk_so_warehouse  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_so_customer   FOREIGN KEY (customer_id)  REFERENCES customers(id),
  CONSTRAINT fk_so_created_by FOREIGN KEY (created_by)   REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- so_items
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS so_items (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  so_id          BIGINT UNSIGNED NOT NULL,
  product_id     INT UNSIGNED    NOT NULL,
  qty_ordered    DECIMAL(14,4)   NOT NULL,
  qty_picked     DECIMAL(14,4)   NOT NULL DEFAULT 0,
  unit_price     DECIMAL(14,4)   NOT NULL,
  tax_group_id   INT UNSIGNED    DEFAULT NULL,
  line_subtotal  DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  line_tax       DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  line_total     DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  notes          VARCHAR(255)    DEFAULT NULL,
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_si_so       (so_id),
  KEY idx_si_product  (product_id),
  KEY idx_si_taxgroup (tax_group_id),
  CONSTRAINT fk_si_so       FOREIGN KEY (so_id)        REFERENCES sales_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_si_product  FOREIGN KEY (product_id)   REFERENCES products(id),
  CONSTRAINT fk_si_taxgroup FOREIGN KEY (tax_group_id) REFERENCES tax_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- pick_lists  (one per SO; could split a SO into multiple in future)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pick_lists (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id        INT UNSIGNED    NOT NULL,
  warehouse_id      INT UNSIGNED    NOT NULL,
  so_id             BIGINT UNSIGNED NOT NULL,
  pick_no           VARCHAR(64)     NOT NULL,
  assigned_user_id  INT UNSIGNED    DEFAULT NULL,
  status            ENUM('DRAFT','ASSIGNED','IN_PROGRESS','COMPLETED','CANCELLED')
                    NOT NULL DEFAULT 'DRAFT',
  notes             TEXT            DEFAULT NULL,
  created_by        INT UNSIGNED    DEFAULT NULL,
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at        DATETIME        DEFAULT NULL,
  completed_at      DATETIME        DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_pl_company_no (company_id, pick_no),
  KEY idx_pl_so          (so_id),
  KEY idx_pl_assigned    (assigned_user_id),
  KEY idx_pl_warehouse   (warehouse_id, status),
  CONSTRAINT fk_pl_company    FOREIGN KEY (company_id)       REFERENCES companies(id),
  CONSTRAINT fk_pl_warehouse  FOREIGN KEY (warehouse_id)     REFERENCES warehouses(id),
  CONSTRAINT fk_pl_so         FOREIGN KEY (so_id)            REFERENCES sales_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_pl_assigned   FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_pl_created_by FOREIGN KEY (created_by)       REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- pick_items  (one row per (so_item, suggested_bin); the picker may end up
--              filling from a different bin and we record that too)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pick_items (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pick_list_id        BIGINT UNSIGNED NOT NULL,
  so_item_id          BIGINT UNSIGNED NOT NULL,
  product_id          INT UNSIGNED    NOT NULL,
  suggested_bin_id    INT UNSIGNED    DEFAULT NULL,
  qty_to_pick         DECIMAL(14,4)   NOT NULL,
  qty_picked          DECIMAL(14,4)   NOT NULL DEFAULT 0,
  picked_from_bin_id  INT UNSIGNED    DEFAULT NULL,
  scan_uuid           CHAR(36)        DEFAULT NULL,
  movement_id         BIGINT UNSIGNED DEFAULT NULL,
  picked_at           DATETIME        DEFAULT NULL,
  picked_by           INT UNSIGNED    DEFAULT NULL,
  walk_order          INT UNSIGNED    NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_pi_scan (scan_uuid),
  KEY idx_pi_pick    (pick_list_id, walk_order),
  KEY idx_pi_so_item (so_item_id),
  KEY idx_pi_bin_sug (suggested_bin_id),
  KEY idx_pi_bin_act (picked_from_bin_id),
  KEY idx_pi_movement(movement_id),
  CONSTRAINT fk_pi_pick      FOREIGN KEY (pick_list_id)       REFERENCES pick_lists(id)      ON DELETE CASCADE,
  CONSTRAINT fk_pi_so_item   FOREIGN KEY (so_item_id)         REFERENCES so_items(id),
  CONSTRAINT fk_pi_product   FOREIGN KEY (product_id)         REFERENCES products(id),
  CONSTRAINT fk_pi_bin_sug   FOREIGN KEY (suggested_bin_id)   REFERENCES bins(id) ON DELETE SET NULL,
  CONSTRAINT fk_pi_bin_act   FOREIGN KEY (picked_from_bin_id) REFERENCES bins(id) ON DELETE SET NULL,
  CONSTRAINT fk_pi_movement  FOREIGN KEY (movement_id)        REFERENCES stock_movements(id) ON DELETE SET NULL,
  CONSTRAINT fk_pi_picked_by FOREIGN KEY (picked_by)          REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
