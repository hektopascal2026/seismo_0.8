<?php

declare(strict_types=1);

namespace Seismo\Plugin\LexRechtBund;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Service\SourceFetcherInterface;
use SimplePie\Item;
use SimplePie\SimplePie;

/**
 * German federal law gazette (BGBl) via recht.bund.de RSS.
 */
final class LexRechtBundPlugin implements SourceFetcherInterface
{
    public function getIdentifier(): string
    {
        return 'recht_bund';
    }

    public function getLabel(): string
    {
        return 'recht.bund.de (BGBl RSS)';
    }

    public function getEntryType(): string
    {
        return 'lex_item';
    }

    public function getConfigKey(): string
    {
        return 'de';
    }

    public function getMinIntervalSeconds(): int
    {
        return 2 * 60 * 60;
    }

    public function fetch(array $config): array
    {
        self::ensureSimplePieAvailable();

        $feedUrl = trim((string)($config['feed_url'] ?? ''));
        if ($feedUrl === '' || !preg_match('#^https://#i', $feedUrl)) {
            throw new \InvalidArgumentException('DE feed_url must be a non-empty https URL.');
        }

        $lookback = max(1, (int)($config['lookback_days'] ?? 90));
        $sinceUtc = new DateTimeImmutable('-' . $lookback . ' days', new DateTimeZone('UTC'));
        $maxItems = max(1, min((int)($config['limit'] ?? 100), 200));
        $excludeTypes = self::excludeDocumentTypesFromConfig($config);

        $pie = new SimplePie();
        $pie->set_feed_url($feedUrl);
        $pie->set_timeout(30);
        $pie->enable_cache(false);
        $pie->init();
        if ($pie->error()) {
            throw new \RuntimeException((string)$pie->error());
        }

        $rows = [];
        foreach ($pie->get_items(0, 300) as $item) {
            if (count($rows) >= $maxItems) {
                break;
            }
            $title = trim((string)$item->get_title());
            $link = trim((string)$item->get_permalink());
            if ($title === '' || $link === '' || !preg_match('#^https://www\.recht\.bund\.de/#i', $link)) {
                continue;
            }

            $pub = $this->itemPublishedUtc($item);
            if ($pub !== null && $pub < $sinceUtc) {
                continue;
            }

            $descRaw = trim((string)$item->get_description());
            $contentRaw = trim((string)$item->get_content());
            $body = $contentRaw !== '' ? $contentRaw : $descRaw;
            $description = $body !== '' ? trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null;
            if ($description === '') {
                $description = null;
            }

            $celex = 'de_rss_' . substr(hash('sha256', $link), 0, 40);
            $docDate = $pub !== null ? $pub->format('Y-m-d') : null;

            $docType = self::guessDocumentType($title);
            if (self::documentTypeIsExcluded($docType, $excludeTypes)) {
                continue;
            }

            $rows[] = [
                'celex' => $celex,
                'title' => $title,
                'description' => $description,
                'document_date' => $docDate,
                'document_type' => $docType,
                'eurlex_url' => $link,
                'work_uri' => $link,
                'source' => 'de',
            ];
        }

        return $rows;
    }

    /**
     * Shared hosts sometimes deploy `src/` without a complete `vendor/`, or a
     * CLI script may load Seismo classes before `bootstrap.php` pulled Composer.
     * Try the project autoload once, then fail with an operator-actionable message.
     */
    private static function ensureSimplePieAvailable(): void
    {
        if (class_exists(SimplePie::class, false)) {
            return;
        }

        $root = defined('SEISMO_ROOT') ? SEISMO_ROOT : dirname(__DIR__, 3);
        $autoload = $root . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }

        if (class_exists(SimplePie::class, true)) {
            return;
        }

        throw new \RuntimeException(
            'SimplePie is not installed (class SimplePie\\SimplePie not found). '
            . 'On the server, run `composer install` in the Seismo install directory and upload the entire `vendor/` '
            . 'folder next to `bootstrap.php`, including `vendor/simplepie/simplepie/`. '
            . 'Without it, RSS-based features (DE Lex refresh and core RSS fetch) cannot run.'
        );
    }

    private function itemPublishedUtc(Item $item): ?DateTimeImmutable
    {
        $date = $item->get_date('U');
        if ($date === false || $date === null || $date === '') {
            return null;
        }
        $ts = is_numeric($date) ? (int)$date : (int)strtotime((string)$date);
        if ($ts <= 0) {
            return null;
        }

        return (new DateTimeImmutable('@' . (string)$ts, new DateTimeZone('UTC')));
    }

    private static function guessDocumentType(string $title): string
    {
        $t = mb_strtolower($title);
        if (str_contains($t, 'verordnung')) {
            return 'Verordnung';
        }
        if (str_contains($t, 'gesetz')) {
            return 'Gesetz';
        }
        if (str_contains($t, 'bekanntmachung')) {
            return 'Bekanntmachung';
        }

        return 'BGBl';
    }

    /**
     * Lowercased exclusions; empty means nothing is filtered.
     *
     * @return list<string>
     */
    private static function excludeDocumentTypesFromConfig(array $config): array
    {
        if (!array_key_exists('exclude_document_types', $config)) {
            return ['bekanntmachung'];
        }
        $raw = $config['exclude_document_types'];
        if (!is_array($raw)) {
            return ['bekanntmachung'];
        }
        $out = [];
        foreach ($raw as $v) {
            if (is_string($v)) {
                $t = mb_strtolower(trim($v));
                if ($t !== '' && mb_strlen($t) <= 64) {
                    $out[] = $t;
                }
            }
        }

        return $out;
    }

    /** @param list<string> $excludedLower */
    private static function documentTypeIsExcluded(string $guessedDocType, array $excludedLower): bool
    {
        if ($excludedLower === []) {
            return false;
        }

        return in_array(mb_strtolower(trim($guessedDocType)), $excludedLower, true);
    }
}
