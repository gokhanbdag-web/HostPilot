SET @hostpilot_migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA=DATABASE()
       AND TABLE_NAME='invoices'
       AND CONSTRAINT_NAME='fk_invoice_service') = 0,
    'ALTER TABLE invoices ADD CONSTRAINT fk_invoice_service FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE SET NULL',
    'DO 0'
);
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;
