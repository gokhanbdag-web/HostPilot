SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_registrations' AND COLUMN_NAME='nameservers_enc') = 0, 'ALTER TABLE domain_registrations ADD COLUMN nameservers_enc LONGTEXT NULL', 'DO 0');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_registrations' AND COLUMN_NAME='pending_nameservers_enc') = 0, 'ALTER TABLE domain_registrations ADD COLUMN pending_nameservers_enc LONGTEXT NULL', 'DO 0');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_registrations' AND COLUMN_NAME='nameserver_status') = 0, 'ALTER TABLE domain_registrations ADD COLUMN nameserver_status VARCHAR(24) NULL', 'DO 0');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_registrations' AND COLUMN_NAME='nameserver_error') = 0, 'ALTER TABLE domain_registrations ADD COLUMN nameserver_error VARCHAR(1000) NULL', 'DO 0');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_registrations' AND COLUMN_NAME='nameserver_updated_at') = 0, 'ALTER TABLE domain_registrations ADD COLUMN nameserver_updated_at DATETIME NULL', 'DO 0');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;
