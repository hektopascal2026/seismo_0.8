<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * Which entry families to include in a researcher gather pass.
 *
 * Two modes:
 *   - **Export** — legacy `type` query param (`all`, `feed_item`, …); `feed_item` uses one
 *     unpartitioned {@see \Seismo\Repository\MagnituExportRepository::listFeedItemsSince()}.
 *   - **Modules** — nav-aligned Feeds / Media / Scraper / Mail / Lex / Leg toggles for AI Researcher.
 */
final class ResearcherSourceSelection
{
    private const EXPORT_TYPES = ['all', 'feed_item', 'email', 'lex_item', 'calendar_event'];

    private function __construct(
        private readonly ?string $exportType,
        private readonly bool $moduleFeeds,
        private readonly bool $moduleMedia,
        private readonly bool $moduleScraper,
        private readonly bool $moduleEmail,
        private readonly bool $moduleNewsletter,
        private readonly bool $moduleLex,
        private readonly bool $moduleLeg,
        private readonly bool $moduleLexCh = false,
        private readonly bool $moduleMem = false,
    ) {
    }

    public static function forExport(string $type): self
    {
        if (!in_array($type, self::EXPORT_TYPES, true)) {
            $type = 'all';
        }

        return new self($type, false, false, false, false, false, false, false, false, false);
    }

    public static function forModules(
        bool $feeds,
        bool $media,
        bool $scraper,
        bool $email,
        bool $newsletter,
        bool $lex,
        bool $leg,
        bool $lexCh = false,
        bool $mem = false,
    ): self {
        return new self(null, $feeds, $media, $scraper, $email, $newsletter, $lex, $leg, $lexCh, $mem);
    }

    public function isExportMode(): bool
    {
        return $this->exportType !== null;
    }

    public function exportType(): string
    {
        return $this->exportType ?? 'all';
    }

    public function moduleFeeds(): bool
    {
        return $this->moduleFeeds;
    }

    public function moduleMedia(): bool
    {
        return $this->moduleMedia;
    }

    public function moduleScraper(): bool
    {
        return $this->moduleScraper;
    }

    public function moduleEmail(): bool
    {
        return $this->moduleEmail;
    }

    public function moduleNewsletter(): bool
    {
        return $this->moduleNewsletter;
    }

    public function moduleLex(): bool
    {
        return $this->moduleLex;
    }

    public function moduleLexCh(): bool
    {
        return $this->moduleLexCh;
    }

    public function moduleLeg(): bool
    {
        return $this->moduleLeg;
    }

    public function moduleMem(): bool
    {
        return $this->moduleMem;
    }

    public function hasAnyModule(): bool
    {
        return $this->moduleFeeds
            || $this->moduleMedia
            || $this->moduleScraper
            || $this->moduleEmail
            || $this->moduleNewsletter
            || $this->moduleLex
            || $this->moduleLeg
            || $this->moduleLexCh
            || $this->moduleMem;
    }
}
