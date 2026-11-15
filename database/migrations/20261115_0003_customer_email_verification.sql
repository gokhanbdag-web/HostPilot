SET @hostpilot_email_verified_column = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='email_verified_at') = 0,
    'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER status',
    'DO 0'
);
PREPARE hostpilot_email_verified_stmt FROM @hostpilot_email_verified_column;
EXECUTE hostpilot_email_verified_stmt;
DEALLOCATE PREPARE hostpilot_email_verified_stmt;

CREATE TABLE IF NOT EXISTS email_verification_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_verification_token_hash (token_hash),
    KEY idx_email_verification_user_created (user_id, created_at),
    KEY idx_email_verification_expiry (expires_at),
    CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
