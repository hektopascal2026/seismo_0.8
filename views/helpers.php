<?php
/**
 * View-level helper functions.
 *
 * These are **presentation helpers only** — things the sacred
 * dashboard_entry_loop.php partial calls directly. They live in the global
 * namespace because the partial is deliberately kept byte-for-byte identical
 * to its 0.4 shape, and the partial calls these as bare functions.
 *
 * Only presentation and string-shaping logic belongs here. Never put SQL,
 * database access, or side-effectful code in this file — those belong in
 * Repositories or Services.
 *
 * Loaded from DashboardController before rendering the view, using
 * `require_once` so repeated controller renders in the same request don't
 * redeclare functions.
 */

declare(strict_types=1);

if (!function_exists('seismo_magnitu_day_heading')) {
    /**
     * German day label for the dashboard date separators ("Heute", "Gestern").
     * Returns '' for non-positive timestamps.
     *
     * Local-time calendar comparison is deliberate — the user reads the
     * dashboard in Zurich, so "today" should mean "today in Zurich" even
     * though timestamps are stored UTC.
     */
    function seismo_magnitu_day_heading(int $unixTs): string
    {
        if ($unixTs <= 0) {
            return '';
        }
        $tz       = seismo_view_timezone();
        $itemDay  = (new \DateTimeImmutable('@' . $unixTs))->setTimezone($tz)->setTime(0, 0, 0);
        $todayDay = new \DateTimeImmutable('today', $tz);
        $diffDays = (int) round(($todayDay->getTimestamp() - $itemDay->getTimestamp()) / 86400);

        if ($diffDays === 0) {
            return 'Heute';
        }
        if ($diffDays === 1) {
            return 'Gestern';
        }
        if ($diffDays === 2) {
            return 'Vorgestern';
        }
        if ($diffDays >= 3 && $diffDays <= 6) {
            return 'Heute -' . $diffDays;
        }
        if ($diffDays < 0) {
            return $itemDay->format('d.m.Y');
        }

        return $itemDay->format('d.m.Y');
    }
}

if (!function_exists('seismo_timeline_view_link_params')) {
    /**
     * Query params for index/filter timeline links (preserves search, filters, paging).
     *
     * @param 'index'|'filter' $action
     */
    function seismo_timeline_view_link_params(string $action, bool $favouritesView): array
    {
        $params = ['action' => $action, 'view' => $favouritesView ? 'favourites' : 'newest'];
        $searchQuery = trim((string)($_GET['q'] ?? ''));
        if ($searchQuery !== '') {
            $params['q'] = $searchQuery;
        }
        foreach (['limit', 'offset', 'none', 'filter_form', 'filters'] as $k) {
            if (!isset($_GET[$k])) {
                continue;
            }
            $v = $_GET[$k];
            if (is_array($v)) {
                $params[$k] = $v;
            } elseif (is_scalar($v)) {
                $params[$k] = $v;
            }
        }
        if ($action === 'index' && isset($_GET['show_media']) && (string)$_GET['show_media'] === '1') {
            $params['show_media'] = '1';
        }

        return $params;
    }
}

if (!function_exists('seismo_parl_press_commission_from_guid')) {
    /**
     * Second pill label for parlament.ch press rows (0.4: lex document_type).
     * Parses `parl_mm:{slug}` / `parl_sda:{slug}` guids from {@see \Seismo\Core\Fetcher\ParlPressFetchService}.
     */
    function seismo_parl_press_commission_from_guid(?string $guid): string
    {
        $guid = trim((string)$guid);
        if ($guid === '') {
            return '';
        }
        $slug = preg_match('#^parl_(mm|sda):(.+)$#i', $guid, $m) ? $m[2] : $guid;

        return \Seismo\Core\Fetcher\ParlPressFetchService::commissionFromSlug($slug);
    }
}

if (!function_exists('seismo_feed_item_pill_label')) {
    /**
     * Timeline / preview pill text for a feed_item (RSS, Substack, Media).
     *
     * Always {@see feeds.title} (`feed_title` / `feed_name`). {@see feeds.category}
     * is routing / future classification only — not shown on cards or filter pills.
     */
    function seismo_feed_item_pill_label(array $item, int $maxLen = 32): string
    {
        $feedLabel = trim((string)($item['feed_title'] ?? ''));
        if ($feedLabel === '') {
            $feedLabel = trim((string)($item['feed_name'] ?? ''));
        }

        if ($maxLen > 0 && mb_strlen($feedLabel) > $maxLen) {
            $feedLabel = mb_substr($feedLabel, 0, $maxLen) . '…';
        }

        return $feedLabel;
    }
}

if (!function_exists('seismo_feed_filter_pill_text_class')) {
    /**
     * CSS modifier for a filter-page feed pill (`filter-pill-text--*`).
     *
     * @param string $kind rss|substack|media|scraper
     */
    function seismo_feed_filter_pill_text_class(string $kind): string
    {
        return match ($kind) {
            'substack' => 'filter-pill-text--feed-substack',
            'media'    => 'filter-pill-text--feed-media',
            'scraper'  => 'filter-pill-text--scraper',
            default    => 'filter-pill-text--feed',
        };
    }
}

if (!function_exists('seismo_feed_item_entry_tag_class')) {
    /**
     * CSS modifier for a timeline feed_item source pill (`entry-tag--*`).
     *
     * @param array<string, mixed> $item Feed row (`feed_source_type`, `feed_category`, …)
     * @param string               $wrapperType `rss` or `substack` from {@see EntryRepository::wrapFeedItem()}.
     */
    function seismo_feed_item_entry_tag_class(array $item, string $wrapperType = 'rss', bool $timelineMedia = false): string
    {
        if ($timelineMedia || seismo_feed_item_is_timeline_media($item)) {
            return 'entry-tag--feed-media';
        }

        return $wrapperType === 'substack' ? 'entry-tag--feed-substack' : 'entry-tag--feed-rss';
    }
}

if (!function_exists('seismo_lex_source_pill_parts')) {
    /**
     * Emoji + short label for Lex / Jus source pills (timeline cards and filter page).
     *
     * @return array{emoji: string, label: string}
     */
    function seismo_lex_source_pill_parts(string $source): array
    {
        return match ($source) {
            'ch_bger'  => ['emoji' => '⚖️', 'label' => 'BGer'],
            'ch_bge'   => ['emoji' => '⚖️', 'label' => 'BGE'],
            'ch_bvger' => ['emoji' => '⚖️', 'label' => 'BVGer'],
            'de'       => ['emoji' => '🇩🇪', 'label' => 'DE'],
            'ch'       => ['emoji' => '🇨🇭', 'label' => 'CH'],
            'fr'       => ['emoji' => '🇫🇷', 'label' => 'FR'],
            default    => ['emoji' => '🇪🇺', 'label' => 'EU'],
        };
    }
}

if (!function_exists('seismo_lex_filter_pill_label')) {
    /** Filter-page Lex pill text (e.g. `🇩🇪 DE`). */
    function seismo_lex_filter_pill_label(string $source): string
    {
        $p = seismo_lex_source_pill_parts($source);

        return $p['emoji'] . ' ' . $p['label'];
    }
}

if (!function_exists('seismo_leg_filter_pill_label')) {
    /** Filter-page Leg (calendar) pill text — matches Parlament.ch source family. */
    function seismo_leg_filter_pill_label(): string
    {
        return '🇨🇭 PARL';
    }
}

if (!function_exists('seismo_feed_item_is_timeline_media')) {
    /** True when the feed row is routed to the Media module (`feeds.category = media`). */
    function seismo_feed_item_is_timeline_media(array $item): bool
    {
        return strtolower(trim((string)($item['feed_category'] ?? '')))
            === \Seismo\Feed\FeedModule::CATEGORY_MEDIA;
    }
}

if (!function_exists('seismo_feed_item_resolved_link')) {
    /**
     * Resolve a feed_items row to a usable article URL.
     *
     * Some feeds emit blank <link> elements and stash the URL in <guid>.
     * This helper hides that asymmetry from the view.
     */
    function seismo_feed_item_resolved_link(array $item): string
    {
        $link = trim((string)($item['link'] ?? ''));
        if ($link !== '') {
            return $link;
        }
        $guid = trim((string)($item['guid'] ?? ''));
        if ($guid !== '' && preg_match('#^https?://#i', $guid)) {
            return $guid;
        }
        return '';
    }
}

if (!function_exists('seismo_is_navigable_url')) {
    /**
     * True when a value is safe to use as an external href without producing a
     * dead control (empty string, whitespace-only, or "#" reload the page or
     * jump nowhere). Feed/Lex/Leg rows occasionally store "#" or blank when
     * upstream metadata is incomplete.
     */
    function seismo_is_navigable_url(?string $url): bool
    {
        $u = trim((string)$url);
        if ($u === '' || $u === '#') {
            return false;
        }
        $lower = rtrim(strtolower($u), '/');
        if ($lower === 'https://seismo.live' || $lower === 'http://seismo.live') {
            return false;
        }
        return true;
    }
}

if (!function_exists('seismo_lex_bge_celex_for_display')) {
    /** Human-readable BGE citation (152-IV-41 → 152 IV 41). */
    function seismo_lex_bge_celex_for_display(string $celex): string
    {
        if (preg_match('/^(\d+)-([IVX]+)-(\d+)$/i', $celex, $m)) {
            return $m[1] . ' ' . strtoupper($m[2]) . ' ' . $m[3];
        }

        return $celex;
    }
}

if (!function_exists('seismo_lex_bge_footer_mono_hide')) {
    /** Hide redundant hyphenated celex under the card when the heading is already the citation. */
    function seismo_lex_bge_footer_mono_hide(string $source, string $celexRaw, string $headingTitle): bool
    {
        if ($source !== 'ch_bge' || !preg_match('/^\d+-[IVX]+-\d+$/i', $celexRaw)) {
            return false;
        }
        $citation = seismo_lex_bge_celex_for_display($celexRaw);

        return trim($headingTitle) === $citation || trim($headingTitle) === $celexRaw;
    }
}

if (!function_exists('seismo_lex_card_preview_text')) {
    /**
     * Card body for Lex rows: EU preamble excerpt, FR synopsis, DE corpus lead, etc.
     *
     * @param array<string, mixed> $lexItem
     */
    function seismo_lex_card_preview_text(array $lexItem): string
    {
        return \Seismo\Core\Lex\LexCardPreview::previewText($lexItem);
    }
}

if (!function_exists('seismo_lex_card_heading_title')) {
    /**
     * Primary heading for Lex cards. EU rows often stored CELEX as `title` when
     * SPARQL returned no language-matched expression — prefer `description` then.
     */
    function seismo_lex_card_heading_title(array $lexItem): string
    {
        $source = (string)($lexItem['source'] ?? '');
        $title  = trim((string)($lexItem['title'] ?? ''));
        $celex  = strtoupper(preg_replace('/\s+/', '', (string)($lexItem['celex'] ?? '')));
        $desc   = trim((string)($lexItem['description'] ?? ''));

        if ($source === 'eu' && $desc !== '') {
            $tNorm = strtoupper(preg_replace('/\s+/', '', $title));
            if ($title === '' || $tNorm === $celex || preg_match('/^\d{4,}[A-Z][0-9A-Z]+$/i', $title)) {
                return $desc;
            }
        }
        if ($title !== '') {
            return $title;
        }

        return (string)($lexItem['celex'] ?? '');
    }
}

if (!function_exists('seismo_lex_ch_document_type_for_display')) {
    /**
     * Grey-pill text for Fedlex (CH) cards, e.g. {@code Verordnung / Änderung}.
     */
    function seismo_lex_ch_document_type_for_display(array $lexItem): string
    {
        $source = (string)($lexItem['source'] ?? '');
        if ($source !== 'ch') {
            $f = trim((string)($lexItem['document_type'] ?? ''));

            return $f !== '' ? $f : 'Legislation';
        }

        return \Seismo\Plugin\LexFedlex\LexFedlexPlugin::documentTypePillLabelFromLexRow($lexItem);
    }
}

if (!function_exists('seismo_lex_eu_document_type_for_display')) {
    /**
     * Grey-pill text for EUR-Lex cards: uses {@see lex_items.document_type}
     * when refresh stored a concrete class; otherwise derives the typology
     * letter from CELEX so older "EU legislation" rows still differentiate.
     */
    function seismo_lex_eu_document_type_for_display(array $lexItem): string
    {
        if (($lexItem['source'] ?? '') !== 'eu') {
            $f = trim((string)($lexItem['document_type'] ?? ''));

            return $f !== '' ? $f : 'Legislation';
        }
        $stored = trim((string)($lexItem['document_type'] ?? ''));
        if ($stored !== '' && strcasecmp($stored, 'EU legislation') !== 0) {
            return mb_substr($stored, 0, 100);
        }

        return \Seismo\Plugin\LexEu\LexEuPlugin::resolveEuDocumentTypeLabel(
            (string)($lexItem['celex'] ?? ''),
            null
        );
    }
}

if (!function_exists('seismo_highlight_search_term')) {
    /**
     * Wrap matches of $searchQuery in a <mark> while escaping everything else.
     *
     * Slice 1 doesn't expose search UI, but the partial calls this function
     * in its "search active" branch. Ship a working implementation now so the
     * partial stays functionally identical once search returns in Slice 1.5.
     */
    function seismo_highlight_search_term(?string $text, string $searchQuery): string
    {
        $text = (string)$text;
        if ($searchQuery === '' || $text === '') {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $escapedText  = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedQuery = preg_quote($searchQuery, '/');
        $result       = preg_replace(
            '/' . $escapedQuery . '/iu',
            '<mark class="search-highlight">$0</mark>',
            $escapedText
        );
        return $result ?? $escapedText;
    }
}

if (!function_exists('seismo_calendar_event_type_label')) {
    /**
     * Friendly label for a Leg (calendar_events) event type. Input values
     * come straight from the Parlament.ch OData feed, which is inconsistent
     * about diacritics ("Geschäft" vs "Geschaeft") — both variants map here.
     */
    function seismo_calendar_event_type_label(?string $type): string
    {
        return match ($type) {
            'session'                                      => 'Session',
            'Motion'                                       => 'Motion',
            'Postulat'                                     => 'Postulat',
            'Interpellation',
            'Dringliche Interpellation'                    => 'Interpellation',
            'Einfache Anfrage',
            'Dringliche Einfache Anfrage'                  => 'Anfrage',
            'Parlamentarische Initiative'                  => 'Parl. Initiative',
            'Standesinitiative'                            => 'Standesinitiative',
            'Geschaeft des Bundesrates',
            'Geschäft des Bundesrates'                     => 'Bundesratsgeschäft',
            'Geschaeft des Parlaments',
            'Geschäft des Parlaments'                      => 'Parlamentsgeschäft',
            'Petition'                                     => 'Petition',
            'Empfehlung'                                   => 'Empfehlung',
            'Fragestunde. Frage'                           => 'Fragestunde',
            default                                        => $type !== null && $type !== '' ? $type : 'Event',
        };
    }
}

if (!function_exists('seismo_calendar_event_body_text')) {
    /**
     * Display body for Leg / calendar_events cards.
     *
     * Parlament.ch stores Ausgangslage in `description` and Begründung /
     * eingereichter Text in `content` — the dashboard must read both.
     *
     * @param array<string, mixed> $event
     */
    function seismo_calendar_event_body_text(array $event): string
    {
        $decode = static function (string $raw): string {
            return trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        };
        $description = $decode((string)($event['description'] ?? ''));
        $bodyText    = $decode((string)($event['content'] ?? ''));
        if ($bodyText === $description) {
            $bodyText = '';
        }
        if ($bodyText === '') {
            return $description;
        }

        return $description !== '' ? $description . "\n\n" . $bodyText : $bodyText;
    }
}

if (!function_exists('seismo_calendar_event_metadata')) {
    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    function seismo_calendar_event_metadata(array $event): array
    {
        $raw = $event['metadata'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('seismo_leg_parl_ch_signal')) {
    /**
     * @param array<string, mixed> $event
     */
    function seismo_leg_parl_ch_signal(array $event): ?string
    {
        $signal = seismo_calendar_event_metadata($event)['leg_signal'] ?? null;
        if (!is_string($signal) || $signal === '') {
            return null;
        }

        return $signal;
    }
}

if (!function_exists('seismo_council_label')) {
    /**
     * Expand a council code from Parlament.ch into a readable label.
     */
    function seismo_council_label(?string $code): string
    {
        return match ($code) {
            'NR'    => 'Nationalrat',
            'SR'    => 'Ständerat',
            'BR'    => 'Bundesrat',
            default => (string)($code ?? ''),
        };
    }
}

if (!function_exists('seismo_format_utc')) {
    /**
     * Format a UTC `DateTimeImmutable` in local (Zurich) time for view display.
     *
     * Single entry point used by the Lex, Leg, and Settings → Diagnostics UI — keeps
     * the "views are the only layer that converts to local time" rule in one
     * place. Uses {@see seismo_view_timezone()} (SEISMO_VIEW_TIMEZONE).
     */
    function seismo_format_utc(?\DateTimeImmutable $dtUtc, string $format = 'd.m.Y H:i'): ?string
    {
        if ($dtUtc === null) {
            return null;
        }
        $local = $dtUtc->setTimezone(seismo_view_timezone());

        return $local->format($format);
    }
}

if (!function_exists('seismo_format_lex_refresh_utc')) {
    /** @deprecated use {@see seismo_format_utc()} — kept for existing lex.php call site. */
    function seismo_format_lex_refresh_utc(?\DateTimeImmutable $dtUtc): ?string
    {
        return seismo_format_utc($dtUtc);
    }
}

if (!function_exists('seismo_email_web_view_url')) {
    /**
     * Newsletter “view in browser” / Webansicht URL from ingest metadata, if any.
     */
    function seismo_email_web_view_url(array $email): ?string
    {
        $url = \Seismo\Core\Mail\EmailMetadata::webViewUrlFromMetadata($email['metadata'] ?? null);
        if ($url !== null && seismo_is_navigable_url($url)) {
            return $url;
        }

        $html  = trim((string)($email['html_body'] ?? $email['body_html'] ?? ''));
        $plain = trim((string)($email['text_body'] ?? $email['body_text'] ?? ''));
        $profile = \Seismo\Core\Mail\EmailLocaleGuesser::profileForEmail(
            (string)($email['subject'] ?? ''),
            $plain
        );
        $ranks = \Seismo\Core\Mail\EmailAlternateLocalePolicy::preferredLocaleRanks($profile);
        $url   = \Seismo\Core\Mail\EmailWebViewUrlExtractor::resolve($html, $plain, $ranks)->url;
        if ($url !== null && seismo_is_navigable_url($url)) {
            return $url;
        }

        return null;
    }
}

if (!function_exists('seismo_email_display_title')) {
    /**
     * Card headline: derived title from ingest processor when present, else subject.
     */
    function seismo_email_display_title(array $email): string
    {
        $derived = trim((string)($email['derived_title'] ?? ''));
        if ($derived !== '') {
            return $derived;
        }
        $subject = trim((string)($email['subject'] ?? ''));

        return $subject !== '' ? $subject : '(No subject)';
    }
}

if (!function_exists('seismo_format_email_body_for_display')) {
    function seismo_format_email_body_for_display(string $body): string
    {
        return \Seismo\Core\Mail\EmailBodyDisplay::formatForDisplay($body);
    }
}

if (!function_exists('seismo_email_plain_body_for_display')) {
    /**
     * Plain-text body for dashboard email cards (never HTML markup).
     *
     * @param array<string, mixed> $email
     */
    function seismo_email_plain_body_for_display(array $email): string
    {
        $body = trim((string)($email['text_body'] ?? $email['body_text'] ?? ''));
        if ($body === '') {
            $body = (string)($email['html_body'] ?? $email['body_html'] ?? '');
        }
        $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($body);
    }
}

if (!function_exists('seismo_trim_email_preview_for_webview_link')) {
    /**
     * Drop trailing read-more / web-view boilerplate when a separate link is shown.
     */
    function seismo_trim_email_preview_for_webview_link(string $preview): string
    {
        $preview = trim(html_entity_decode(strip_tags($preview), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($preview === '') {
            return '';
        }
        $preview = trim(preg_replace('/\s+/u', ' ', $preview) ?? '');
        $webviewPhrases = [
            'view in browser',
            'view this email in your browser',
            'im browser ansehen',
            'im browser öffnen',
            'online lesen',
            'webansicht',
            'online version',
            'version en ligne',
        ];
        $phrasePattern = implode('|', array_map(static fn (string $p): string => preg_quote($p, '/'), $webviewPhrases));
        $preview = trim((string) preg_replace(
            '/\s*(\.{2,}|…)+\s*(?:' . $phrasePattern . ').*$/iu',
            '',
            $preview,
        ));
        foreach ($webviewPhrases as $phrase) {
            $pattern = '/\s*' . preg_quote($phrase, '/') . '.*$/iu';
            $preview = trim((string) preg_replace($pattern, '', $preview));
        }
        $preview = trim((string) preg_replace('/\s*(\.{2,}|…)+\s*$/u', '', $preview));

        return trim((string) preg_replace('/\s*https?:\/\/\S+\s*$/iu', '', $preview));
    }
}

if (!function_exists('seismo_strip_email_listing_boilerplate')) {
    /**
     * Remove fixed “News Service Bund … | date … / … , place , date -” listing lines
     * (Admin.ch-style digests). Only runs when the matching subscription’s
     * apply flag is true (Settings → Mail global default and/or per-subscription
     * {@see email_subscriptions.strip_listing_boilerplate} on the dashboard).
     * Logic matches {@see \Seismo\Core\Mail\EmailListingBoilerplateStripper}
     * (used at ingest, recipe rescoring, and Magnitu export for consistency).
     */
    function seismo_strip_email_listing_boilerplate(string $body, ?string $fromEmail, ?string $subject = null, bool $apply = false): string
    {
        if (!$apply) {
            return $body;
        }
        $s = $subject !== null && trim($subject) !== '' ? $subject : null;

        return \Seismo\Core\Mail\EmailListingBoilerplateStripper::strip($body, $s);
    }
}

if (!function_exists('seismo_ui_nav_leading_throttle_ms')) {
    /**
     * Milliseconds to lock other main-nav / settings-tab links after a navigation
     * click (0 = off). Read from `system_config` key {@see \Seismo\Controller\SettingsController::KEY_NAV_LEADING_THROTTLE}
     * via {@see \Seismo\Repository\SystemConfigRepository} — no raw SQL here.
     */
    function seismo_ui_nav_leading_throttle_ms(): int
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $repo = new \Seismo\Repository\SystemConfigRepository(getDbConnection());
            $raw  = $repo->get(\Seismo\Controller\SettingsController::KEY_NAV_LEADING_THROTTLE) ?? '0';
        } catch (\Throwable) {
            return $cached = 0;
        }
        if ($raw === '1' || $raw === 'true' || $raw === 'yes' || $raw === 'on') {
            return $cached = 500;
        }
        if (is_numeric($raw) && (int)$raw > 0) {
            return $cached = min(10000, max(100, (int)$raw));
        }

        return $cached = 0;
    }
}

if (!function_exists('seismo_linkify_and_format_paragraphs')) {
    /**
     * Escape HTML, auto-link URLs (shortening the link text for clean visuals),
     * and convert double-newlines to paragraph blocks and single-newlines to line breaks.
     */
    function seismo_linkify_and_format_paragraphs(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Regex pattern to find URLs (http/https/www.)
        $pattern = '#https?://[^\s()<>]+[^\s()<>.,;:?!"\x27]#i';

        $linkified = preg_replace_callback($pattern, function ($matches) {
            $url = $matches[0];

            // Decode entities in case they got double-escaped
            $rawUrl = htmlspecialchars_decode($url, ENT_QUOTES | ENT_SUBSTITUTE);

            // Create a shortened link label (e.g. domain.com/some/path... or domain.com)
            $parsed = parse_url($rawUrl);
            $host = $parsed['host'] ?? '';
            $path = $parsed['path'] ?? '';
            $displayUrl = $host;
            if ($path !== '' && $path !== '/') {
                $displayUrl .= mb_substr($path, 0, 15);
                if (mb_strlen($path) > 15) {
                    $displayUrl .= '…';
                }
            }

            return sprintf(
                '<a href="%s" target="_blank" rel="noopener" class="timeline-inline-link">%s</a>',
                htmlspecialchars($rawUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($displayUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );
        }, $escaped);

        // Convert double-newlines to paragraphs, then single-newlines to <br>
        $paragraphs = explode("\n\n", $linkified);
        $formattedParagraphs = [];
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para !== '') {
                $formattedParagraphs[] = '<p class="timeline-entry-paragraph">' . nl2br($para) . '</p>';
            }
        }

        return implode("\n", $formattedParagraphs);
    }
}
