CREATE TABLE IF NOT EXISTS support_departments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_support_departments_name (name),
    KEY idx_support_departments_status_sort (status, sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO support_departments (name,status,sort_order,created_at,updated_at) VALUES
('General Support','active',10,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('Billing','active',20,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('Technical Support','active',30,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('Sales','active',40,UTC_TIMESTAMP(),UTC_TIMESTAMP());
