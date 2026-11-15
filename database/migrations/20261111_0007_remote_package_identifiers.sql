SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='server_product_map' AND COLUMN_NAME='remote_package_guid') = 0, 'ALTER TABLE server_product_map ADD COLUMN remote_package_guid VARCHAR(190) NULL', 'DO 0');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='server_product_map' AND COLUMN_NAME='remote_package_external_id') = 0, 'ALTER TABLE server_product_map ADD COLUMN remote_package_external_id VARCHAR(190) NULL', 'DO 0');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;
