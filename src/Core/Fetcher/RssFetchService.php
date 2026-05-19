<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

use DateTimeImmutable;
use DateTimeZone;
use SimplePie\SimplePie;

/**
 * Parses RSS/Atom feeds via SimplePie. No SQL — returns normalised rows for
 * {@see \Seismo\Repository\FeedItemRepository::upsertFeedItems()}.
 */
final class RssFetchService
{
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

        $pie = new SimplePie();
        $pie->set_feed_url($feedUrl);
        $pie->set_timeout(25);
        $pie->enable_cache(false);
        $pie->init();
        if ($pie->error()) {
            throw new \RuntimeException((string)$pie->error());
        }

        $out = [];
        foreach ($pie->get_items(0, 200) as $item) {
            $title = trim((string)$item->get_title());
            if ($title === '') {
                continue;
            }
            $link = trim((string)$item->get_permalink());
            if ($link === '' || !$this->isNavigableHttpUrl($link)) {
                continue;
            }
            $descRaw = trim((string)$item->get_description());
            $contentRaw = trim((string)$item->get_content());
            if ($contentRaw === '' && $descRaw !== '') {
                $contentRaw = $descRaw;
            }
            $guid = trim((string)$item->get_id());
            if ($guid === '') {
                $guid = substr(sha1($link . "\0" . $title), 0, 32);
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

    private function isNavigableHttpUrl(string $url): bool
    {
        $u = trim($url);
        if ($u === '' || $u === '#') {
            return false;
        }

        return (bool)preg_match('#^https?://#i', $u);
    }
}
