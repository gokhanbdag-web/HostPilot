SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_registrations' AND COLUMN_NAME='registrar_sync_attempted_at') = 0, 'ALTER TABLE domain_registrations ADD COLUMN registrar_sync_attempted_at DATETIME NULL', 'SELECT 1');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_registrations' AND COLUMN_NAME='registrar_sync_error') = 0, 'ALTER TABLE domain_registrations ADD COLUMN registrar_sync_error VARCHAR(1000) NULL', 'SELECT 1');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_registrations' AND INDEX_NAME='idx_domain_expiry_sync_queue') = 0, 'ALTER TABLE domain_registrations ADD KEY idx_domain_expiry_sync_queue(status,registrar_sync_attempted_at,locked_at)', 'SELECT 1');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;
