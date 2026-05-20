-- =============================================================================
-- Seismo — satellite scores database (schema version 17, local tables only)
-- =============================================================================
--
-- Applied by `php migrate.php --scores-db=seismo_<slug>` on the VPS.
-- Entry-source tables live in the shared `seismo` database; this file creates
-- only scoring, labels, favourites, and config for one desk.
-- =============================================================================

CREATE TABLE IF NOT EXISTS entry_scores (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    entry_type      ENUM('feed_item','email','lex_item','calendar_event') NOT NULL,
    entry_id        INT NOT NULL,
    relevance_score FLOAT       DEFAULT 0.0,
    predicted_label VARCHAR(50) DEFAULT NULL,
    explanation     JSON        DEFAULT NULL,
    score_source    ENUM('magnitu','recipe') DEFAULT 'recipe',
    model_version   INT         DEFAULT 0,
    scored_at       TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_entry (entry_type, entry_id),
    INDEX idx_entry_type_id   (entry_type, entry_id),
    INDEX idx_relevance       (relevance_score),
    INDEX idx_predicted_label (predicted_label),
    INDEX idx_score_source    (score_source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS magnitu_labels (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    entry_type ENUM('feed_item','email','lex_item','calendar_event') NOT NULL,
    entry_id   INT NOT NULL,
    label      VARCHAR(50) NOT NULL,
    reasoning  TEXT DEFAULT NULL,
    labeled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_label (entry_type, entry_id),
    INDEX idx_entry (entry_type, entry_id),
    INDEX idx_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entry_favourites (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    entry_type ENUM('feed_item','email','lex_item','calendar_event') NOT NULL,
    entry_id   INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_favourite (entry_type, entry_id),
    INDEX idx_entry (entry_type, entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS magnitu_config (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    config_key   VARCHAR(100) NOT NULL UNIQUE,
    config_value MEDIUMTEXT   DEFAULT NULL,
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
