CREATE TABLE IF NOT EXISTS cms_announcements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    body TEXT NOT NULL,
    severity VARCHAR(20) NOT NULL DEFAULT 'info',
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    published_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_cms_announcements_visibility (status, starts_at, ends_at, published_at),
    KEY idx_cms_announcements_created (created_at),
    CONSTRAINT fk_cms_announcements_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_announcement_dismissals (
    announcement_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    dismissed_at DATETIME NOT NULL,
    PRIMARY KEY (announcement_id, user_id),
    KEY idx_cms_announcement_dismissals_user (user_id, dismissed_at),
    CONSTRAINT fk_cms_announcement_dismissal_announcement FOREIGN KEY (announcement_id) REFERENCES cms_announcements(id) ON DELETE CASCADE,
    CONSTRAINT fk_cms_announcement_dismissal_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
