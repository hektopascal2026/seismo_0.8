<?php

declare(strict_types=1);

namespace Seismo\Feed;

/**
 * Admin module scope for {@see \Seismo\Controller\FeedModuleHandler}.
 *
 * Rows are still stored in `feeds` / `feed_items`; {@see self::CATEGORY_MEDIA}
 * partitions the Feeds UI from the Media UI.
 */
final class FeedModule
{
    public const CATEGORY_MEDIA = 'media';

    public const SCOPE_FEEDS = 'feeds';

    public const SCOPE_MEDIA = 'media';

    private function __construct(
        public readonly string $action,
        public readonly string $navKey,
        public readonly string $pageTitle,
        public readonly string $subtitle,
        public readonly string $sourcesTabLabel,
        public readonly string $saveAction,
        public readonly string $deleteAction,
        public readonly string $toggleAction,
        public readonly string $previewAction,
        public readonly string $refreshAction,
        public readonly string $scope,
        public readonly ?string $fixedCategory,
        public readonly bool $allowParlPress,
        public readonly bool $showCategoryField,
        public readonly string $sourcesIntroHtml,
        public readonly string $emptyItemsHtml,
        public readonly string $refreshFlashLabel,
    ) {
    }

    public static function feeds(): self
    {
        return new self(
            action: 'feeds',
            navKey: 'feeds',
            pageTitle: 'Feeds',
            subtitle: 'RSS & Substack',
            sourcesTabLabel: 'Feeds',
            saveAction: 'feed_save',
            deleteAction: 'feed_delete',
            toggleAction: 'feed_toggle_disabled',
            previewAction: 'feed_preview',
            refreshAction: 'refresh_feed_sources',
            scope: self::SCOPE_FEEDS,
            fixedCategory: null,
            allowParlPress: true,
            showCategoryField: true,
            sourcesIntroHtml: '',
            emptyItemsHtml: 'No RSS or Substack items yet. Add a feed under <a href="{sources_href}">Feeds</a> or run a refresh from Diagnostics.',
            refreshFlashLabel: 'Feed sources (RSS, Substack & Parl. press)',
        );
    }

    public static function media(): self
    {
        $base = getBasePath();

        return new self(
            action: 'media',
            navKey: 'media',
            pageTitle: 'Media',
            subtitle: 'News & monitoring',
            sourcesTabLabel: 'Sources',
            saveAction: 'media_save',
            deleteAction: 'media_delete',
            toggleAction: 'media_toggle_disabled',
            previewAction: 'media_preview',
            refreshAction: 'refresh_media_sources',
            scope: self::SCOPE_MEDIA,
            fixedCategory: self::CATEGORY_MEDIA,
            allowParlPress: false,
            showCategoryField: false,
            sourcesIntroHtml: '<p class="admin-intro">Media sources live in the same <code>feeds</code> table with '
                . '<code>category = media</code>. Use <strong>RSS</strong> (e.g. Google News) with '
                . '<strong>Extract full text</strong> (on by default for new sources) for thin aggregators, or add a <strong>Scraper</strong> listing on '
                . '<a href="' . htmlspecialchars($base . '/index.php?action=scraper', ENT_QUOTES, 'UTF-8') . '">Scraper</a> '
                . 'and set its category to <code>media</code> so items appear here. See '
                . '<code>docs/rss-hydration.md</code>.</p>',
            emptyItemsHtml: 'No media items yet. Add a source under <a href="{sources_href}">Sources</a> or run Refresh.',
            refreshFlashLabel: 'Media sources (RSS hydration & scraper)',
        );
    }

    public function isMedia(): bool
    {
        return $this->scope === self::SCOPE_MEDIA;
    }
}
