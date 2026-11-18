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
        $s=$db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,1,:applied)');
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS payment_reconciliations (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    payment_transaction_id BIGINT UNSIGNED NOT NULL,
                    admin_user_id BIGINT UNSIGNED NOT NULL,
                    decision VARCHAR(40) NOT NULL,
                    provider_reference VARCHAR(190) NOT NULL,
                    amount DECIMAL(12,2) NOT NULL,
                    currency CHAR(3) NOT NULL,
                    reason VARCHAR(500) NOT NULL,
                    created_at DATETIME NOT NULL,
                    UNIQUE KEY uq_payment_reconciliation_transaction_decision (payment_transaction_id,decision),
                    KEY idx_payment_reconciliations_admin (admin_user_id,created_at),
                    CONSTRAINT fk_payment_reconciliations_transaction FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions(id) ON DELETE CASCADE,
                    CONSTRAINT fk_payment_reconciliations_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            ];
            foreach($billingTables as $sql)$db->exec($sql);
            $record=$db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,2,:applied)');
            $record->execute([':name'=>$billingName,':applied'=>gmdate('Y-m-d H:i:s')]);
        }

        $hostingName='20261111_0003_hosting_servers';
        $h=$db->prepare('SELECT id FROM migrations WHERE migration=:name LIMIT 1');
        $h->execute([':name'=>$hostingName]);
        if(!$h->fetchColumn()){
            $hostingTables=[
                "CREATE TABLE IF NOT EXISTS servers (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(120) NOT NULL, panel_type VARCHAR(24) NOT NULL,
                    base_url VARCHAR(255) NOT NULL, username VARCHAR(120) NOT NULL,
                    secret_enc TEXT NOT NULL, auth_mode VARCHAR(20) NOT NULL DEFAULT 'password',
                    shared_ip VARCHAR(45) NULL, enabled TINYINT(1) NOT NULL DEFAULT 1,
                    last_test_at DATETIME NULL, last_test_status VARCHAR(24) NULL,
                    last_test_message VARCHAR(500) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                    KEY idx_servers_panel_enabled (panel_type,enabled)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS server_product_map (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    server_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL,
                    remote_package VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                    UNIQUE KEY uq_server_product (server_id,product_id),
                    CONSTRAINT fk_server_map_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
                    CONSTRAINT fk_server_map_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS provisioning_logs (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    service_id BIGINT UNSIGNED NOT NULL, server_id BIGINT UNSIGNED NULL,
                    action VARCHAR(40) NOT NULL, status VARCHAR(24) NOT NULL,
                    message VARCHAR(1000) NULL, created_at DATETIME NOT NULL,
                    KEY idx_provisioning_logs_service (service_id,created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            ];
            foreach($hostingTables as $sql)$db->exec($sql);
            $columns=[
                'server_id'=>"BIGINT UNSIGNED NULL",
                'remote_identifier'=>"VARCHAR(190) NULL",
                'remote_username'=>"VARCHAR(120) NULL",
                'remote_domain'=>"VARCHAR(190) NULL",
                'remote_password_enc'=>"TEXT NULL",
                'provisioning_status'=>"VARCHAR(24) NOT NULL DEFAULT 'not_started'",
                'provisioning_error'=>"VARCHAR(1000) NULL",
                'provisioned_at'=>"DATETIME NULL"
            ];
            foreach($columns as $column=>$definition){
                $check=$db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND COLUMN_NAME=:column");
                $check->execute([':column'=>$column]);
                if(!(int)$check->fetchColumn())$db->exec("ALTER TABLE services ADD COLUMN ".$column." ".$definition);
            }
            $fk=$db->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='services' AND CONSTRAINT_NAME='fk_services_server'")->fetchColumn();
            if(!(int)$fk)$db->exec("ALTER TABLE services ADD CONSTRAINT fk_services_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE SET NULL");
            $record=$db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,3,:applied)');
            $record->execute([':name'=>$hostingName,':applied'=>gmdate('Y-m-d H:i:s')]);
        }

        $queueName='20261111_0004_provisioning_queue';
        $q4=$db->prepare('SELECT id FROM migrations WHERE migration=:name LIMIT 1');
        $q4->execute([':name'=>$queueName]);
        if(!$q4->fetchColumn()){
            $db->exec("CREATE TABLE IF NOT EXISTS provisioning_jobs (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $record=$db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,4,:applied)');
            $record->execute([':name'=>$queueName,':applied'=>gmdate('Y-m-d H:i:s')]);
        }

        $billingLifecycleName='20261111_0005_service_billing_lifecycle';
        $bl=$db->prepare('SELECT id FROM migrations WHERE migration=:name LIMIT 1');
        $bl->execute([':name'=>$billingLifecycleName]);
        if(!$bl->fetchColumn()){
            $addColumn=static function(string $table,string $column,string $definition)use($db):void{
                $check=$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND COLUMN_NAME=:column_name');
                $check->execute([':table_name'=>$table,':column_name'=>$column]);
                if(!(int)$check->fetchColumn())$db->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition);
            };
            $addColumn('invoices','service_id','BIGINT UNSIGNED NULL');
            $addColumn('invoices','service_period_start','DATE NULL');
            $addColumn('services','suspension_source',"VARCHAR(24) NOT NULL DEFAULT 'none'");
            $addColumn('services','suspension_invoice_id','BIGINT UNSIGNED NULL');
            $addColumn('services','suspended_at','DATETIME NULL');
            $invoiceIndex=$db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='uq_invoice_service_period'");
            if(!(int)$invoiceIndex->fetchColumn())$db->exec('ALTER TABLE invoices ADD UNIQUE KEY uq_invoice_service_period(service_id,service_period_start)');
            $serviceIndex=$db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='idx_invoice_service_due'");
            if(!(int)$serviceIndex->fetchColumn())$db->exec('ALTER TABLE invoices ADD KEY idx_invoice_service_due(service_id,status,due_at)');
            $fk=$db->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND CONSTRAINT_NAME='fk_invoice_service'");
            $fk->execute();
            if(!(int)$fk->fetchColumn())$db->exec('ALTER TABLE invoices ADD CONSTRAINT fk_invoice_service FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE SET NULL');
            $record=$db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,5,:applied)');
            $record->execute([':name'=>$billingLifecycleName,':applied'=>gmdate('Y-m-d H:i:s')]);
        }

        // Keep each migration independently reachable on already-upgraded installations.
        $provisioningIntentName='20261111_0006_provisioning_intent';
        $pi=$db->prepare('SELECT id FROM migrations WHERE migration=:name LIMIT 1');
        $pi->execute([':name'=>$provisioningIntentName]);
        if(!$pi->fetchColumn()){
            $addIntentColumn=static function(string $column,string $definition)use($db):void{
                $check=$db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND COLUMN_NAME=:column");
                $check->execute([':column'=>$column]);
                if(!(int)$check->fetchColumn())$db->exec('ALTER TABLE services ADD COLUMN '.$column.' '.$definition);
            };
            $addIntentColumn('provisioning_username','VARCHAR(120) NULL');
            $addIntentColumn('provisioning_password_enc','TEXT NULL');
            $record=$db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,6,:applied)');
            $record->execute([':name'=>$provisioningIntentName,':applied'=>gmdate('Y-m-d H:i:s')]);
        }

        $packageMappingName='20261111_0007_remote_package_identifiers';
        $pm=$db->prepare('SELECT id FROM migrations WHERE migration=:name LIMIT 1');
        $pm->execute([':name'=>$packageMappingName]);
        if(!$pm->fetchColumn()){
            $addMapColumn=static function(string $column,string $definition)use($db):void{
                $check=$db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='server_product_map' AND COLUMN_NAME=:column");
                $check->execute([':column'=>$column]);
                if(!(int)$check->fetchColumn())$db->exec('ALTER TABLE server_product_map ADD COLUMN '.$column.' '.$definition);
            };
            $addMapColumn('remote_package_guid','VARCHAR(190) NULL');
            $addMapColumn('remote_package_external_id','VARCHAR(190) NULL');
            $record=$db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,7,:applied)');
            $record->execute([':name'=>$packageMappingName,':applied'=>gmdate('Y-m-d H:i:s')]);
        }
        $packageChangeName='20261112_0008_service_package_changes';
        $pc=$db->prepare('SELECT id FROM migrations WHERE migration=:name LIMIT 1');
        $pc->execute([':name'=>$packageChangeName]);
        if(!$pc->fetchColumn()){
            $db->exec("CREATE TABLE IF NOT EXISTS service_package_changes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                service_id BIGINT UNSIGNED NOT NULL,
                from_product_id BIGINT UNSIGNED NOT NULL,
                to_product_id BIGINT UNSIGNED NOT NULL,
                invoice_id BIGINT UNSIGNED NULL,
                target_package VARCHAR(190) NOT NULL,
                target_package_guid VARCHAR(190) NULL,
                target_package_external_id VARCHAR(190) NULL,
                target_amount DECIMAL(12,2) NOT NULL,
                charge_amount DECIMAL(12,2) NOT NULL,
                currency CHAR(3) NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'pending_payment',
                effective_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_package_change_service_status(service_id,status),
                KEY idx_package_change_invoice(invoice_id),
                CONSTRAINT fk_package_change_service FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE RESTRICT,
                CONSTRAINT fk_package_change_from FOREIGN KEY(from_product_id) REFERENCES products(id) ON DELETE RESTRICT,
                CONSTRAINT fk_package_change_to FOREIGN KEY(to_product_id) REFERENCES products(id) ON DELETE RESTRICT,
                CONSTRAINT fk_package_change_invoice FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $record=$db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,8,:applied)');
            $record->execute([':name'=>$packageChangeName,':applied'=>gmdate('Y-m-d H:i:s')]);
        }

        $packageChangeTypeName='20261112_0009_service_package_change_type';
        $pct=$db->prepare('SELECT id FROM migrations WHERE migration=:name LIMIT 1');
        $pct->execute([':name'=>$packageChangeTypeName]);
        if(!$pct->fetchColumn()){
            $column=$db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_package_changes' AND COLUMN_NAME='change_type'");
            if(!(int)$column->fetchColumn())$db->exec("ALTER TABLE service_package_changes ADD COLUMN change_type VARCHAR(16) NOT NULL DEFAULT 'upgrade' AFTER service_id");
            $record=$db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,9,:applied)');
            $record->execute([':name'=>$packageChangeTypeName,':applied'=>gmdate('Y-m-d H:i:s')]);
        }

        $emailOutboxName='20261112_0010_email_outbox';
        $eo=$db->prepare('SELECT id FROM migrations WHERE migration=:name LIMIT 1');
        $eo->execute([':name'=>$emailOutboxName]);
        if(!$eo->fetchColumn()){
            $db->exec("CREATE TABLE IF NOT EXISTS email_outbox (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $record=$db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,10,:applied)');
            $record->execute([':name'=>$emailOutboxName,':applied'=>gmdate('Y-m-d H:i:s')]);
        }

        $domainRegistrationName='20261112_0011_domain_registration_queue';
        $dr=$db->prepare('SELECT id FROM migrations WHERE migration=:name LIMIT 1');
        $dr->execute([':name'=>$domainRegistrationName]);
        if(!$dr->fetchColumn()){
            $db->exec("CREATE TABLE IF NOT EXISTS domain_registrations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                order_id BIGINT UNSIGNED NOT NULL,
                invoice_id BIGINT UNSIGNED NOT NULL,
                domain_name VARCHAR(253) NOT NULL,
                years TINYINT UNSIGNED NOT NULL DEFAULT 1,
                registrar_customer_id VARCHAR(40) NULL,
                registrar_contact_id VARCHAR(40) NULL,
                registrar_order_id VARCHAR(40) NULL,
                registrar_action_id VARCHAR(40) NULL,
                registrar_payload_enc LONGTEXT NOT NULL,
                idempotency_key CHAR(64) NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'awaiting_payment',
                action_status VARCHAR(80) NULL,
                action_message VARCHAR(500) NULL,
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                available_at DATETIME NOT NULL,
                locked_at DATETIME NULL,
                last_error VARCHAR(1000) NULL,
                registered_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_domain_registration_order(order_id),
                UNIQUE KEY uq_domain_registration_idempotency(idempotency_key),
                KEY idx_domain_registration_queue(status,available_at,id),
                KEY idx_domain_registration_user(user_id,created_at),
                UNIQUE KEY uq_domain_registration_name(domain_name),
                CONSTRAINT fk_domain_registration_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
                CONSTRAINT fk_domain_registration_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT,
                CONSTRAINT fk_domain_registration_invoice FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $record=$db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,11,:applied)');
            $record->execute([':name'=>$domainRegistrationName,':applied'=>gmdate('Y-m-d H:i:s')]);
        }

        $domainNameserverName='20261112_0012_domain_nameserver_queue';
        $dns=$db->prepare('SELECT id FROM migrations WHERE migration=:name LIMIT 1');
        $dns->execute([':name'=>$domainNameserverName]);
        if(!$dns->fetchColumn()){
            $addDomainColumn=static function(string $column,string $definition)use($db):void{
                $check=$db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_registrations' AND COLUMN_NAME=:column");
                $check->execute([':column'=>$column]);
                if(!(int)$check->fetchColumn())$db->exec('ALTER TABLE domain_registrations ADD COLUMN '.$column.' '.$definition);
            };
            $addDomainColumn('nameservers_enc','LONGTEXT NULL');
            $addDomainColumn('pending_nameservers_enc','LONGTEXT NULL');
            $addDomainColumn('nameserver_status',"VARCHAR(24) NULL");
            $addDomainColumn('nameserver_error','VARCHAR(1000) NULL');
            $addDomainColumn('nameserver_updated_at','DATETIME NULL');
            $record=$db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,12,:applied)');
            $record->execute([':name'=>$domainNameserverName,':applied'=>gmdate('Y-m-d H:i:s')]);
        }

        $domainExpiryName='20261112_0013_domain_expiry_sync';
        $de=$db->prepare('SELECT id FROM migrations WHERE migration=:name LIMIT 1');
        $de->execute([':name'=>$domainExpiryName]);
        if(!$de->fetchColumn()){
            $addExpiryColumn=static function(string $column,string $definition)use($db):void{
                $check=$db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_registrations' AND COLUMN_NAME=:column");
                $check->execute([':column'=>$column]);
                if(!(int)$check->fetchColumn())$db->exec('ALTER TABLE domain_registrations ADD COLUMN '.$column.' '.$definition);
            };
            $addExpiryColumn('expiry_date','DATETIME NULL');
            $addExpiryColumn('registrar_auto_renew','TINYINT(1) NULL');
            $addExpiryColumn('registrar_synced_at','DATETIME NULL');
            $record=$db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,13,:applied)');
            $record->execute([':name'=>$domainExpiryName,':applied'=>gmdate('Y-m-d H:i:s')]);
        }

        $domainRenewalName='20261112_0014_domain_renewal_queue';
        $drn=$db->prepare('SELECT id FROM migrations WHERE migration=:name LIMIT 1');
        $drn->execute([':name'=>$domainRenewalName]);
        if(!$drn->fetchColumn()){
            $db->exec("CREATE TABLE IF NOT EXISTS domain_renewals (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                domain_registration_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                order_id BIGINT UNSIGNED NOT NULL,
                invoice_id BIGINT UNSIGNED NOT NULL,
                years TINYINT UNSIGNED NOT NULL,
                registrar_order_id VARCHAR(40) NOT NULL,
                expiry_before DATETIME NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'awaiting_payment',
                action_status VARCHAR(80) NULL,
                last_error VARCHAR(1000) NULL,
                idempotency_key CHAR(64) NOT NULL,
                available_at DATETIME NOT NULL,
                locked_at DATETIME NULL,
                renewed_expiry DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_domain_renewal_order(order_id),
                UNIQUE KEY uq_domain_renewal_idempotency(idempotency_key),
                KEY idx_domain_renewal_queue(status,available_at,id),
                KEY idx_domain_renewal_domain(domain_registration_id,created_at),
                CONSTRAINT fk_domain_renewal_domain FOREIGN KEY(domain_registration_id) REFERENCES domain_registrations(id) ON DELETE RESTRICT,
                CONSTRAINT fk_domain_renewal_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
                CONSTRAINT fk_domain_renewal_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT,
                CONSTRAINT fk_domain_renewal_invoice FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $record=$db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,14,:applied)');
            $record->execute([':name'=>$domainRenewalName,':applied'=>gmdate('Y-m-d H:i:s')]);
        }

        $domainRenewalActionName='20261112_0015_domain_renewal_action_tracking';
        $dran=$db->prepare('SELECT id FROM migrations WHERE migration=:name LIMIT 1');
        $dran->execute([':name'=>$domainRenewalActionName]);
        if(!$dran->fetchColumn()){
            $addRenewalColumn=static function(string $column,string $definition)use($db):void{
                $check=$db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_renewals' AND COLUMN_NAME=:column");
                $check->execute([':column'=>$column]);
                if(!(int)$check->fetchColumn())$db->exec('ALTER TABLE domain_renewals ADD COLUMN '.$column.' '.$definition);
            };
            $addRenewalColumn('action_eaq_id','VARCHAR(40) NULL');
            $addRenewalColumn('action_type','VARCHAR(80) NULL');
            $db->exec("UPDATE domain_renewals SET status='needs_review',locked_at=NULL,last_error='Önceki worker sürümünde registrar işlem kimliği saklanmadı; registrar panelinde doğrulama gerekli.',updated_at=UTC_TIMESTAMP() WHERE status='pending_remote' AND action_eaq_id IS NULL");
            $record=$db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,15,:applied)');
            $record->execute([':name'=>$domainRenewalActionName,':applied'=>gmdate('Y-m-d H:i:s')]);
        }


        $domainExpiryReminderName='20261112_0016_domain_expiry_reminder_index';
        $deri=$db->prepare('SELECT id FROM migrations WHERE migration=:name LIMIT 1');
        $deri->execute([':name'=>$domainExpiryReminderName]);
        if(!$deri->fetchColumn()){
            $index=$db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_registrations' AND INDEX_NAME='idx_domain_expiry_reminders'");
            if(!(int)$index->fetchColumn())$db->exec('ALTER TABLE domain_registrations ADD KEY idx_domain_expiry_reminders(status,expiry_date,registrar_synced_at)');
            $record=$db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,16,:applied)');
            $record->execute([':name'=>$domainExpiryReminderName,':applied'=>gmdate('Y-m-d H:i:s')]);
        }
        $domainSyncQueueName='20261112_0017_domain_expiry_sync_queue';
        $dsq=$db->prepare('SELECT id FROM migrations WHERE migration=:name LIMIT 1');
        $dsq->execute([':name'=>$domainSyncQueueName]);
        if(!$dsq->fetchColumn()){
            $addSyncColumn=static function(string $column,string $definition)use($db):void{
                $check=$db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_registrations' AND COLUMN_NAME=:column");
                $check->execute([':column'=>$column]);
                if(!(int)$check->fetchColumn())$db->exec('ALTER TABLE domain_registrations ADD COLUMN '.$column.' '.$definition);
            };
            $addSyncColumn('registrar_sync_attempted_at','DATETIME NULL');
            $addSyncColumn('registrar_sync_error','VARCHAR(1000) NULL');
            $index=$db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='domain_registrations' AND INDEX_NAME='idx_domain_expiry_sync_queue'");
            if(!(int)$index->fetchColumn())$db->exec('ALTER TABLE domain_registrations ADD KEY idx_domain_expiry_sync_queue(status,registrar_sync_attempted_at,locked_at)');
            $record=$db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,17,:applied)');
            $record->execute([':name'=>$domainSyncQueueName,':applied'=>gmdate('Y-m-d H:i:s')]);
        }


        // Forward repair: the legacy billing-lifecycle migration did not execute
        // its foreign-key existence query before casting the statement to int.
        // Keep the historical migration immutable and repair the intended FK here.
        $invoiceServiceFkRepair = '20261115_0001_invoice_service_fk_repair';
        $repairCheck = $db->prepare('SELECT id FROM migrations WHERE migration=:name LIMIT 1');
        $repairCheck->execute([':name' => $invoiceServiceFkRepair]);
        if (!$repairCheck->fetchColumn()) {
            $foreignKey = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND CONSTRAINT_NAME='fk_invoice_service'");
            $foreignKey->execute();
            if (!(int)$foreignKey->fetchColumn()) {
                $db->exec('ALTER TABLE invoices ADD CONSTRAINT fk_invoice_service FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE SET NULL');
            }
            $record = $db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,18,:applied)');
            $record->execute([':name' => $invoiceServiceFkRepair, ':applied' => gmdate('Y-m-d H:i:s')]);
        }

        // Keep the retained legacy schema path aligned with the SQL migration schema.
        $authVersionColumn = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='auth_version'");
        $authVersionColumn->execute();
        if (!(int)$authVersionColumn->fetchColumn()) {
            $db->exec('ALTER TABLE users ADD COLUMN auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER password_hash');
        }


        $db->exec("CREATE TABLE IF NOT EXISTS payment_refunds (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            payment_transaction_id BIGINT UNSIGNED NOT NULL,
            admin_user_id BIGINT UNSIGNED NOT NULL,
            provider_reference VARCHAR(190) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            currency CHAR(3) NOT NULL,
            reason VARCHAR(500) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_payment_refund_provider_reference (payment_transaction_id, provider_reference),
            KEY idx_payment_refunds_created (created_at),
            KEY idx_payment_refunds_admin (admin_user_id, created_at),
            CONSTRAINT fk_payment_refunds_transaction FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions(id) ON DELETE CASCADE,
            CONSTRAINT fk_payment_refunds_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS payment_statement_imports (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS payment_statement_movements (
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
            review_status VARCHAR(16) NOT NULL DEFAULT 'pending',
            suggested_payment_transaction_id BIGINT UNSIGNED NULL,
            suggested_refund_id BIGINT UNSIGNED NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            review_note VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_statement_import_row (import_id, source_row_number),
            KEY idx_statement_reference (provider_reference),
            KEY idx_statement_match_status (match_status, created_at),
            KEY idx_statement_review_status (review_status, created_at),
            CONSTRAINT fk_statement_movement_import FOREIGN KEY (import_id) REFERENCES payment_statement_imports(id) ON DELETE CASCADE,
            CONSTRAINT fk_statement_movement_payment FOREIGN KEY (suggested_payment_transaction_id) REFERENCES payment_transactions(id) ON DELETE SET NULL,
            CONSTRAINT fk_statement_movement_refund FOREIGN KEY (suggested_refund_id) REFERENCES payment_refunds(id) ON DELETE SET NULL,
            CONSTRAINT fk_statement_review_user FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


    }
}
