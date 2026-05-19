<?php

declare(strict_types=1);

namespace Seismo\Repository;

/**
 * Dashboard tag-filter state. Default = show everything (no query params).
 *
 * **Native form (preferred):** `TimelineFilter::fromHttpGet()` reads
 * `filters[feed][]` (normal `feeds.category` strings plus `sc:<scraper_config.id>`
 * and `sf:<feeds.id>` scraper tokens), `filters[lex][]` (includes Swiss Jus sources
 * `ch_bger` / `ch_bge` / `ch_bvger` as separate toggles), `filters[email][]`,
 * `filters[calendar]=1` (Leg), plus `filter_form=1` when the filter form
 * submitted so “all checkboxes off in a row” is not confused with the default
 * “all on” first visit. `none=1` means every dimension off (empty timeline).
 * When a dimension is fully off, `excludeAll*` flags short-circuit SQL so
 * untagged emails and NULL-category feeds cannot leak through exclusion lists.
 *
 * A `filters[...]` array that includes at least one of `feed` / `lex` / `email` /
 * `calendar` / `jus` also selects the **native** branch, even when
 * `filter_form=1` is missing (e.g. shared `?action=index&filters[lex][]=ch_bge`
 * or the search bar preserving `filters` without the hidden). Otherwise
 * `fromQueryArray` would run and **ignore** `filters`, showing an unfiltered
 * timeline (Lex pills appeared to have no effect while feeds still loaded).
 *
 * **Legacy per-pill OFF lists:** `efc` / `elx` / `eet` are comma-separated tokens
 * that are turned **off** (excluded from SQL). `ecal=1` / `ejus=1` hide Leg / Jus.
 * Parsed when the native `filter_form` / `none` / `filters` branch does not apply.
 *
 * **Legacy inclusion** (`fc`, `fk`, `lx`, `etag`) is still parsed for old links.
 *
 * Keep in sync with {@see DashboardController}, {@see FavouriteController::RETURN_QUERY_ALLOW},
 * and the dashboard filter view.
 */
final class TimelineFilter
{
    private const FK_ALLOWED = ['rss', 'substack', 'scraper'];

    /** Lex sources treated as “Jus” on the Leg / Jus filter row (not Lex pills). */
    public const JUS_LEX_SOURCES = ['ch_bger', 'ch_bge', 'ch_bvger'];

    /**
     * @param list<string> $feedCategories       Legacy: include only these `feeds.category` values.
     * @param list<string> $feedSourceKinds      Legacy: rss|substack|scraper OR.
     * @param list<string> $lexSources           Legacy: Lex `source` IN (…).
     * @param list<string> $emailTags            Legacy: sender tag IN (…).
     * @param list<string> $excludedFeedCategories Dashboard: these feed categories are OFF.
     * @param list<string> $excludedLexSources     Dashboard: these Lex sources are OFF.
     * @param list<string> $excludedEmailTags      Dashboard: these sender tags are OFF.
     * @param bool         $excludeAllFeedItems    Native/none: no feed rows (fixes NULL-category leak).
     * @param bool         $excludeAllEmails       Native/none: no email rows (fixes untagged leak).
     * @param bool         $excludeAllLexItems     Native/none: no lex rows (fixes unknown-source leak).
     */
    public function __construct(
        public readonly array $feedCategories = [],
        public readonly array $feedSourceKinds = [],
        public readonly array $lexSources = [],
        public readonly array $emailTags = [],
        public readonly array $excludedFeedCategories = [],
        public readonly array $excludedLexSources = [],
        public readonly array $excludedEmailTags = [],
        public readonly bool $excludeCalendar = false,
        public readonly bool $excludeJusLex = false,
        public readonly bool $excludeAllFeedItems = false,
        public readonly bool $excludeAllEmails = false,
        public readonly bool $excludeAllLexItems = false,
    ) {
    }

    /**
     * Any filter that narrows the timeline (exclusions, Leg/Jus off, legacy).
     */
    public function isActive(): bool
    {
        return $this->feedCategories !== []
            || $this->feedSourceKinds !== []
            || $this->lexSources !== []
            || $this->emailTags !== []
            || $this->excludedFeedCategories !== []
            || $this->excludedLexSources !== []
            || $this->excludedEmailTags !== []
            || $this->excludeCalendar
            || $this->excludeJusLex
            || $this->excludeAllFeedItems
            || $this->excludeAllEmails
            || $this->excludeAllLexItems;
    }

    /**
     * Default dashboard pill state: no exclusions, Leg + Jus on, no legacy.
     */
    public function dashboardPillsAllOn(): bool
    {
        return $this->excludedFeedCategories === []
            && $this->excludedLexSources === []
            && $this->excludedEmailTags === []
            && !$this->excludeCalendar
            && !$this->excludeJusLex
            && !$this->excludeAllFeedItems
            && !$this->excludeAllEmails
            && !$this->excludeAllLexItems
            && $this->feedCategories === []
            && $this->feedSourceKinds === []
            && $this->lexSources === []
            && $this->emailTags === [];
    }

    /**
     * True when exclusions match “everything off” for the current pill option sets.
     *
     * @param array{feed_categories: list<string>, lex_sources: list<string>, email_tags: list<string>} $pillOpts
     */
    public function dashboardPillsAllOff(array $pillOpts): bool
    {
        if (!$this->excludeCalendar || !$this->excludeJusLex) {
            return false;
        }
        if (!$this->legacyFiltersEmpty()) {
            return false;
        }
        $feeds = $pillOpts['feed_categories'] ?? [];
        $lex   = $pillOpts['lex_sources'] ?? [];
        $em    = $pillOpts['email_tags'] ?? [];

        return self::sameStringSet($feeds, $this->excludedFeedCategories)
            && self::sameStringSet($lex, $this->excludedLexSources)
            && self::sameStringSet($em, $this->excludedEmailTags);
    }

    private function legacyFiltersEmpty(): bool
    {
        return $this->feedCategories === []
            && $this->feedSourceKinds === []
            && $this->lexSources === []
            && $this->emailTags === [];
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function sameStringSet(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        $a = array_values(array_unique($a));
        $b = array_values(array_unique($b));
        sort($a);
        sort($b);

        return $a === $b;
    }

    /**
     * Lex sources excluded per-pill plus optional Jus trio when `ejus=1`.
     *
     * @return list<string>
     */
    public function effectiveExcludedLexSources(): array
    {
        $x = $this->excludedLexSources;
        if ($this->excludeJusLex) {
            foreach (self::JUS_LEX_SOURCES as $j) {
                if (!in_array($j, $x, true)) {
                    $x[] = $j;
                }
            }
        }

        return array_values(array_unique($x));
    }

    /**
     * Build filter state from `$_GET` using native `filters[…]` when `filter_form=1`
     * **or** when `filters` uses native keys (`feed`, `lex`, …), then `none=1`, then
     * legacy query keys.
     *
     * @param array<string, mixed> $get Typically $_GET
     * @param array{feed_categories: list<string>, lex_sources: list<string>, email_tags: list<string>} $pillOpts
     */
    public static function fromHttpGet(array $get, array $pillOpts): self
    {
        $noneRaw = isset($get['none']) ? trim((string)$get['none']) : '';
        if ($noneRaw === '1' || strtolower($noneRaw) === 'true') {
            return self::fromNoneFlag($pillOpts);
        }

        $filterForm = isset($get['filter_form']) ? trim((string)$get['filter_form']) : '';
        if ($filterForm === '1') {
            return self::fromNativeFilterForm($get, $pillOpts);
        }

        $filtersGet = $get['filters'] ?? null;
        if (self::getFiltersInQueryLooksNative($filtersGet)) {
            return self::fromNativeFilterForm($get, $pillOpts);
        }

        return self::fromQueryArray($get);
    }

    /**
     * True when `$_GET['filters']` is shaped like the filter-page form
     * (so it should be parsed with {@see fromNativeFilterForm} even if
     * `filter_form=1` is missing).
     *
     * @param mixed $filters Value of `$get['filters']`
     */
    public static function getFiltersInQueryLooksNative(mixed $filters): bool
    {
        if (!is_array($filters) || $filters === []) {
            return false;
        }

        return array_key_exists('feed', $filters)
            || array_key_exists('lex', $filters)
            || array_key_exists('email', $filters)
            || array_key_exists('calendar', $filters)
            || array_key_exists('jus', $filters);
    }

    /**
     * @param array{feed_categories: list<string>, lex_sources: list<string>, email_tags: list<string>} $pillOpts
     */
    private static function fromNoneFlag(array $pillOpts): self
    {
        return new self(
            excludedFeedCategories: array_values($pillOpts['feed_categories'] ?? []),
            excludedLexSources: array_values($pillOpts['lex_sources'] ?? []),
            excludedEmailTags: array_values($pillOpts['email_tags'] ?? []),
            excludeCalendar: true,
            excludeJusLex: true,
            excludeAllFeedItems: true,
            excludeAllEmails: true,
            excludeAllLexItems: true,
        );
    }

    /**
     * Checkbox form: checked values are inclusions; unchecked dimensions are empty lists.
     *
     * @param array<string, mixed> $get
     * @param array{feed_categories: list<string>, lex_sources: list<string>, email_tags: list<string>} $pillOpts
     */
    private static function fromNativeFilterForm(array $get, array $pillOpts): self
    {
        $filters = isset($get['filters']) && is_array($get['filters']) ? $get['filters'] : [];

        $feedAll = array_values($pillOpts['feed_categories'] ?? []);
        $lexAll  = array_values($pillOpts['lex_sources'] ?? []);
        $emAll   = array_values($pillOpts['email_tags'] ?? []);

        $inFeeds = array_values(array_intersect($feedAll, self::stringListFromFilterBranch($filters['feed'] ?? null)));
        $inLex   = array_values(array_intersect($lexAll, self::stringListFromFilterBranch($filters['lex'] ?? null)));
        $inEm    = array_values(array_intersect($emAll, self::stringListFromFilterBranch($filters['email'] ?? null)));

        // Older filter URLs used a single `filters[jus]=1` instead of three `filters[lex][]` keys.
        $jusLegacy = $filters['jus'] ?? null;
        if (is_scalar($jusLegacy) && trim((string)$jusLegacy) === '1') {
            foreach (self::JUS_LEX_SOURCES as $j) {
                if (!in_array($j, $inLex, true)) {
                    $inLex[] = $j;
                }
            }
            $inLex = array_values(array_unique($inLex));
        }

        $calRaw = $filters['calendar'] ?? null;
        $calOn  = is_scalar($calRaw) && trim((string)$calRaw) === '1';

        $excludeAllFeedItems = $feedAll !== [] && $inFeeds === [];
        $excludeAllEmails    = $emAll !== [] && $inEm === [];
        $excludeAllLexItems  = $lexAll !== [] && $inLex === [];

        return new self(
            excludedFeedCategories: array_values(array_diff($feedAll, $inFeeds)),
            excludedLexSources: array_values(array_diff($lexAll, $inLex)),
            excludedEmailTags: array_values(array_diff($emAll, $inEm)),
            excludeCalendar: !$calOn,
            excludeJusLex: false,
            excludeAllFeedItems: $excludeAllFeedItems,
            excludeAllEmails: $excludeAllEmails,
            excludeAllLexItems: $excludeAllLexItems,
        );
    }

    /**
     * @return list<string>
     */
    private static function stringListFromFilterBranch(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $v) {
                if (!is_scalar($v)) {
                    continue;
                }
                $s = trim((string)$v);
                if ($s !== '') {
                    $out[] = $s;
                }
            }

            return array_values(array_unique($out));
        }
        $s = trim((string)$raw);

        return $s === '' ? [] : [$s];
    }

    /**
     * Legacy-only parser (`efc`/`elx`/… and old inclusion keys). Prefer
     * {@see self::fromHttpGet()} for dashboard requests.
     *
     * @param array<string, mixed> $get Typically $_GET
     */
    public static function fromQueryArray(array $get): self
    {
        $fcList   = self::parseListParam($get['fc'] ?? null);
        $fkList   = self::normalizeFkList(self::parseListParam($get['fk'] ?? null));
        $lxList   = self::parseListParam($get['lx'] ?? null);
        $etagList = self::parseListParam($get['etag'] ?? null);

        $efcList = self::parseListParam($get['efc'] ?? null);
        $elxList = self::parseListParam($get['elx'] ?? null);
        $eetList = self::parseListParam($get['eet'] ?? null);

        $ecalRaw = isset($get['ecal']) ? trim((string)$get['ecal']) : '';
        $ejusRaw = isset($get['ejus']) ? trim((string)$get['ejus']) : '';

        return new self(
            feedCategories: $fcList,
            feedSourceKinds: $fkList,
            lexSources: $lxList,
            emailTags: $etagList,
            excludedFeedCategories: $efcList,
            excludedLexSources: $elxList,
            excludedEmailTags: $eetList,
            excludeCalendar: ($ecalRaw === '1' || strtolower($ecalRaw) === 'true'),
            excludeJusLex: ($ejusRaw === '1' || strtolower($ejusRaw) === 'true'),
        );
    }

    /**
     * @return list<string>
     */
    private static function parseListParam(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $v) {
                if (!is_scalar($v)) {
                    continue;
                }
                $s = trim((string)$v);
                if ($s !== '') {
                    $out[] = $s;
                }
            }

            return array_values(array_unique($out));
        }
        $s = trim((string)$raw);
        if ($s === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $s) as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $parsed
     * @return list<string>
     */
    private static function normalizeFkList(array $parsed): array
    {
        $out = [];
        foreach ($parsed as $p) {
            if (in_array($p, self::FK_ALLOWED, true)) {
                $out[] = $p;
            }
        }
        $out = array_values(array_unique($out));
        if (count($out) === count(self::FK_ALLOWED)) {
            return [];
        }

        return $out;
    }
}
