<?php

declare(strict_types=1);

final class RssWriter
{
    /**
     * @param list<array{url: string, title: string, description: string, published_at: string, source_name: string}> $items
     */
    public static function buildChannel(
        string $channelTitle,
        string $channelDescription,
        string $channelLink,
        string $selfFeedUrl,
        array $items,
    ): string {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $rss = $dom->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
        $dom->appendChild($rss);

        $channel = $dom->createElement('channel');
        $rss->appendChild($channel);

        self::appendTextChild($dom, $channel, 'title', $channelTitle);
        self::appendTextChild($dom, $channel, 'link', $channelLink);
        self::appendTextChild($dom, $channel, 'description', $channelDescription);

        $atom = $dom->createElement('atom:link');
        $atom->setAttribute('href', $selfFeedUrl);
        $atom->setAttribute('rel', 'self');
        $atom->setAttribute('type', 'application/rss+xml');
        $channel->appendChild($atom);

        self::appendTextChild($dom, $channel, 'lastBuildDate', self::rfc822(gmdate('c')));

        foreach ($items as $row) {
            $item = $dom->createElement('item');
            self::appendTextChild($dom, $item, 'title', $row['title']);
            $link = $dom->createElement('link');
            $link->appendChild($dom->createTextNode($row['url']));
            $item->appendChild($link);
            if ($row['description'] !== '') {
                self::appendTextChild($dom, $item, 'description', $row['description']);
            }
            $guid = $dom->createElement('guid');
            $guid->setAttribute('isPermaLink', 'true');
            $guid->appendChild($dom->createTextNode($row['url']));
            $item->appendChild($guid);
            self::appendTextChild($dom, $item, 'pubDate', self::rfc822($row['published_at']));
            if ($row['source_name'] !== '') {
                self::appendTextChild($dom, $item, 'category', $row['source_name']);
            }
            $channel->appendChild($item);
        }

        return $dom->saveXML() ?: '';
    }

    private static function appendTextChild(DOMDocument $dom, DOMElement $parent, string $name, string $text): void
    {
        $el = $dom->createElement($name);
        $el->appendChild($dom->createTextNode($text));
        $parent->appendChild($el);
    }

    private static function rfc822(string $isoOrRfc): string
    {
        $ts = strtotime($isoOrRfc);
        if ($ts === false) {
            $ts = time();
        }

        return gmdate('D, d M Y H:i:s', $ts) . ' GMT';
    }
}
