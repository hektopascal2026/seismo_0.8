<?php

namespace {
    if (!function_exists('entryTable')) {
        function entryTable(string $t): string { return "`{$t}`"; }
    }
}

namespace Seismo\Tests {

    use PHPUnit\Framework\TestCase;
    use PDO;
    use Seismo\Core\Mail\EmailDigestExportPolicy;

    final class EmailDigestExportPolicyTest extends TestCase
    {
        private PDO $pdo;

        protected function setUp(): void
        {
            $this->pdo = new PDO('sqlite::memory:');
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $this->pdo->exec("
                CREATE TABLE emails (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    parent_email_id INTEGER DEFAULT NULL,
                    subject VARCHAR(255),
                    hidden INTEGER DEFAULT 0
                )
            ");
            $this->pdo->exec("
                CREATE TABLE entry_scores (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    entry_type VARCHAR(50),
                    entry_id INTEGER,
                    relevance_score FLOAT,
                    score_source VARCHAR(50)
                )
            ");
        }

        public function testExportableEmailSqlHidesParentWithVisibleChildren(): void
        {
            $this->pdo->exec("INSERT INTO emails (id, parent_email_id, hidden) VALUES (1, NULL, 0)");
            $this->pdo->exec("INSERT INTO emails (id, parent_email_id, hidden) VALUES (2, 1, 0)");
            $this->pdo->exec("INSERT INTO emails (id, parent_email_id, hidden) VALUES (3, NULL, 0)");

            $sql = 'SELECT id FROM emails e WHERE ' . EmailDigestExportPolicy::sqlExportableEmail('e');
            $ids = $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

            self::assertEqualsCanonicalizing([2, 3], array_map('intval', $ids));
        }

        public function testScoreRowSqlExcludesParentDigestScores(): void
        {
            $this->pdo->exec("INSERT INTO emails (id, parent_email_id, hidden) VALUES (10, NULL, 0)");
            $this->pdo->exec("INSERT INTO emails (id, parent_email_id, hidden) VALUES (11, 10, 0)");
            $this->pdo->exec("INSERT INTO entry_scores (entry_type, entry_id, relevance_score, score_source) VALUES ('email', 10, 0.9, 'recipe')");
            $this->pdo->exec("INSERT INTO entry_scores (entry_type, entry_id, relevance_score, score_source) VALUES ('email', 11, 0.8, 'recipe')");

            $sql = 'SELECT es.entry_id FROM entry_scores es WHERE es.entry_type = \'email\''
                . EmailDigestExportPolicy::sqlScoreRowExcludesDigestParents();
            $ids = $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

            self::assertSame([11], array_map('intval', $ids));
        }
    }
}
