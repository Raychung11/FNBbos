-- SLV WMS — Migration 009: Invoices + Delivery Orders
-- Run AFTER 008_pick_sequence.sql.
-- Adds the five tables that drive the post-pick billing + dispatch flow.
-- Invoice numbers and DO numbers come from the document_sequences rows
-- already seeded in 002_seed.sql.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- invoices  (header)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoices (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    INT UNSIGNED    NOT NULL,
  warehouse_id  INT UNSIGNED    NOT NULL,
  invoice_no    VARCHAR(64)     NOT NULL,
  so_id         BIGINT UNSIGNED NOT NULL,
  customer_id   INT UNSIGNED    NOT NULL,
  invoice_date  DATE            NOT NULL,
  subtotal      DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  tax_total     DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  grand_total   DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  status        ENUM('DRAFT','SENT','PAID','VOID','CANCELLED') NOT NULL DEFAULT 'DRAFT',
  notes         TEXT            DEFAULT NULL,
  pdf_path      VARCHAR(500)    DEFAULT NULL,
  created_by    INT UNSIGNED    DEFAULT NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_inv_company_no (company_id, invoice_no),
  KEY idx_inv_so       (so_id),
  KEY idx_inv_customer (customer_id),
  KEY idx_inv_warehouse_status (warehouse_id, status),
  KEY idx_inv_date     (invoice_date),
  CONSTRAINT fk_inv_company    FOREIGN KEY (company_id)   REFERENCES companies(id),
  CONSTRAINT fk_inv_warehouse  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_inv_so         FOREIGN KEY (so_id)        REFERENCES sales_orders(id),
  CONSTRAINT fk_inv_customer   FOREIGN KEY (customer_id)  REFERENCES customers(id),
  CONSTRAINT fk_inv_created_by FOREIGN KEY (created_by)   REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- invoice_items
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoice_items (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id      BIGINT UNSIGNED NOT NULL,
  product_id      INT UNSIGNED    NOT NULL,
  qty             DECIMAL(14,4)   NOT NULL,
  unit_price      DECIMAL(14,4)   NOT NULL,
  tax_group_id    INT UNSIGNED    DEFAULT NULL,
  line_subtotal   DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  line_tax        DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  line_total      DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  notes           VARCHAR(255)    DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_ii_invoice (invoice_id),
  KEY idx_ii_product (product_id),
  CONSTRAINT fk_ii_invoice  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_ii_product  FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_ii_taxgroup FOREIGN KEY (tax_group_id) REFERENCES tax_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- invoice_taxes  (per-tax-code breakdown for SST filing)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoice_taxes (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id      BIGINT UNSIGNED NOT NULL,
  tax_code_id     INT UNSIGNED    NOT NULL,
  rate            DECIMAL(7,4)    NOT NULL,
  taxable_amount  DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  tax_amount      DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_it_invoice_code (invoice_id, tax_code_id),
  KEY idx_it_tax (tax_code_id),
  CONSTRAINT fk_it_invoice FOREIGN KEY (invoice_id)  REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_it_tax     FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- delivery_orders  (one per invoice for now; could support partials later)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS delivery_orders (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      INT UNSIGNED    NOT NULL,
  warehouse_id    INT UNSIGNED    NOT NULL,
  do_no           VARCHAR(64)     NOT NULL,
  invoice_id      BIGINT UNSIGNED NOT NULL,
  customer_id     INT UNSIGNED    NOT NULL,
  driver_user_id  INT UNSIGNED    DEFAULT NULL,
  vehicle         VARCHAR(64)     DEFAULT NULL,
  status          ENUM('READY','IN_TRANSIT','DELIVERED','RETURNED','CANCELLED') NOT NULL DEFAULT 'READY',
  dispatched_at   DATETIME        DEFAULT NULL,
  delivered_at    DATETIME        DEFAULT NULL,
  pod_path        VARCHAR(500)    DEFAULT NULL,
  signature_path  VARCHAR(500)    DEFAULT NULL,
  notes           TEXT            DEFAULT NULL,
  created_by      INT UNSIGNED    DEFAULT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_do_company_no (company_id, do_no),
  KEY idx_do_invoice  (invoice_id),
  KEY idx_do_customer (customer_id),
  KEY idx_do_driver   (driver_user_id),
  KEY idx_do_status_time (warehouse_id, status, dispatched_at),
  CONSTRAINT fk_do_company    FOREIGN KEY (company_id)     REFERENCES companies(id),
  CONSTRAINT fk_do_warehouse  FOREIGN KEY (warehouse_id)   REFERENCES warehouses(id),
  CONSTRAINT fk_do_invoice    FOREIGN KEY (invoice_id)     REFERENCES invoices(id),
  CONSTRAINT fk_do_customer   FOREIGN KEY (customer_id)    REFERENCES customers(id),
  CONSTRAINT fk_do_driver     FOREIGN KEY (driver_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_do_created_by FOREIGN KEY (created_by)     REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- do_items
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS do_items (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  do_id       BIGINT UNSIGNED NOT NULL,
  product_id  INT UNSIGNED    NOT NULL,
  qty         DECIMAL(14,4)   NOT NULL,
  notes       VARCHAR(255)    DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_di_do      (do_id),
  KEY idx_di_product (product_id),
  CONSTRAINT fk_di_do      FOREIGN KEY (do_id)      REFERENCES delivery_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_di_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
