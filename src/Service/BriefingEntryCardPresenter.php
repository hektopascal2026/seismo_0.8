<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * Renders dashboard-style entry cards for briefing source validation.
 */
final class BriefingEntryCardPresenter
{
    /**
     * @param list<array<string, mixed>> $entries Magnitu-shaped rows from {@see BriefingEntryGatherer}
     * @param array<string, array<string, mixed>> $scoresByKey "entry_type:entry_id" → score row
     */
    public function renderHtml(array $entries, array $scoresByKey): string
    {
        if ($entries === []) {
            return '';
        }

        require_once SEISMO_ROOT . '/views/helpers.php';

        $allItems = [];
        foreach ($entries as $entry) {
            $key = ($entry['entry_type'] ?? '') . ':' . ($entry['entry_id'] ?? '');
            $allItems[] = $this->toWrapper($entry, $scoresByKey[$key] ?? null);
        }

        $csrfField         = '';
        $showFavourites    = false;
        $showDaySeparators = false;
        $searchQuery       = '';
        $returnQuery       = 'action=briefing_builder';

        ob_start();
        require SEISMO_ROOT . '/views/partials/dashboard_entry_loop.php';

        return (string)ob_get_clean();
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed>|null $score
     * @return array<string, mixed>
     */
    private function toWrapper(array $entry, ?array $score): array
    {
        $entryType = (string)($entry['entry_type'] ?? '');

        return match ($entryType) {
            'feed_item'      => $this->wrapFeedFromShaped($entry, $score),
            'email'          => $this->wrapEmailFromShaped($entry, $score),
            'lex_item'       => $this->wrapLexFromShaped($entry, $score),
            'calendar_event' => $this->wrapCalendarFromShaped($entry, $score),
            default          => throw new \InvalidArgumentException('Unknown briefing entry_type: ' . $entryType),
        };
    }

    /**
     * @param array<string, mixed> $e
     * @param array<string, mixed>|null $score
     * @return array<string, mixed>
     */
    private function wrapFeedFromShaped(array $e, ?array $score): array
    {
        $sourceType = (string)($e['source_type'] ?? 'rss');
        $category   = (string)($e['source_category'] ?? '');

        if ($sourceType === 'substack') {
            $type = 'substack';
        } elseif ($sourceType === 'scraper' || $category === 'scraper') {
            $type = 'scraper';
        } else {
            $type = 'feed';
        }

        $row = [
            'id'               => (int)($e['entry_id'] ?? 0),
            'title'            => (string)($e['title'] ?? ''),
            'description'      => (string)($e['description'] ?? ''),
            'content'          => (string)($e['content'] ?? ''),
            'link'             => (string)($e['link'] ?? ''),
            'published_date'   => $e['published_date'] ?? null,
            'feed_source_type' => $sourceType,
            'feed_category'    => $category,
            'feed_title'       => (string)($e['source_name'] ?? ''),
            'author'           => (string)($e['author'] ?? ''),
            'guid'             => '',
        ];

        $wrapper = [
            'type'           => $type,
            'timeline_media' => strtolower($category) === 'media',
            'entry_type'     => 'feed_item',
            'entry_id'       => (int)($e['entry_id'] ?? 0),
            'date'           => seismo_feed_item_timeline_unix($row),
            'data'           => $row,
            'score'          => $score,
            'is_favourite'   => false,
        ];
        $wrapper['clock_label'] = seismo_format_wrapper_card_clock($wrapper);

        return $wrapper;
    }

    /**
     * @param array<string, mixed> $e
     * @param array<string, mixed>|null $score
     * @return array<string, mixed>
     */
    private function wrapEmailFromShaped(array $e, ?array $score): array
    {
        $displayTitle = (string)($e['title'] ?? '');
        $row          = [
            'id'            => (int)($e['entry_id'] ?? 0),
            'subject'       => $displayTitle !== '' ? $displayTitle : '(No subject)',
            'text_body'     => (string)($e['content'] ?? ''),
            'from_name'     => (string)($e['source_name'] ?? ''),
            'from_email'    => '',
            'sender_tag'    => (string)($e['source_category'] ?? 'unclassified'),
            'derived_title' => '',
            'date_received' => $e['published_date'] ?? null,
            'metadata'      => null,
        ];

        $wrapper = [
            'type'         => 'email',
            'entry_type'   => 'email',
            'entry_id'     => (int)($e['entry_id'] ?? 0),
            'date'         => seismo_email_timeline_unix($row),
            'data'         => $row,
            'score'        => $score,
            'is_favourite' => false,
        ];
        $wrapper['clock_label'] = seismo_format_wrapper_card_clock($wrapper);

        return $wrapper;
    }

    /**
     * @param array<string, mixed> $e
     * @param array<string, mixed>|null $score
     * @return array<string, mixed>
     */
    private function wrapLexFromShaped(array $e, ?array $score): array
    {
        $row = [
            'id'              => (int)($e['entry_id'] ?? 0),
            'source'          => $this->lexSourceFromShaped((string)($e['source_type'] ?? '')),
            'title'           => (string)($e['title'] ?? ''),
            'description'     => (string)($e['description'] ?? ''),
            'document_type'   => (string)($e['source_category'] ?? ''),
            'eurlex_url'      => (string)($e['link'] ?? ''),
            'celex'           => '',
            'document_date'   => $e['published_date'] ?? null,
            'content_excerpt' => (string)($e['description'] ?? ''),
        ];

        $wrapper = [
            'type'         => 'lex',
            'entry_type'   => 'lex_item',
            'entry_id'     => (int)($e['entry_id'] ?? 0),
            'date'         => seismo_lex_item_timeline_unix($row),
            'data'         => $row,
            'score'        => $score,
            'is_favourite' => false,
        ];
        $wrapper['clock_label'] = seismo_format_wrapper_card_clock($wrapper);

        return $wrapper;
    }

    /**
     * @param array<string, mixed> $e
     * @param array<string, mixed>|null $score
     * @return array<string, mixed>
     */
    private function wrapCalendarFromShaped(array $e, ?array $score): array
    {
        $sourceType = (string)($e['source_type'] ?? '');
        $source     = 'parliament_ch';
        if (str_starts_with($sourceType, 'leg_')) {
            $parsed = substr($sourceType, 4);
            if ($parsed !== '') {
                $source = $parsed;
            }
        }

        $row = [
            'id'          => (int)($e['entry_id'] ?? 0),
            'title'       => (string)($e['title'] ?? ''),
            'description' => (string)($e['description'] ?? ''),
            'content'     => (string)($e['content'] ?? ''),
            'url'         => (string)($e['link'] ?? ''),
            'event_type'  => (string)($e['source_category'] ?? ''),
            'council'     => (string)($e['author'] ?? ''),
            'event_date'  => $e['published_date'] ?? null,
            'source'      => $source,
        ];

        $wrapper = [
            'type'         => 'calendar',
            'entry_type'   => 'calendar_event',
            'entry_id'     => (int)($e['entry_id'] ?? 0),
            'date'         => seismo_calendar_event_timeline_unix($row),
            'data'         => $row,
            'score'        => $score,
            'is_favourite' => false,
        ];
        $wrapper['clock_label'] = seismo_format_wrapper_card_clock($wrapper);

        return $wrapper;
    }

    private function lexSourceFromShaped(string $sourceType): string
    {
        if (str_starts_with($sourceType, 'lex_')) {
            $parsed = substr($sourceType, 4);

            return $parsed !== '' ? $parsed : 'eu';
        }

        return $sourceType !== '' ? $sourceType : 'eu';
    }
}
