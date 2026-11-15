SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='first_name') = 0, 'ALTER TABLE users ADD COLUMN first_name VARCHAR(80) NULL', 'DO 0');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='last_name') = 0, 'ALTER TABLE users ADD COLUMN last_name VARCHAR(80) NULL', 'DO 0');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

UPDATE users
SET first_name = CASE
    WHEN first_name IS NULL OR first_name = '' THEN SUBSTRING_INDEX(name, ' ', 1)
    ELSE first_name
END,
last_name = CASE
    WHEN last_name IS NULL THEN
        CASE WHEN LOCATE(' ', name) > 0 THEN TRIM(SUBSTRING(name, LOCATE(' ', name) + 1)) ELSE '' END
    ELSE last_name
END
WHERE first_name IS NULL OR first_name = '' OR last_name IS NULL;
