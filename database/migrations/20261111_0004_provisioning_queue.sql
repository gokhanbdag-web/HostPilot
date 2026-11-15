CREATE TABLE IF NOT EXISTS provisioning_jobs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                service_id BIGINT UNSIGNED NOT NULL,
                job_type VARCHAR(32) NOT NULL DEFAULT 'create',
                status VARCHAR(24) NOT NULL DEFAULT 'queued',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                available_at DATETIME NOT NULL,
                locked_at DATETIME NULL,
                last_error VARCHAR(1000) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_provision_job_service_type (service_id,job_type),
                KEY idx_provision_jobs_ready (status,available_at,id),
                CONSTRAINT fk_provision_job_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
