-- SLV WMS — Migration 004: password_resets
-- Run AFTER 003_master_data.sql.
-- Token-based forgot-password flow. Stores SHA-256 of the plaintext token;
-- the plaintext is emailed in the reset link and never persisted server-side.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE IF NOT EXISTS password_resets (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED  NOT NULL,
  token_hash  CHAR(64)      NOT NULL,
  expires_at  DATETIME      NOT NULL,
  used_at     DATETIME      DEFAULT NULL,
  ip          VARCHAR(64)   DEFAULT NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_pr_token   (token_hash),
  KEY        idx_pr_user     (user_id, expires_at),
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
