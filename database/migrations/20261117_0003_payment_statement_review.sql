SET @hp_review_status_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_statement_movements' AND COLUMN_NAME='review_status');
SET @hp_review_status_sql = IF(@hp_review_status_exists=0,'ALTER TABLE payment_statement_movements ADD COLUMN review_status VARCHAR(16) NOT NULL DEFAULT ''pending'' AFTER match_status','SET @hp_statement_review_noop = 1');
PREPARE hp_statement_review_stmt FROM @hp_review_status_sql;
EXECUTE hp_statement_review_stmt;
DEALLOCATE PREPARE hp_statement_review_stmt;

SET @hp_reviewed_by_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_statement_movements' AND COLUMN_NAME='reviewed_by');
SET @hp_reviewed_by_sql = IF(@hp_reviewed_by_exists=0,'ALTER TABLE payment_statement_movements ADD COLUMN reviewed_by BIGINT UNSIGNED NULL AFTER suggested_refund_id','SET @hp_statement_review_noop = 1');
PREPARE hp_statement_review_stmt FROM @hp_reviewed_by_sql;
EXECUTE hp_statement_review_stmt;
DEALLOCATE PREPARE hp_statement_review_stmt;

SET @hp_reviewed_at_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_statement_movements' AND COLUMN_NAME='reviewed_at');
SET @hp_reviewed_at_sql = IF(@hp_reviewed_at_exists=0,'ALTER TABLE payment_statement_movements ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by','SET @hp_statement_review_noop = 1');
PREPARE hp_statement_review_stmt FROM @hp_reviewed_at_sql;
EXECUTE hp_statement_review_stmt;
DEALLOCATE PREPARE hp_statement_review_stmt;

SET @hp_review_note_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_statement_movements' AND COLUMN_NAME='review_note');
SET @hp_review_note_sql = IF(@hp_review_note_exists=0,'ALTER TABLE payment_statement_movements ADD COLUMN review_note VARCHAR(500) NOT NULL DEFAULT '''' AFTER reviewed_at','SET @hp_statement_review_noop = 1');
PREPARE hp_statement_review_stmt FROM @hp_review_note_sql;
EXECUTE hp_statement_review_stmt;
DEALLOCATE PREPARE hp_statement_review_stmt;

SET @hp_review_index_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_statement_movements' AND INDEX_NAME='idx_statement_review_status');
SET @hp_review_index_sql = IF(@hp_review_index_exists=0,'ALTER TABLE payment_statement_movements ADD KEY idx_statement_review_status (review_status, created_at)','SET @hp_statement_review_noop = 1');
PREPARE hp_statement_review_stmt FROM @hp_review_index_sql;
EXECUTE hp_statement_review_stmt;
DEALLOCATE PREPARE hp_statement_review_stmt;

SET @hp_review_fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='payment_statement_movements' AND CONSTRAINT_NAME='fk_statement_review_user');
SET @hp_review_fk_sql = IF(@hp_review_fk_exists=0,'ALTER TABLE payment_statement_movements ADD CONSTRAINT fk_statement_review_user FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL','SET @hp_statement_review_noop = 1');
PREPARE hp_statement_review_stmt FROM @hp_review_fk_sql;
EXECUTE hp_statement_review_stmt;
DEALLOCATE PREPARE hp_statement_review_stmt;
