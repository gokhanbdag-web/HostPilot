SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='service_id') = 0, 'ALTER TABLE invoices ADD COLUMN service_id BIGINT UNSIGNED NULL', 'SELECT 1');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='service_period_start') = 0, 'ALTER TABLE invoices ADD COLUMN service_period_start DATE NULL', 'SELECT 1');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND COLUMN_NAME='suspension_source') = 0, 'ALTER TABLE services ADD COLUMN suspension_source VARCHAR(24) NOT NULL DEFAULT ''none''', 'SELECT 1');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND COLUMN_NAME='suspension_invoice_id') = 0, 'ALTER TABLE services ADD COLUMN suspension_invoice_id BIGINT UNSIGNED NULL', 'SELECT 1');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND COLUMN_NAME='suspended_at') = 0, 'ALTER TABLE services ADD COLUMN suspended_at DATETIME NULL', 'SELECT 1');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='uq_invoice_service_period') = 0, 'ALTER TABLE invoices ADD UNIQUE KEY uq_invoice_service_period(service_id,service_period_start)', 'SELECT 1');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='idx_invoice_service_due') = 0, 'ALTER TABLE invoices ADD KEY idx_invoice_service_due(service_id,status,due_at)', 'SELECT 1');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND CONSTRAINT_NAME='fk_invoice_service') = 0, 'ALTER TABLE invoices ADD CONSTRAINT fk_invoice_service FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;
