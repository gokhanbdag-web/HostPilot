SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_registrations' AND COLUMN_NAME='expiry_date') = 0, 'ALTER TABLE domain_registrations ADD COLUMN expiry_date DATETIME NULL', 'SELECT 1');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_registrations' AND COLUMN_NAME='registrar_auto_renew') = 0, 'ALTER TABLE domain_registrations ADD COLUMN registrar_auto_renew TINYINT(1) NULL', 'SELECT 1');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_registrations' AND COLUMN_NAME='registrar_synced_at') = 0, 'ALTER TABLE domain_registrations ADD COLUMN registrar_synced_at DATETIME NULL', 'SELECT 1');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;
