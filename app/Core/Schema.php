<?php
declare(strict_types=1);
namespace HostPilot\Core;
use PDO;
use Throwable;

final class Schema
{
    public static function migrate(PDO $db):void
    {
        $name='20261111_0001_catalog_orders_services';
        $q=$db->prepare('SELECT id FROM migrations WHERE migration=:name LIMIT 1');
        $q->execute([':name'=>$name]);
        $catalogDone=(bool)$q->fetchColumn();
        if(!$catalogDone){

        // Idempotent DDL: MySQL/MariaDB may implicitly commit CREATE TABLE statements.
        $statements=[
            "CREATE TABLE IF NOT EXISTS product_groups (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL, slug VARCHAR(150) NOT NULL, description TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active', sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_product_groups_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS products (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                group_id BIGINT UNSIGNED NULL, name VARCHAR(190) NOT NULL, slug VARCHAR(190) NOT NULL,
                description LONGTEXT NULL, product_type VARCHAR(40) NOT NULL DEFAULT 'hosting',
                status VARCHAR(20) NOT NULL DEFAULT 'draft', featured TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_products_slug (slug), KEY idx_products_status_type (status, product_type),
                CONSTRAINT fk_products_group FOREIGN KEY (group_id) REFERENCES product_groups(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS product_pricing (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, product_id BIGINT UNSIGNED NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'TRY', billing_cycle VARCHAR(24) NOT NULL DEFAULT 'monthly',
                price DECIMAL(12,2) NOT NULL DEFAULT 0.00, setup_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_product_price_cycle (product_id,currency,billing_cycle),
                CONSTRAINT fk_product_pricing_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, order_number VARCHAR(30) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'pending',
                currency CHAR(3) NOT NULL DEFAULT 'TRY', subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax_total DECIMAL(12,2) NOT NULL DEFAULT 0.00, total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                notes TEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_orders_number (order_number), KEY idx_orders_user_status (user_id,status),
                CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS order_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NOT NULL, product_name VARCHAR(190) NOT NULL,
                billing_cycle VARCHAR(24) NOT NULL, quantity INT UNSIGNED NOT NULL DEFAULT 1,
                unit_price DECIMAL(12,2) NOT NULL, line_total DECIMAL(12,2) NOT NULL, created_at DATETIME NOT NULL,
                CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
                KEY idx_order_items_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS services (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NOT NULL, order_id BIGINT UNSIGNED NULL, domain VARCHAR(190) NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'pending', billing_cycle VARCHAR(24) NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, next_due_date DATE NULL,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                KEY idx_services_user_status (user_id,status),
                CONSTRAINT fk_services_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
                CONSTRAINT fk_services_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
                CONSTRAINT fk_services_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ];
        foreach($statements as $sql)$db->exec($sql);
        $s=$db->prepare('INSERT INTO migrations(migration,batch,applied_at) VALUES(:name,1,:applied)');
        $s->execute([':name'=>$name,':applied'=>gmdate('Y-m-d H:i:s')]);
        }

        $billingName='20261111_0002_invoices_payments';
        $b=$db->prepare('SELECT id FROM migrations WHERE migration=:name LIMIT 1');
        $b->execute([':name'=>$billingName]);
        if(!$b->fetchColumn()){
            $billingTables=[
                "CREATE TABLE IF NOT EXISTS invoices (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS invoice_items (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, invoice_id BIGINT UNSIGNED NOT NULL,
                    description VARCHAR(255) NOT NULL, quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
                    unit_price DECIMAL(12,2) NOT NULL, tax_rate DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
                    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, line_total DECIMAL(12,2) NOT NULL,
                    created_at DATETIME NOT NULL, KEY idx_invoice_items_invoice (invoice_id),
                    CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS payment_transactions (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            ];
            foreach($billingTables as $sql)$db->exec($sql);
            $record=$db->prepare('INSERT INTO migrations(migration,batch,applied_at) VALUES(:name,2,:applied)');
            $record->execute([':name'=>$billingName,':applied'=>gmdate('Y-m-d H:i:s')]);
        }
    }
}
