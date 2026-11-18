CREATE TABLE IF NOT EXISTS cms_menus (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(40) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_cms_menus_location (location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_menu_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    menu_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(100) NOT NULL,
    url VARCHAR(500) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    target_blank TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_cms_menu_items_menu_sort (menu_id, status, sort_order, id),
    CONSTRAINT fk_cms_menu_items_menu FOREIGN KEY (menu_id) REFERENCES cms_menus(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cms_menus(name,location,status,created_at,updated_at) VALUES
('Ana Menü','header','active',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('Alt Menü','footer','active',UTC_TIMESTAMP(),UTC_TIMESTAMP());

INSERT INTO cms_menu_items(menu_id,label,url,status,target_blank,sort_order,created_at,updated_at)
SELECT id,'Ana sayfa','/','active',0,10,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM cms_menus WHERE location='header'
AND NOT EXISTS (SELECT 1 FROM cms_menu_items WHERE menu_id=cms_menus.id);

INSERT INTO cms_menu_items(menu_id,label,url,status,target_blank,sort_order,created_at,updated_at)
SELECT id,'Yardım merkezi','/knowledgebase','active',0,20,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM cms_menus WHERE location='header'
AND NOT EXISTS (SELECT 1 FROM cms_menu_items WHERE menu_id=cms_menus.id AND url='/knowledgebase');

INSERT INTO cms_menu_items(menu_id,label,url,status,target_blank,sort_order,created_at,updated_at)
SELECT id,'Müşteri paneli','/client','active',0,30,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM cms_menus WHERE location='header'
AND NOT EXISTS (SELECT 1 FROM cms_menu_items WHERE menu_id=cms_menus.id AND url='/client');

INSERT INTO cms_menu_items(menu_id,label,url,status,target_blank,sort_order,created_at,updated_at)
SELECT id,'Yardım merkezi','/knowledgebase','active',0,10,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM cms_menus WHERE location='footer'
AND NOT EXISTS (SELECT 1 FROM cms_menu_items WHERE menu_id=cms_menus.id);
