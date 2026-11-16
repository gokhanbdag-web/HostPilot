CREATE TABLE IF NOT EXISTS payment_reconciliations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payment_transaction_id BIGINT UNSIGNED NOT NULL,
    admin_user_id BIGINT UNSIGNED NOT NULL,
    decision VARCHAR(40) NOT NULL,
    provider_reference VARCHAR(190) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_payment_reconciliation_transaction_decision (payment_transaction_id, decision),
    KEY idx_payment_reconciliations_admin (admin_user_id, created_at),
    CONSTRAINT fk_payment_reconciliations_transaction FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions(id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_reconciliations_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
