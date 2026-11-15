SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND COLUMN_NAME='provisioning_username') = 0, 'ALTER TABLE services ADD COLUMN provisioning_username VARCHAR(120) NULL', 'SELECT 1');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND COLUMN_NAME='provisioning_password_enc') = 0, 'ALTER TABLE services ADD COLUMN provisioning_password_enc TEXT NULL', 'SELECT 1');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;
