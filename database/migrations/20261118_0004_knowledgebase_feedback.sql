CREATE TABLE IF NOT EXISTS knowledgebase_feedback (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    article_id BIGINT UNSIGNED NOT NULL,
    visitor_hash CHAR(64) NOT NULL,
    vote TINYINT(1) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_knowledgebase_feedback_visitor (article_id, visitor_hash),
    KEY idx_knowledgebase_feedback_article_vote (article_id, vote),
    CONSTRAINT fk_knowledgebase_feedback_article FOREIGN KEY (article_id)
        REFERENCES knowledgebase_articles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
