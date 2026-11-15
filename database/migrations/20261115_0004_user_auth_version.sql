SET @hostpilot_auth_version_column = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='auth_version') = 0,
    'ALTER TABLE users ADD COLUMN auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER password_hash',
    'DO 0'
);
PREPARE hostpilot_auth_version_stmt FROM @hostpilot_auth_version_column;
EXECUTE hostpilot_auth_version_stmt;
DEALLOCATE PREPARE hostpilot_auth_version_stmt;
