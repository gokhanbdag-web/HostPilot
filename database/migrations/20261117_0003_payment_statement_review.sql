ALTER TABLE payment_statement_movements
    ADD COLUMN review_status VARCHAR(16) NOT NULL DEFAULT 'pending' AFTER match_status,
    ADD COLUMN reviewed_by BIGINT UNSIGNED NULL AFTER suggested_refund_id,
    ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by,
    ADD COLUMN review_note VARCHAR(500) NOT NULL DEFAULT '' AFTER reviewed_at,
    ADD KEY idx_statement_review_status (review_status, created_at),
    ADD CONSTRAINT fk_statement_review_user FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL;
