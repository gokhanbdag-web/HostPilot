CREATE TABLE IF NOT EXISTS cms_pages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    excerpt TEXT NULL,
    content LONGTEXT NOT NULL,
    meta_title VARCHAR(190) NULL,
    meta_description VARCHAR(320) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_cms_pages_slug (slug),
    KEY idx_cms_pages_status_sort (status, sort_order, title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
