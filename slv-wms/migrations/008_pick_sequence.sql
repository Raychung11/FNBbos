-- SLV WMS — Migration 008: Add PICK doc-number sequence
-- Run AFTER 007_sales_orders.sql.
-- The Phase 6 pick-list generator allocates a number via next_doc_no('PICK').
-- If you've already configured a custom format in Settings → Document
-- numbering, this INSERT IGNOREs and leaves your row untouched.

INSERT IGNORE INTO document_sequences
  (company_id, doc_type, format_template, current_seq, reset_yearly)
VALUES
  (1, 'PICK', 'PICK/{YY}/{seq:5}', 0, 1);
