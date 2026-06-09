<?php
/**
 * Migration 031 — Relational tables for email template splitting rules (schema 47).
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;
use RuntimeException;

final class Migration031EmailTemplateRules
{
    public const VERSION = 47;

    public static function migrationScope(): MigrationScope
    {
        return MigrationScope::MothershipOnly;
    }

    public function apply(PDO $pdo, MigrationTarget $target): void
    {
        // 1. Create newsletter_sender
        if (!$this->tableExists($pdo, 'newsletter_sender')) {
            $pdo->exec(
                'CREATE TABLE newsletter_sender (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    email_address VARCHAR(255) NOT NULL,
                    sender_name VARCHAR(255) DEFAULT NULL,
                    UNIQUE KEY idx_newsletter_sender_email (email_address)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        // 2. Create newsletter_template
        if (!$this->tableExists($pdo, 'newsletter_template')) {
            $pdo->exec(
                'CREATE TABLE newsletter_template (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    sender_id INT UNSIGNED NOT NULL,
                    template_name VARCHAR(255) NOT NULL,
                    active_from DATETIME NOT NULL,
                    active_to DATETIME DEFAULT NULL,
                    KEY idx_newsletter_template_sender (sender_id),
                    CONSTRAINT fk_newsletter_template_sender FOREIGN KEY (sender_id) REFERENCES newsletter_sender (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        // 3. Create template_rule
        if (!$this->tableExists($pdo, 'template_rule')) {
            $pdo->exec(
                'CREATE TABLE template_rule (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    template_id INT UNSIGNED NOT NULL,
                    split_method VARCHAR(50) DEFAULT \'html_selector\',
                    story_selector VARCHAR(255) NOT NULL,
                    title_selector VARCHAR(255) DEFAULT NULL,
                    link_selector VARCHAR(255) DEFAULT NULL,
                    body_selector VARCHAR(255) DEFAULT NULL,
                    exclude_selectors JSON DEFAULT NULL,
                    exclude_titles JSON DEFAULT NULL,
                    glue_rules JSON DEFAULT NULL,
                    UNIQUE KEY idx_template_rule_template (template_id),
                    CONSTRAINT fk_template_rule_template FOREIGN KEY (template_id) REFERENCES newsletter_template (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        // 4. Migrate data from email_subscriptions.digest_split_config
        $this->migrateExistingConfigs($pdo);
    }

    private function migrateExistingConfigs(PDO $pdo): void
    {
        $stmt = $pdo->query('SELECT id, display_name, match_type, match_value, digest_split_config FROM email_subscriptions WHERE digest_split_config IS NOT NULL AND digest_split_config != \'\'');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $config = json_decode($row['digest_split_config'], true);
            if (!is_array($config) || empty($config)) {
                continue;
            }

            $matchValue = trim((string)$row['match_value']);
            if ($matchValue === '') {
                continue;
            }

            // Insert newsletter_sender (email_address holds match_value: domain or exact email)
            $senderStmt = $pdo->prepare('INSERT IGNORE INTO newsletter_sender (email_address, sender_name) VALUES (?, ?)');
            $senderStmt->execute([$matchValue, trim((string)$row['display_name'])]);

            $senderIdStmt = $pdo->prepare('SELECT id FROM newsletter_sender WHERE email_address = ?');
            $senderIdStmt->execute([$matchValue]);
            $senderId = $senderIdStmt->fetchColumn();

            if (!$senderId) {
                continue;
            }

            // Check if template already exists
            $tplCheckStmt = $pdo->prepare('SELECT id FROM newsletter_template WHERE sender_id = ? AND template_name = ?');
            $templateName = 'Default Template';
            $tplCheckStmt->execute([$senderId, $templateName]);
            $templateId = $tplCheckStmt->fetchColumn();

            if (!$templateId) {
                // Insert default template using standard CURRENT_TIMESTAMP (cross-platform compatible)
                $tplStmt = $pdo->prepare('INSERT INTO newsletter_template (sender_id, template_name, active_from) VALUES (?, ?, CURRENT_TIMESTAMP)');
                $tplStmt->execute([$senderId, $templateName]);
                $templateId = $pdo->lastInsertId();
            }

            if (!$templateId) {
                continue;
            }

            // Extract split rule settings
            $splitRules = $config['split_rules'] ?? $config;
            $splitMethod = trim((string)($splitRules['split_method'] ?? $splitRules['type'] ?? 'html_selector'));
            $storySelector = $splitRules['story_selector'] ?? $splitRules['selector_story'] ?? '';
            $titleSelector = $splitRules['title_selector'] ?? $splitRules['selector_title'] ?? null;
            $linkSelector = $splitRules['link_selector'] ?? $splitRules['selector_link'] ?? null;
            $bodySelector = $splitRules['body_selector'] ?? $splitRules['selector_body'] ?? null;

            $excludeSelectors = isset($splitRules['exclude_selectors']) ? json_encode($splitRules['exclude_selectors']) : null;
            $excludeTitles = isset($splitRules['exclude_titles']) ? json_encode($splitRules['exclude_titles']) : null;
            $glueRules = isset($splitRules['glue_rules']) ? json_encode($splitRules['glue_rules']) : null;

            if (trim($storySelector) === '') {
                continue;
            }

            $ruleStmt = $pdo->prepare(
                'INSERT IGNORE INTO template_rule (
                    template_id, split_method, story_selector, title_selector, link_selector, body_selector, 
                    exclude_selectors, exclude_titles, glue_rules
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ruleStmt->execute([
                $templateId, $splitMethod, $storySelector, $titleSelector, $linkSelector, $bodySelector,
                $excludeSelectors, $excludeTitles, $glueRules
            ]);
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);

        return (int)$stmt->fetchColumn() > 0;
    }
}
