<?php

declare(strict_types=1);

namespace Seismo\Service;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Core\Fetcher\RssFetchService;
use Seismo\Repository\EntryRepository;

/**
 * Build static RSS 2.0 files under `newsbridge/feeds/` for Seismo to consume as
 * ordinary RSS `feeds.url` sources — replaces hosting generated XML on staging.
 * No database access.
 */
final class NewsbridgeGenerator
{
    public function __construct(
        private readonly RssFetchService $rss = new RssFetchService(),
    ) {
    }

    /**
     * @return array{written: list<string>, errors: list<string>, stats: array<string, array{items: int, sources: int, failed_sources: int}>}
     */
    public function run(string $configPath, string $outDir): array
    {
        if (!is_file($configPath) || !is_readable($configPath)) {
            return [
                'written' => [],
                'errors'  => [
                    'Config not found or not readable: ' . $configPath
                    . ' — copy `newsbridge/config.example.json` to `newsbridge/config.json` (in the Seismo install root).',
                ],
                'stats'   => [],
            ];
        }
        $raw = file_get_contents($configPath);
        if ($raw === false || $raw === '') {
            return [
                'written' => [],
                'errors'  => ['Config is empty.'],
                'stats'   => [],
            ];
        }
        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [
                'written' => [],
                'errors'  => ['Invalid JSON: ' . $e->getMessage()],
                'stats'   => [],
            ];
        }
        if (!is_array($data) || !isset($data['outputs']) || !is_array($data['outputs'])) {
            return [
                'written' => [],
                'errors'  => ['Config must be a JSON object with an "outputs" array.'],
                'stats'   => [],
            ];
        }

        if (!is_dir($outDir)) {
            if (!@mkdir($outDir, 0755, true) && !is_dir($outDir)) {
                return [
                    'written' => [],
                    'errors'  => ['Cannot create output directory: ' . $outDir],
                    'stats'   => [],
                ];
            }
        }
        if (!is_writable($outDir)) {
            return [
                'written' => [],
                'errors'  => ['Output directory is not writable: ' . $outDir],
                'stats'   => [],
            ];
        }

        $written = [];
        $errors  = [];
        $stats   = [];

        foreach ($data['outputs'] as $idx => $block) {
            if (!is_array($block)) {
                $errors[] = 'outputs[' . (string)$idx . '] is not an object.';

                continue;
            }
            $file = trim((string)($block['file'] ?? ''));
            if ($file === '' || str_contains($file, '..') || str_contains($file, '/')) {
                $errors[] = 'Invalid or missing "file" in outputs[' . (string)$idx . '].';

                continue;
            }
            if (!str_ends_with(strtolower($file), '.xml')) {
                $errors[] = 'Output file must end with .xml: ' . $file;

                continue;
            }
            $sources = $block['sources'] ?? null;
            if (!is_array($sources) || $sources === []) {
                $errors[] = 'No sources for ' . $file;

                continue;
            }
            $sourceUrls = [];
            foreach ($sources as $u) {
                if (!is_string($u)) {
                    continue;
                }
                $u = trim($u);
                if ($u !== '' && (str_starts_with($u, 'http://') || str_starts_with($u, 'https://'))) {
                    $sourceUrls[] = $u;
                }
            }
            if ($sourceUrls === []) {
                $errors[] = 'No valid source URLs for ' . $file;

                continue;
            }

            $maxItems = (int)($block['max_items'] ?? 100);
            $maxItems = max(1, min(EntryRepository::MAX_LIMIT, $maxItems));

            $ch = is_array($block['channel'] ?? null) ? $block['channel'] : [];
            $title       = trim((string)($ch['title'] ?? 'Newsbridge'));
            $link        = trim((string)($ch['link'] ?? ''));
            if ($link === '' && defined('SEISMO_MOTHERSHIP_URL')) {
                $mu = trim((string)SEISMO_MOTHERSHIP_URL);
                if ($mu !== '' && (str_starts_with($mu, 'http://') || str_starts_with($mu, 'https://'))) {
                    $link = rtrim($mu, '/');
                }
            }
            if ($link === '') {
                $link = 'https://example.invalid/';
            }
            $description = trim((string)($ch['description'] ?? 'Aggregated RSS'));

            $failedCount    = 0;
            $items          = $this->collectItems($sourceUrls, $errors, $failedCount);
            $items          = $this->applyLanguageFilter($items, $block);
            $items          = $this->dedupeByLink($items);
            usort($items, $this->sortByDateDesc(...));
            $items = array_slice($items, 0, $maxItems);

            $xml = $this->buildRss2(
                $title,
                $link,
                $description,
                (string)($block['self_link'] ?? (rtrim($link, '/') . '/newsbridge/feeds/' . $file)),
                $items
            );

            $target = rtrim($outDir, '/\\') . '/' . $file;
            if (@file_put_contents($target, $xml) === false) {
                $errors[] = 'Failed to write ' . $target;

                continue;
            }
            $written[] = $file;
            $stats[$file] = [
                'items'           => count($items),
                'sources'         => count($sourceUrls),
                'failed_sources'  => $failedCount,
            ];
        }

        return ['written' => $written, 'errors' => $errors, 'stats' => $stats];
    }

    /**
     * @param list<string>        $sourceUrls
     * @param list<string>        $errors
     * @return list<array{guid: string, title: string, link: string, description: string, content: string, author: string, published_date: ?string, content_hash: string}>
     */
    private function collectItems(array $sourceUrls, array &$errors, int &$failedSources): array
    {
        $failedSources = 0;
        $out           = [];
        foreach ($sourceUrls as $u) {
            try {
                $rows = $this->rss->fetchFeedItems($u);
                foreach ($rows as $r) {
                    $out[] = $r;
                }
            } catch (\Throwable $e) {
                ++$failedSources;
                $errors[] = $u . ': ' . $e->getMessage();
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function applyLanguageFilter(array $items, array $block): array
    {
        $hint = trim((string)($block['language'] ?? ''));
        if ($hint === '') {
            return $items;
        }
        $hint = strtolower($hint);
        $out  = [];
        foreach ($items as $it) {
            $blob = mb_strtolower(
                (string)($it['title'] ?? '') . ' ' . (string)($it['description'] ?? '') . ' ' . (string)($it['content'] ?? '')
            );
            if ($this->rowMatchesLanguageHint($blob, $hint)) {
                $out[] = $it;
            }
        }

        return $out !== [] ? $out : $items;
    }

    private function rowMatchesLanguageHint(string $blob, string $hint): bool
    {
        return match ($hint) {
            'de'  => (bool)(preg_match('/[äöüß]|\\b(der|die|das|und|für|schweiz|bundes|stadt)\\b/u', $blob) || str_contains($blob, 'ch-de')),
            'fr'  => (bool)(preg_match('/[àâçéèêëîïôùûü]|\\b(les?|une?|dans|pour|suisse|fédéral)\\b/u', $blob) || str_contains($blob, 'ch-fr')),
            'en'  => (bool)(preg_match('/\\b(the|and|for|switzerland|federal|swiss)\\b/i', $blob) || str_contains($blob, 'ch-en')),
            'any' => true,
            default => true,
        };
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function dedupeByLink(array $items): array
    {
        $seen = [];
        $out  = [];
        foreach ($items as $it) {
            $k = mb_strtolower(trim((string)($it['link'] ?? '')));
            if ($k === '' || $k === '#') {
                continue;
            }
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[]    = $it;
        }

        return $out;
    }

    private function sortByDateDesc(array $a, array $b): int
    {
        $ta = $this->rowUnix((string)($a['published_date'] ?? ''));
        $tb = $this->rowUnix((string)($b['published_date'] ?? ''));

        return $tb <=> $ta;
    }

    private function rowUnix(string $pub): int
    {
        $pub = trim($pub);
        if ($pub === '') {
            return 0;
        }
        $t = strtotime($pub . ' UTC');

        return is_int($t) && $t > 0 ? $t : 0;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function buildRss2(
        string $title,
        string $link,
        string $description,
        string $selfUrl,
        array $items
    ): string {
        $tzUtc = new DateTimeZone('UTC');
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">',
            '  <channel>',
            '    <title>' . $this->e($title) . '</title>',
            '    <link>' . $this->e($link) . '</link>',
            '    <description>' . $this->e($description) . '</description>',
            '    <lastBuildDate>' . $this->e($this->rfc2822('now', $tzUtc)) . '</lastBuildDate>',
            '    <atom:link href="' . $this->e($selfUrl) . '" rel="self" type="application/rss+xml"/>',
            '    <generator>Seismo Newsbridge ' . $this->e(SEISMO_VERSION) . '</generator>',
        ];
        foreach ($items as $it) {
            $t = trim((string)($it['title'] ?? ''));
            if ($t === '') {
                continue;
            }
            $itemLink = trim((string)($it['link'] ?? ''));
            if ($itemLink === '' || $itemLink === '#') {
                continue;
            }
            $desc    = (string)($it['content'] ?? '');
            if (trim($desc) === '') {
                $desc = (string)($it['description'] ?? '');
            }
            $desc = strip_tags($desc);
            if (mb_strlen($desc) > 2000) {
                $desc = mb_substr($desc, 0, 1997) . '...';
            }
            $guid = trim((string)($it['guid'] ?? ''));
            if ($guid === '') {
                $guid = sha1($itemLink . "\0" . $t);
            }
            $pub     = (string)($it['published_date'] ?? '');
            $pubDate = $this->rfc2822FromDbOrNow($pub, $tzUtc);
            $lines[] = '    <item>';
            $lines[] = '      <title>' . $this->e($t) . '</title>';
            $lines[] = '      <link>' . $this->e($itemLink) . '</link>';
            $lines[] = '      <description>' . $this->e($desc) . '</description>';
            $lines[] = '      <pubDate>' . $this->e($pubDate) . '</pubDate>';
            $lines[] = '      <guid isPermaLink="false">' . $this->e($guid) . '</guid>';
            $lines[] = '    </item>';
        }
        $lines[] = '  </channel>';
        $lines[] = '</rss>';

        return implode("\n", $lines) . "\n";
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function rfc2822(string $time, DateTimeZone $tz): string
    {
        $d = new DateTimeImmutable($time, $tz);

        return $d->format('r');
    }

    private function rfc2822FromDbOrNow(string $yHis, DateTimeZone $tz): string
    {
        $yHis = trim($yHis);
        if ($yHis === '') {
            return $this->rfc2822('now', $tz);
        }
        $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $yHis, $tz);
        if ($d === false) {
            $t = strtotime($yHis . ' UTC');
            if (is_int($t) && $t > 0) {
                return (new DateTimeImmutable('@' . $t, $tz))->format('r');
            }

            return $this->rfc2822('now', $tz);
        }

        return $d->format('r');
    }
}
