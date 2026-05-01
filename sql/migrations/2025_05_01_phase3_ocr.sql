-- =============================================================================
-- Phase 3: OCR fields denormalised on claims for fast filter/display.
-- claim_attachments.ocr_json keeps the full extraction (raw text + items).
-- =============================================================================

ALTER TABLE claims
  ADD COLUMN ocr_total    DECIMAL(12,2) NULL AFTER receipt_hash,
  ADD COLUMN ocr_merchant VARCHAR(160)  NULL AFTER ocr_total,
  ADD COLUMN ocr_date     DATE          NULL AFTER ocr_merchant,
  ADD COLUMN ocr_status   VARCHAR(20)   NOT NULL DEFAULT 'none' AFTER ocr_date;
-- ocr_status values: none | success | failed | mismatch
