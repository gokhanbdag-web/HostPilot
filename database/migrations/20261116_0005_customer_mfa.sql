CREATE TABLE IF NOT EXISTS customer_mfa (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    secret_enc VARCHAR(255) NOT NULL,
    enabled_at DATETIME NULL,
    last_used_step BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_customer_mfa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_mfa_recovery_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    UNIQUE KEY uq_customer_mfa_recovery_user_hash (user_id, code_hash),
    KEY idx_customer_mfa_recovery_user_used (user_id, used_at),
    CONSTRAINT fk_customer_mfa_recovery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
