CREATE TABLE IF NOT EXISTS staff_permissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    permission VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_permission (user_id, permission),
    KEY idx_staff_permissions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_departments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    department VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_department (user_id, department),
    KEY idx_staff_departments_user (user_id),
    KEY idx_staff_departments_department (department)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO staff_permissions (user_id, permission, created_at)
SELECT id, 'dashboard.view', UTC_TIMESTAMP() FROM users WHERE role='staff';
INSERT IGNORE INTO staff_permissions (user_id, permission, created_at)
SELECT id, 'customers.view', UTC_TIMESTAMP() FROM users WHERE role='staff';
INSERT IGNORE INTO staff_permissions (user_id, permission, created_at)
SELECT id, 'reports.view', UTC_TIMESTAMP() FROM users WHERE role='staff';
INSERT IGNORE INTO staff_permissions (user_id, permission, created_at)
SELECT id, 'support.view', UTC_TIMESTAMP() FROM users WHERE role='staff';
INSERT IGNORE INTO staff_permissions (user_id, permission, created_at)
SELECT id, 'support.reply', UTC_TIMESTAMP() FROM users WHERE role='staff';
INSERT IGNORE INTO staff_permissions (user_id, permission, created_at)
SELECT id, 'catalog.manage', UTC_TIMESTAMP() FROM users WHERE role='staff';
INSERT IGNORE INTO staff_permissions (user_id, permission, created_at)
SELECT id, 'orders.view', UTC_TIMESTAMP() FROM users WHERE role='staff';
INSERT IGNORE INTO staff_permissions (user_id, permission, created_at)
SELECT id, 'invoices.view', UTC_TIMESTAMP() FROM users WHERE role='staff';
INSERT IGNORE INTO staff_departments (user_id, department, created_at)
SELECT id, 'General Support', UTC_TIMESTAMP() FROM users WHERE role='staff';
INSERT IGNORE INTO staff_departments (user_id, department, created_at)
SELECT id, 'Billing', UTC_TIMESTAMP() FROM users WHERE role='staff';
INSERT IGNORE INTO staff_departments (user_id, department, created_at)
SELECT id, 'Technical Support', UTC_TIMESTAMP() FROM users WHERE role='staff';
INSERT IGNORE INTO staff_departments (user_id, department, created_at)
SELECT id, 'Sales', UTC_TIMESTAMP() FROM users WHERE role='staff';
