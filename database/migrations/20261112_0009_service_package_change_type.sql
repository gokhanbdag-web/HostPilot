SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_package_changes' AND COLUMN_NAME='change_type') = 0, 'ALTER TABLE service_package_changes ADD COLUMN change_type VARCHAR(16) NOT NULL DEFAULT ''upgrade'' AFTER service_id', 'SELECT 1');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;
