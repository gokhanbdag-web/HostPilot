CREATE TABLE IF NOT EXISTS invoices (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, invoice_number VARCHAR(30) NOT NULL,
                    order_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
                    status VARCHAR(24) NOT NULL DEFAULT 'unpaid', currency CHAR(3) NOT NULL DEFAULT 'TRY',
                    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00, tax_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    total DECIMAL(12,2) NOT NULL DEFAULT 0.00, amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    issued_at DATETIME NOT NULL, due_at DATETIME NULL, paid_at DATETIME NULL,
                    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                    UNIQUE KEY uq_invoices_number (invoice_number), UNIQUE KEY uq_invoices_order (order_id),
                    KEY idx_invoices_user_status (user_id,status),
                    CONSTRAINT fk_invoices_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
                    CONSTRAINT fk_invoices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_items (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, invoice_id BIGINT UNSIGNED NOT NULL,
                    description VARCHAR(255) NOT NULL, quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
                    unit_price DECIMAL(12,2) NOT NULL, tax_rate DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
                    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, line_total DECIMAL(12,2) NOT NULL,
                    created_at DATETIME NOT NULL, KEY idx_invoice_items_invoice (invoice_id),
                    CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_transactions (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, invoice_id BIGINT UNSIGNED NOT NULL,
                    user_id BIGINT UNSIGNED NOT NULL, gateway VARCHAR(40) NOT NULL,
                    gateway_transaction_id VARCHAR(190) NULL, idempotency_key VARCHAR(100) NOT NULL,
                    status VARCHAR(24) NOT NULL DEFAULT 'pending', currency CHAR(3) NOT NULL DEFAULT 'TRY',
                    amount DECIMAL(12,2) NOT NULL, request_reference VARCHAR(190) NULL,
                    response_code VARCHAR(100) NULL, safe_message VARCHAR(500) NULL,
                    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                    UNIQUE KEY uq_payment_idempotency (idempotency_key),
                    KEY idx_payment_invoice_status (invoice_id,status),
                    KEY idx_payment_gateway_txn (gateway,gateway_transaction_id),
                    CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
                    CONSTRAINT fk_payment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
