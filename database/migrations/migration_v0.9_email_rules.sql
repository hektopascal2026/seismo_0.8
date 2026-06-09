-- Seismo 0.9 Email Template Rules Schema Migration

CREATE TABLE IF NOT EXISTS newsletter_sender (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email_address VARCHAR(255) NOT NULL,
    sender_name VARCHAR(255) DEFAULT NULL,
    UNIQUE KEY idx_newsletter_sender_email (email_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS newsletter_template (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sender_id INT UNSIGNED NOT NULL,
    template_name VARCHAR(255) NOT NULL,
    active_from DATETIME NOT NULL,
    active_to DATETIME DEFAULT NULL,
    KEY idx_newsletter_template_sender (sender_id),
    CONSTRAINT fk_newsletter_template_sender FOREIGN KEY (sender_id) REFERENCES newsletter_sender (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS template_rule (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    template_id INT UNSIGNED NOT NULL,
    split_method VARCHAR(50) DEFAULT 'html_selector',
    story_selector VARCHAR(255) NOT NULL,
    title_selector VARCHAR(255) DEFAULT NULL,
    link_selector VARCHAR(255) DEFAULT NULL,
    body_selector VARCHAR(255) DEFAULT NULL,
    exclude_selectors JSON DEFAULT NULL,
    exclude_titles JSON DEFAULT NULL,
    glue_rules JSON DEFAULT NULL,
    UNIQUE KEY idx_template_rule_template (template_id),
    CONSTRAINT fk_template_rule_template FOREIGN KEY (template_id) REFERENCES newsletter_template (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
