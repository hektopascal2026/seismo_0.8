<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Service\Http\BaseClient;
use Seismo\Service\Http\CompressedBodyDecoder;
use Seismo\Service\Http\HttpClientException;
use SimplePie\SimplePie;

/**
 * Parses RSS/Atom feeds via SimplePie. No SQL — returns normalised rows for
 * {@see \Seismo\Repository\FeedItemRepository::upsertFeedItems()}.
 *
 * Fetches XML with {@see BaseClient} first (follows 308 redirects; Tamedia
 * partner feeds). SimplePie alone often fails on Tamedia {@code /rss.xml}
 * (302 loop) or {@code /rss.html} (308 to partner-feeds).
 */
final class RssFetchService
{
    private const FEED_ACCEPT = 'application/rss+xml, application/atom+xml, application/xml, text/xml, */*;q=0.8';

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchFeedItems(string $feedUrl): array
    {
        $feedUrl = trim($feedUrl);
        if ($feedUrl === '') {
            return [];
        }
        // SharePoint list REST URLs are not RSS — 0.4 SimplePie stored "Untitled" for every item here.
        if (preg_match('#/_api/web/lists#i', $feedUrl) || str_contains($feedUrl, 'getByTitle(')) {
            throw new \RuntimeException(
                'This URL is a SharePoint list API endpoint. Use source_type parl_press (Feeds → Parlament Medien), not RSS.'
            );
        }

        $document = $this->fetchFeedDocument($feedUrl);

        $pie = new SimplePie();
        $pie->set_raw_data($document['xml']);
        $pie->set_timeout(25);
        $pie->enable_cache(false);
        $pie->init();
        if ($pie->error()) {
            throw new \RuntimeException((string)$pie->error());
        }

        $out = [];
        foreach ($pie->get_items(0, 200) as $item) {
            $title = trim((string)$item->get_title());
            if ($title === '' || RssFeedItemFilter::shouldSkip($feedUrl, $title)) {
                continue;
            }
            $link = trim((string)$item->get_permalink());
            if ($link === '' || !$this->isNavigableHttpUrl($link)) {
                continue;
            }
            if (str_contains($link, 'news.google.com')) {
                $link = (new GoogleNewsArticleUrlResolver())->resolve($link);
                if (!$this->isNavigableHttpUrl($link)) {
                    continue;
                }
            }
            $descRaw = trim((string)$item->get_description());
            $contentRaw = trim((string)$item->get_content());
            if ($contentRaw === '' && $descRaw !== '') {
                $contentRaw = $descRaw;
            }
            $guid = trim((string)$item->get_id());
            if ($guid === '') {
                $guid = ArticleLinkNormalizer::stableFeedGuid($link, $link . "\0" . $title);
            } elseif (preg_match('#^https?://#i', $guid) || mb_strlen($guid) > 64) {
                $guid = ArticleLinkNormalizer::stableFeedGuid($link, $guid);
            }
            $author = '';
            $au = $item->get_author();
            if ($au !== null) {
                $author = trim((string)$au->get_name());
            }
            $date = $item->get_date('U');
            $pub = null;
            if ($date !== false && $date !== null && $date !== '') {
                $ts = is_numeric($date) ? (int)$date : (int)strtotime((string)$date);
                if ($ts > 0) {
                    $pub = (new DateTimeImmutable('@' . (string)$ts, new DateTimeZone('UTC')))
                        ->format('Y-m-d H:i:s');
                }
            }

            $out[] = [
                'guid'           => mb_substr($guid, 0, 500),
                'title'          => mb_substr($title, 0, 500),
                'link'           => mb_substr($link, 0, 500),
                'description'    => $descRaw,
                'content'        => $contentRaw,
                'author'         => mb_substr($author, 0, 255),
                'published_date' => $pub,
                'content_hash'   => '',
            ];
        }

        return $out;
    }

    /**
     * @return array{xml: string, final_url: string}
     */
    private function fetchFeedDocument(string $feedUrl): array
    {
        $requestUrl = self::normalizeFeedUrl($feedUrl);
        $client     = new BaseClient(25);

        try {
            $response = $client->get($requestUrl, ['Accept' => self::FEED_ACCEPT]);
        } catch (HttpClientException $e) {
            throw new \RuntimeException(
                $e->getMessage() . self::feedFetchHint($feedUrl),
                0,
                $e
            );
        }

        if (!$response->isOk()) {
            throw new \RuntimeException(
                'Feed HTTP ' . $response->status . ' for ' . $requestUrl . self::feedFetchHint($feedUrl)
            );
        }

        $xml = trim(CompressedBodyDecoder::decode($response->body, $response->headers));
        if ($xml === '' || !str_contains($xml, '<')) {
            if (str_starts_with($response->body, "\x1f\x8b")) {
                throw new \RuntimeException(
                    'Feed response was gzip-compressed but could not be decoded for ' . $requestUrl
                    . self::feedFetchHint($feedUrl)
                );
            }
            throw new \RuntimeException(
                'Feed response was empty or not XML for ' . $requestUrl . self::feedFetchHint($feedUrl)
            );
        }
        if (self::looksLikeHtmlDocument($xml)) {
            throw new \RuntimeException(
                'Feed URL returned an HTML page, not RSS/XML for ' . $requestUrl
                . self::feedFetchHint($feedUrl)
            );
        }

        $final = trim($response->finalUrl);

        return [
            'xml'       => $xml,
            'final_url' => $final !== '' ? $final : $requestUrl,
        ];
    }

    /**
     * Tamedia sites expose broken {@code /rss.xml} (302 loop). Section {@code /rss.html}
     * URLs 308 to partner-feeds (curl/SimplePie need redirect follow).
     */
    private static function normalizeFeedUrl(string $feedUrl): string
    {
        if (preg_match('#^https?://(www\.)?(tagesanzeiger|derbund|bazonline|24heures)\.ch/rss\.xml$#i', $feedUrl)) {
            return (string)preg_replace('#/rss\.xml$#i', '/rss.html', $feedUrl);
        }

        // EEA newsroom “RSS” pages are HTML; the feed is always …/rss.xml on the same path.
        if (preg_match(
            '~^https?://(www\.)?eea\.europa\.eu/.+/rss-feeds/[^/?#]+$~i',
            $feedUrl
        ) && !preg_match('#/rss\.xml$#i', $feedUrl)) {
            return rtrim($feedUrl, '/') . '/rss.xml';
        }

        return $feedUrl;
    }

    private static function looksLikeHtmlDocument(string $body): bool
    {
        $head = strtolower(ltrim(substr($body, 0, 256)));

        return str_starts_with($head, '<!doctype html')
            || str_starts_with($head, '<html')
            || (str_contains($head, '<html') && !str_contains($head, '<rss') && !str_contains($head, '<feed'));
    }

    private static function feedFetchHint(string $feedUrl): string
    {
        if (preg_match('#^https?://(www\.)?eea\.europa\.eu/.+/rss-feeds/#i', $feedUrl)
            && !preg_match('#/rss\.xml$#i', $feedUrl)) {
            return ' Use the feed URL ending in /rss.xml (e.g. '
                . rtrim($feedUrl, '/') . '/rss.xml).';
        }

        if (!preg_match('#^https?://(www\.)?(tagesanzeiger|derbund|bazonline|24heures)\.ch#i', $feedUrl)) {
            return '';
        }

        if (preg_match('#/rss\.xml$#i', $feedUrl)) {
            return ' Tamedia /rss.xml redirects to itself — use a section feed, e.g. '
                . 'https://www.tagesanzeiger.ch/ausland/rss.html '
                . '(→ partner-feeds.publishing.tamedia.ch).';
        }

        return ' For Tamedia, use section URLs ending in /rss.html (e.g. …/ausland/rss.html, …/wirtschaft/rss.html) '
            . 'or paste the partner-feeds.publishing.tamedia.ch URL from the redirect.';
    }

    private function isNavigableHttpUrl(string $url): bool
    {
        $u = trim($url);
        if ($u === '' || $u === '#') {
            return false;
        }

        return (bool)preg_match('#^https?://#i', $u);
    }
}
