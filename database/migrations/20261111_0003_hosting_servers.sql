CREATE TABLE IF NOT EXISTS servers (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(120) NOT NULL, panel_type VARCHAR(24) NOT NULL,
                    base_url VARCHAR(255) NOT NULL, username VARCHAR(120) NOT NULL,
                    secret_enc TEXT NOT NULL, auth_mode VARCHAR(20) NOT NULL DEFAULT 'password',
                    shared_ip VARCHAR(45) NULL, enabled TINYINT(1) NOT NULL DEFAULT 1,
                    last_test_at DATETIME NULL, last_test_status VARCHAR(24) NULL,
                    last_test_message VARCHAR(500) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                    KEY idx_servers_panel_enabled (panel_type,enabled)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS server_product_map (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    server_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL,
                    remote_package VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                    UNIQUE KEY uq_server_product (server_id,product_id),
                    CONSTRAINT fk_server_map_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
                    CONSTRAINT fk_server_map_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provisioning_logs (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    service_id BIGINT UNSIGNED NOT NULL, server_id BIGINT UNSIGNED NULL,
                    action VARCHAR(40) NOT NULL, status VARCHAR(24) NOT NULL,
                    message VARCHAR(1000) NULL, created_at DATETIME NOT NULL,
                    KEY idx_provisioning_logs_service (service_id,created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND COLUMN_NAME='server_id') = 0, 'ALTER TABLE services ADD COLUMN server_id BIGINT UNSIGNED NULL', 'DO 0');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND COLUMN_NAME='remote_identifier') = 0, 'ALTER TABLE services ADD COLUMN remote_identifier VARCHAR(190) NULL', 'DO 0');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND COLUMN_NAME='remote_username') = 0, 'ALTER TABLE services ADD COLUMN remote_username VARCHAR(120) NULL', 'DO 0');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND COLUMN_NAME='remote_domain') = 0, 'ALTER TABLE services ADD COLUMN remote_domain VARCHAR(190) NULL', 'DO 0');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND COLUMN_NAME='remote_password_enc') = 0, 'ALTER TABLE services ADD COLUMN remote_password_enc TEXT NULL', 'DO 0');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND COLUMN_NAME='provisioning_status') = 0, 'ALTER TABLE services ADD COLUMN provisioning_status VARCHAR(24) NOT NULL DEFAULT ''not_started''', 'DO 0');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND COLUMN_NAME='provisioning_error') = 0, 'ALTER TABLE services ADD COLUMN provisioning_error VARCHAR(1000) NULL', 'DO 0');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND COLUMN_NAME='provisioned_at') = 0, 'ALTER TABLE services ADD COLUMN provisioned_at DATETIME NULL', 'DO 0');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;

SET @hostpilot_migration_sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='services' AND CONSTRAINT_NAME='fk_services_server') = 0, 'ALTER TABLE services ADD CONSTRAINT fk_services_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE SET NULL', 'DO 0');
PREPARE hostpilot_migration_stmt FROM @hostpilot_migration_sql;
EXECUTE hostpilot_migration_stmt;
DEALLOCATE PREPARE hostpilot_migration_stmt;
