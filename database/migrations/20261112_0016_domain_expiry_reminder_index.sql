SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_registrations' AND INDEX_NAME='idx_domain_expiry_reminders') = 0, 'ALTER TABLE domain_registrations ADD KEY idx_domain_expiry_reminders(status,expiry_date,registrar_synced_at)', 'DO 0');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;
