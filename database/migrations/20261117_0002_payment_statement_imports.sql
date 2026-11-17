CREATE TABLE IF NOT EXISTS payment_statement_imports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(24) NOT NULL,
    original_filename VARCHAR(190) NOT NULL,
    file_sha256 CHAR(64) NOT NULL,
    imported_by BIGINT UNSIGNED NOT NULL,
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_statement_source_file (source, file_sha256),
    KEY idx_statement_imports_created (created_at),
    CONSTRAINT fk_statement_imports_user FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_statement_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    import_id BIGINT UNSIGNED NOT NULL,
    source_row_number INT UNSIGNED NOT NULL,
    occurred_at DATETIME NOT NULL,
    provider_reference VARCHAR(190) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL,
    movement_type VARCHAR(16) NOT NULL DEFAULT 'unknown',
    match_status VARCHAR(24) NOT NULL DEFAULT 'unmatched',
    suggested_payment_transaction_id BIGINT UNSIGNED NULL,
    suggested_refund_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_statement_import_row (import_id, source_row_number),
    KEY idx_statement_reference (provider_reference),
    KEY idx_statement_match_status (match_status, created_at),
    CONSTRAINT fk_statement_movement_import FOREIGN KEY (import_id) REFERENCES payment_statement_imports(id) ON DELETE CASCADE,
    CONSTRAINT fk_statement_movement_payment FOREIGN KEY (suggested_payment_transaction_id) REFERENCES payment_transactions(id) ON DELETE SET NULL,
    CONSTRAINT fk_statement_movement_refund FOREIGN KEY (suggested_refund_id) REFERENCES payment_refunds(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
