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
        return $u !== '' && $u !== '#';
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

if (!function_exists('seismo_strip_email_listing_boilerplate')) {
    /**
     * Remove fixed “News Service Bund … | date … / … , place , date -” listing lines
     * (Admin.ch-style digests). Only runs when the matching subscription’s
     * apply flag is true (set from {@see email_subscriptions.strip_listing_boilerplate} on the
     * dashboard). Logic matches {@see \Seismo\Core\Mail\EmailListingBoilerplateStripper}
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
