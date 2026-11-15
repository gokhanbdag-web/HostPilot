CREATE TABLE IF NOT EXISTS security_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    event_key VARCHAR(80) NOT NULL,
    ip_hash CHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL,
    KEY idx_security_audit_user_created (user_id, created_at),
    KEY idx_security_audit_event_created (event_key, created_at),
    CONSTRAINT fk_security_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
