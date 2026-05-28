<?php
/**
 * Dashboard filter editor + live timeline preview on the same page.
 *
 * @var string $csrfField
 * @var ?string $dashboardError
 * @var array{
 *   feed_categories: list<string>,
 *   feed_category_labels?: array<string, string>,
 *   feed_pill_kinds?: array<string, string>,
 *   lex_sources: list<string>,
 *   lex_source_labels?: array<string, string>,
 *   email_tags: list<string>,
 *   email_tag_labels?: array<string, string>,
 * } $filterPillOptions
 * @var \Seismo\Repository\TimelineFilter $timelineFilter
 * @var array<int, array<string, mixed>> $allItems
 * @var string $returnQuery
 * @var float $alertThreshold
 * @var string $emptyTimelineHint
 * @var string $currentView 'newest'|'favourites'
 */

declare(strict_types=1);

$basePath = getBasePath();
$accent   = seismoBrandAccent();

$headerTitle    = seismoBrandTitle();
$headerSubtitle = null;
$activeNav      = 'filter';

$searchQuery = trim((string)($_GET['q'] ?? ''));
$currentView = $currentView ?? 'newest';

$filterNavParams = ['action' => 'filter'];
if ($currentView === 'favourites') {
    $filterNavParams['view'] = 'favourites';
}
foreach (['q', 'view', 'limit', 'offset', 'none', 'filter_form', 'filters'] as $k) {
    if (!isset($_GET[$k])) {
        continue;
    }
    $v = $_GET[$k];
    if (is_array($v)) {
        $filterNavParams[$k] = $v;
    } elseif (is_scalar($v)) {
        $filterNavParams[$k] = $v;
    }
}
$filterNavQs = http_build_query($filterNavParams);

$filterResetParams = ['action' => 'filter'];
if ($searchQuery !== '') {
    $filterResetParams['q'] = $searchQuery;
}
if ($currentView === 'favourites') {
    $filterResetParams['view'] = 'favourites';
}
$filterPageAllQs = http_build_query($filterResetParams);

$filterPageNoneParams            = $filterResetParams;
$filterPageNoneParams['none']    = '1';
$filterPageNoneQs               = http_build_query($filterPageNoneParams);

$filterNewestOnlyQs = http_build_query(seismo_timeline_view_link_params('filter', false));
$filterFavouritesQs = http_build_query(seismo_timeline_view_link_params('filter', true));
$timelineFavouritesOn = $currentView === 'favourites';
$timelineFavouritesToggleHref = $timelineFavouritesOn ? $filterNewestOnlyQs : $filterFavouritesQs;
$showTimelineFavouritesToggle = true;

$filterDropSearchParams = ['action' => 'filter'];
if ($currentView === 'favourites') {
    $filterDropSearchParams['view'] = 'favourites';
}
$filterDropSearchQs = http_build_query($filterDropSearchParams);

$feedOn = static function (string $cat) use ($timelineFilter): bool {
    if ($timelineFilter->feedCategories !== []) {
        return in_array($cat, $timelineFilter->feedCategories, true);
    }

    return !in_array($cat, $timelineFilter->excludedFeedCategories, true);
};
$lexExcludedEffective = $timelineFilter->effectiveExcludedLexSources();
$lexOn                = static function (string $src) use ($timelineFilter, $lexExcludedEffective): bool {
    if ($timelineFilter->lexSources !== []) {
        return in_array($src, $timelineFilter->lexSources, true);
    }

    return !in_array($src, $lexExcludedEffective, true);
};
$mailOn = static function (string $tg) use ($timelineFilter): bool {
    if ($timelineFilter->emailTags !== []) {
        return in_array($tg, $timelineFilter->emailTags, true);
    }

    return !in_array($tg, $timelineFilter->excludedEmailTags, true);
};
$legOn = !$timelineFilter->excludeCalendar;

$feedCategoryLabels = $filterPillOptions['feed_category_labels'] ?? [];
$feedPillKinds      = $filterPillOptions['feed_pill_kinds'] ?? [];
$emailTagLabels     = $filterPillOptions['email_tag_labels'] ?? [];

$formAction = $basePath . '/index.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Filters — <?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css?v=<?= e(SEISMO_VERSION) ?>">
    <?php if ($accent): ?>
    <style>:root { --seismo-accent: <?= e($accent) ?>; }</style>
    <?php endif; ?>
</head>
<body>
    <div class="container">
        <?php require __DIR__ . '/partials/site_header.php'; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="message message-success"><?= e((string)$_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="message message-error"><?= e((string)$_SESSION['error']) ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if ($dashboardError !== null): ?>
            <div class="message message-error"><?= e($dashboardError) ?></div>
        <?php endif; ?>

        <div class="search-section search-section-spaced">
            <div class="filter-page-actions">
                <a href="<?= e($basePath) ?>/index.php?<?= e($filterPageAllQs) ?>" class="btn btn-primary">All</a>
                <a href="<?= e($basePath) ?>/index.php?<?= e($filterPageNoneQs) ?>" class="btn btn-secondary">None</a>
            </div>

            <form id="dashboard-filters" class="filter-toolbar filter-toolbar--form" method="get" action="<?= e($formAction) ?>">
                <input type="hidden" name="action" value="filter">
                <input type="hidden" name="filter_form" value="1">
                <?php if ($searchQuery !== ''): ?>
                    <input type="hidden" name="q" value="<?= e($searchQuery) ?>">
                <?php endif; ?>
                <?php if ($currentView === 'favourites'): ?>
                    <input type="hidden" name="view" value="favourites">
                <?php endif; ?>
                <?php
                $lim = isset($_GET['limit']) && ctype_digit((string)$_GET['limit']) ? (string)$_GET['limit'] : '';
                $off = isset($_GET['offset']) && ctype_digit((string)$_GET['offset']) ? (string)$_GET['offset'] : '';
                ?>
                <?php if ($lim !== ''): ?>
                    <input type="hidden" name="limit" value="<?= e($lim) ?>">
                <?php endif; ?>
                <?php if ($off !== ''): ?>
                    <input type="hidden" name="offset" value="<?= e($off) ?>">
                <?php endif; ?>

                <div class="filter-toolbar__head">
                    <span class="filter-toolbar__label">Filters</span>
                </div>

                <?php if ($filterPillOptions['feed_categories'] !== []): ?>
                <div class="filter-toolbar__row">
                    <span class="filter-toolbar__hint">Feed</span>
                    <?php foreach ($filterPillOptions['feed_categories'] as $cat): ?>
                        <?php
                        $pillKind  = $feedPillKinds[$cat] ?? (str_starts_with($cat, 'sc:') ? 'scraper' : 'rss');
                        $fcClass   = seismo_feed_filter_pill_text_class($pillKind);
                        $cid       = 'df-feed-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $cat);
                        $feedLabel = $feedCategoryLabels[$cat] ?? $cat;
                        ?>
                        <label class="filter-pill-label" for="<?= e($cid) ?>">
                            <input type="checkbox" class="filter-pill-input" id="<?= e($cid) ?>"
                                   name="filters[feed][]" value="<?= e($cat) ?>"
                                <?= $feedOn($cat) ? ' checked' : '' ?>>
                            <span class="filter-pill-text <?= e($fcClass) ?>"><?= e($feedLabel) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($filterPillOptions['lex_sources'] !== []): ?>
                <div class="filter-toolbar__row">
                    <span class="filter-toolbar__hint">Lex &amp; Jus</span>
                    <?php foreach ($filterPillOptions['lex_sources'] as $src): ?>
                        <?php
                        $cid       = 'df-lex-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $src);
                        $lexLabel  = seismo_lex_filter_pill_label($src);
                        ?>
                        <label class="filter-pill-label" for="<?= e($cid) ?>">
                            <input type="checkbox" class="filter-pill-input" id="<?= e($cid) ?>"
                                   name="filters[lex][]" value="<?= e($src) ?>"
                                <?= $lexOn($src) ? ' checked' : '' ?>>
                            <span class="filter-pill-text filter-pill-text--lex"><?= e($lexLabel) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($filterPillOptions['email_tags'] !== []): ?>
                <div class="filter-toolbar__row">
                    <span class="filter-toolbar__hint">Mail</span>
                    <?php foreach ($filterPillOptions['email_tags'] as $tg): ?>
                        <?php
                        $cid       = 'df-mail-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $tg);
                        $mailLabel = $emailTagLabels[$tg] ?? $tg;
                        ?>
                        <label class="filter-pill-label" for="<?= e($cid) ?>">
                            <input type="checkbox" class="filter-pill-input" id="<?= e($cid) ?>"
                                   name="filters[email][]" value="<?= e($tg) ?>"
                                <?= $mailOn($tg) ? ' checked' : '' ?>>
                            <span class="filter-pill-text filter-pill-text--mail"><?= e($mailLabel) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="filter-toolbar__row">
                    <span class="filter-toolbar__hint">Leg</span>
                    <label class="filter-pill-label" for="df-cal">
                        <input type="checkbox" class="filter-pill-input" id="df-cal" name="filters[calendar]" value="1"
                            <?= $legOn ? ' checked' : '' ?>>
                        <span class="filter-pill-text filter-pill-text--leg" title="Parliamentary calendar (Leg)"><?= e(seismo_leg_filter_pill_label()) ?></span>
                    </label>
                    <span class="filter-toolbar__sep">|</span>
                    <label class="filter-pill-label" for="df-mem">
                        <input type="checkbox" class="filter-pill-input" id="df-mem" name="filters[mem]" value="1"
                            <?= $timelineFilter->filterMem ? ' checked' : '' ?>>
                        <span class="filter-pill-text filter-pill-text--leg" title="Swissmem Monitor (Mem)">Mem</span>
                    </label>
                </div>
            </form>
        </div>

        <div class="filter-page-preview latest-entries-section">
            <?php if ($dashboardError !== null): ?>
                <?php // Error shown above. ?>
            <?php elseif ($allItems !== []): ?>
                <?php
                $showDaySeparators              = true;
                $showFavourites                 = true;
                $embedTimelineExpandAllInDayRow = true;
                ?>
                <?php include __DIR__ . '/partials/dashboard_entry_loop.php'; ?>
            <?php else: ?>
                <div class="timeline-day-row timeline-day-row--expand-only">
                    <?php require __DIR__ . '/partials/timeline_day_row_actions.php'; ?>
                </div>
                <div class="empty-state">
                    <?php if ($emptyTimelineHint === 'favourites'): ?>
                        <p>No favourites yet. Star entries on a card, or switch to <a href="?<?= e($filterNewestOnlyQs) ?>">all entries</a> on this page.</p>
                    <?php elseif ($emptyTimelineHint === 'search'): ?>
                        <p>No entries match your search. Try different words or <a href="?<?= e($filterDropSearchQs) ?>">clear the query</a>.</p>
                    <?php elseif ($emptyTimelineHint === 'swissmem'): ?>
                        <p>No Swissmem mentions found in the last 7 days. Try updating the timeline or check back later.</p>
                    <?php elseif ($emptyTimelineHint === 'filters'): ?>
                        <p>No entries match the current filters. Use <a href="?<?= e($filterPageAllQs) ?>">All</a> to turn every source on, or adjust the pills above.</p>
                    <?php else: ?>
                        <p>No entries yet. Run <code>php migrate.php</code> if this is a fresh install, then come back once a fetcher has populated the database.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    (function() {
        var f = document.getElementById('dashboard-filters');
        if (!f) return;
        f.addEventListener('change', function() {
            f.submit();
        });
    })();
    </script>
    <script>
    (function() {
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.timeline-favourites-toggle-btn');
            if (!btn || !btn.dataset.href) return;
            window.location.assign(btn.dataset.href);
        });
        function collapse(card, btn) {
            var preview = card.querySelector('.entry-preview');
            var full    = card.querySelector('.entry-full-content');
            if (!preview || !full) return;
            full.style.display = 'none';
            preview.style.display = '';
            if (btn) btn.textContent = 'expand \u25BC';
        }
        function expand(card, btn) {
            var preview = card.querySelector('.entry-preview');
            var full    = card.querySelector('.entry-full-content');
            if (!preview || !full) return;
            preview.style.display = 'none';
            full.style.display    = 'block';
            if (btn) btn.textContent = 'collapse \u25B2';
        }
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.entry-expand-btn');
            if (!btn) return;
            var card = btn.closest('.entry-card');
            var full = card.querySelector('.entry-full-content');
            if (!full) return;
            full.style.display === 'block' ? collapse(card, btn) : expand(card, btn);
        });
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.entry-expand-all-btn');
            if (!btn) return;
            var isExpanded = btn.dataset.expanded === 'true';
            document.querySelectorAll('.entry-card').forEach(function(card) {
                var cardBtn = card.querySelector('.entry-expand-btn');
                isExpanded ? collapse(card, cardBtn) : expand(card, cardBtn);
            });
            btn.dataset.expanded = !isExpanded;
            btn.textContent = !isExpanded ? 'collapse all \u25B2' : 'expand all \u25BC';
        });
    })();
    </script>
</body>
</html>
