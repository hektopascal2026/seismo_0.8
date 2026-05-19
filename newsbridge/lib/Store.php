<?php

declare(strict_types=1);

final class Store
{
    private PDO $pdo;

    public function __construct(string $sqlitePath)
    {
        $dir = dirname($sqlitePath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create data directory: ' . $dir);
            }
        }

        $this->pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS articles (
                stream_id TEXT NOT NULL,
                url TEXT NOT NULL,
                title TEXT NOT NULL,
                description TEXT,
                published_at TEXT NOT NULL,
                source_name TEXT,
                inserted_at TEXT NOT NULL,
                PRIMARY KEY (stream_id, url)
            )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_articles_stream_published ON articles (stream_id, published_at)');
    }

    public function upsertArticle(
        string $streamId,
        string $url,
        string $title,
        ?string $description,
        string $publishedAtIso,
        ?string $sourceName,
    ): void {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT OR REPLACE INTO articles (stream_id, url, title, description, published_at, source_name, inserted_at)
             VALUES (:stream_id, :url, :title, :description, :published_at, :source_name, :inserted_at)'
        );
        $stmt->execute([
            'stream_id'    => $streamId,
            'url'          => $url,
            'title'        => $title,
            'description'  => $description,
            'published_at' => $publishedAtIso,
            'source_name'  => $sourceName,
            'inserted_at'  => $now,
        ]);
    }

    public function prunePublishedBefore(string $isoCutoffUtc): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM articles WHERE published_at < :cut');
        $stmt->execute(['cut' => $isoCutoffUtc]);
    }

    public function deleteAllForStream(string $streamId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM articles WHERE stream_id = :sid');
        $stmt->execute(['sid' => $streamId]);
    }

    /**
     * @return list<array{url: string, title: string, description: string, published_at: string, source_name: string}>
     */
    public function listForStream(string $streamId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT url, title, COALESCE(description, "") AS description, published_at, COALESCE(source_name, "") AS source_name
             FROM articles WHERE stream_id = :sid ORDER BY published_at DESC LIMIT :lim'
        );
        $stmt->bindValue('sid', $streamId, PDO::PARAM_STR);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $r): array {
            return [
                'url'            => (string) $r['url'],
                'title'          => (string) $r['title'],
                'description'    => (string) $r['description'],
                'published_at'   => (string) $r['published_at'],
                'source_name'    => (string) $r['source_name'],
            ];
        }, $rows);
    }
}
