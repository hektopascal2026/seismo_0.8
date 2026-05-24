<?php

declare(strict_types=1);

namespace Seismo\Core\Lex;

/**
 * Fetch full JORF text corpus via PISTE {@code POST /consult/jorf}.
 */
final class LexLegifranceContentFetcher
{
    public const MAX_CONTENT_BYTES = 1_048_576;

    public function __construct(
        private LexLegifranceApiClient $client,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function attachContentToRows(array $rows, int $limit): array
    {
        $limit = max(0, $limit);
        if ($limit === 0 || $rows === []) {
            return $rows;
        }

        $fetched = 0;
        foreach ($rows as &$row) {
            if ($fetched >= $limit) {
                break;
            }
            $textCid = self::textCidFromRow($row);
            if ($textCid === null) {
                continue;
            }
            $content = $this->fetchPlainTextForTextCid($textCid);
            if ($content === null) {
                continue;
            }
            $row['content'] = $content;
            $fetched++;
        }
        unset($row);

        return $rows;
    }

    public function fetchPlainTextForTextCid(string $textCid): ?string
    {
        $textCid = trim($textCid);
        if (!self::isJorfTextCid($textCid)) {
            return null;
        }

        try {
            $decoded = $this->client->postJson('/consult/jorf', ['textCid' => $textCid]);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }

        return $this->plainTextFromJorfResponse($decoded);
    }

    /**
     * @param array<string, mixed> $response
     */
    public function plainTextFromJorfResponse(array $response): ?string
    {
        $parts = [];
        foreach (['visa', 'title', 'resume', 'notice'] as $key) {
            $value = trim((string)($response[$key] ?? ''));
            if ($value !== '') {
                $parts[] = LexPlainText::normalize($value);
            }
        }

        $parts = array_merge($parts, $this->collectArticleTexts($response));
        $plain = trim(implode("\n\n", array_filter($parts)));
        if ($plain === '') {
            return null;
        }
        if (strlen($plain) > self::MAX_CONTENT_BYTES) {
            $plain = substr($plain, 0, self::MAX_CONTENT_BYTES) . "\n\n[truncated]";
        }

        return $plain;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function textCidFromRow(array $row): ?string
    {
        $celex = trim((string)($row['celex'] ?? ''));
        if (self::isJorfTextCid($celex)) {
            return $celex;
        }

        return null;
    }

    public static function isJorfTextCid(string $id): bool
    {
        return $id !== '' && str_starts_with($id, 'JORFTEXT');
    }

    /**
     * @param array<string, mixed> $node
     * @return list<string>
     */
    private function collectArticleTexts(array $node): array
    {
        $out = [];

        $articles = $node['articles'] ?? [];
        if (is_array($articles) && $articles !== []) {
            usort(
                $articles,
                static fn (mixed $a, mixed $b): int => (int)(is_array($a) ? ($a['intOrdre'] ?? 0) : 0)
                    <=> (int)(is_array($b) ? ($b['intOrdre'] ?? 0) : 0),
            );
            foreach ($articles as $article) {
                if (!is_array($article)) {
                    continue;
                }
                $chunk = $this->articlePlainText($article);
                if ($chunk !== '') {
                    $out[] = $chunk;
                }
            }
        }

        $sections = $node['sections'] ?? [];
        if (is_array($sections) && $sections !== []) {
            usort(
                $sections,
                static fn (mixed $a, mixed $b): int => (int)(is_array($a) ? ($a['intOrdre'] ?? 0) : 0)
                    <=> (int)(is_array($b) ? ($b['intOrdre'] ?? 0) : 0),
            );
            foreach ($sections as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $title = trim((string)($section['title'] ?? ''));
                if ($title !== '') {
                    $out[] = $title;
                }
                $out = array_merge($out, $this->collectArticleTexts($section));
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $article
     */
    private function articlePlainText(array $article): string
    {
        $content = LexPlainText::fromHtml((string)($article['content'] ?? ''));
        if ($content === '') {
            return '';
        }

        $num = trim((string)($article['num'] ?? ''));
        $surtitre = trim((string)($article['surtitre'] ?? ''));
        $head = [];
        if ($num !== '') {
            $head[] = 'Article ' . $num;
        }
        if ($surtitre !== '') {
            $head[] = $surtitre;
        }
        if ($head === []) {
            return $content;
        }

        return implode(' — ', $head) . "\n" . $content;
    }
}
