CREATE TABLE IF NOT EXISTS email_outbox (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                idempotency_key CHAR(64) NOT NULL,
                event_type VARCHAR(64) NOT NULL DEFAULT 'general',
                recipient VARCHAR(254) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body LONGTEXT NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'pending',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                available_at DATETIME NOT NULL,
                locked_at DATETIME NULL,
                last_error VARCHAR(1000) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                sent_at DATETIME NULL,
                UNIQUE KEY uq_email_outbox_idempotency(idempotency_key),
                KEY idx_email_outbox_ready(status,available_at,id),
                KEY idx_email_outbox_created(created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
