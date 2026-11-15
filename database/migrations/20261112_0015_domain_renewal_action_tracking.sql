SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_renewals' AND COLUMN_NAME='action_eaq_id') = 0, 'ALTER TABLE domain_renewals ADD COLUMN action_eaq_id VARCHAR(40) NULL', 'SELECT 1');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_renewals' AND COLUMN_NAME='action_type') = 0, 'ALTER TABLE domain_renewals ADD COLUMN action_type VARCHAR(80) NULL', 'SELECT 1');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

UPDATE domain_renewals SET status='needs_review',locked_at=NULL,last_error='Önceki worker sürümünde registrar işlem kimliği saklanmadı; registrar panelinde doğrulama gerekli.',updated_at=UTC_TIMESTAMP() WHERE status='pending_remote' AND action_eaq_id IS NULL;
