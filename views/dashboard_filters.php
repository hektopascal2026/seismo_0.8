<?php
/**
 * Dashboard filter editor + live timeline preview on the same page.
 *
 * @var string $csrfField
 * @var ?string $dashboardError
 * @var array{
 *   feed_categories: list<string>,
 *   feed_category_labels?: array<string, string>,
 *   lex_sources: list<string>,
 *   lex_source_labels?: array<string, string>,
 *   email_tags: list<string>
 * } $filterPillOptions
 * @var \Seismo\Repository\TimelineFilter $timelineFilter
 * @var array<int, array<string, mixed>> $allItems
 * @var string $returnQuery
 * @var float $alertThreshold
 * @var string $emptyTimelineHint
 */

declare(strict_types=1);

$basePath = getBasePath();
$accent   = seismoBrandAccent();

$headerTitle    = seismoBrandTitle();
$headerSubtitle = null;
$activeNav      = 'filter';

$searchQuery = trim((string)($_GET['q'] ?? ''));
$currentView = (isset($_GET['view']) && (string)$_GET['view'] === 'favourites')
    ? 'favourites'
    : 'newest';

$filterNavParams = ['action' => 'filter'];
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

$filterNewestOnlyParams = ['action' => 'filter'];
if ($searchQuery !== '') {
    $filterNewestOnlyParams['q'] = $searchQuery;
}
$filterNewestOnlyQs = http_build_query($filterNewestOnlyParams);

$filterDropSearchParams = ['action' => 'filter'];
if ($currentView === 'favourites') {
    $filterDropSearchParams['view'] = 'favourites';
}
$filterDropSearchQs = http_build_query($filterDropSearchParams);

$feedOn = static function (string $cat) use ($timelineFilter): bool {
    return !in_array($cat, $timelineFilter->excludedFeedCategories, true);
};
$lexExcludedEffective = $timelineFilter->effectiveExcludedLexSources();
$lexOn                = static function (string $src) use ($lexExcludedEffective): bool {
    return !in_array($src, $lexExcludedEffective, true);
};
$mailOn = static function (string $tg) use ($timelineFilter): bool {
    return !in_array($tg, $timelineFilter->excludedEmailTags, true);
};
$legOn = !$timelineFilter->excludeCalendar;

$feedCategoryLabels = $filterPillOptions['feed_category_labels'] ?? [];
$lexSourceLabels    = $filterPillOptions['lex_source_labels'] ?? [];

$formAction = $basePath . '/index.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Filters — <?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
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
            <p class="admin-intro">Toggle sources below; the feed updates on this page. Open a lean <a href="<?= e($basePath) ?>/index.php?action=index">Timeline</a> when you prefer no controls.</p>

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
                        $isScraperToken = str_starts_with($cat, 'sc:') || str_starts_with($cat, 'sf:');
                        $fcClass        = $isScraperToken ? 'filter-pill-text--scraper' : 'filter-pill-text--feed';
                        $cid            = 'df-feed-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $cat);
                        $feedLabel      = $feedCategoryLabels[$cat] ?? $cat;
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
                        $lexLabel  = $lexSourceLabels[$src] ?? $src;
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
                    <span class="filter-toolbar__hint">Email tag</span>
                    <?php foreach ($filterPillOptions['email_tags'] as $tg): ?>
                        <?php $cid = 'df-mail-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $tg); ?>
                        <label class="filter-pill-label" for="<?= e($cid) ?>">
                            <input type="checkbox" class="filter-pill-input" id="<?= e($cid) ?>"
                                   name="filters[email][]" value="<?= e($tg) ?>"
                                <?= $mailOn($tg) ? ' checked' : '' ?>>
                            <span class="filter-pill-text filter-pill-text--mail"><?= e($tg) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="filter-toolbar__row">
                    <span class="filter-toolbar__hint">Leg</span>
                    <label class="filter-pill-label" for="df-cal">
                        <input type="checkbox" class="filter-pill-input" id="df-cal" name="filters[calendar]" value="1"
                            <?= $legOn ? ' checked' : '' ?>>
                        <span class="filter-pill-text filter-pill-text--leg" title="Parliamentary calendar (Leg)">Leg</span>
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
                <div class="empty-state">
                    <?php if ($emptyTimelineHint === 'favourites'): ?>
                        <p>No favourites yet. Star entries on a card, or switch to <a href="?<?= e($filterNewestOnlyQs) ?>">all entries</a> on this page.</p>
                    <?php elseif ($emptyTimelineHint === 'search'): ?>
                        <p>No entries match your search. Try different words or <a href="?<?= e($filterDropSearchQs) ?>">clear the query</a>.</p>
                    <?php elseif ($emptyTimelineHint === 'filters'): ?>
                        <p>No entries match the current filters. Use <a href="?<?= e($filterPageAllQs) ?>">All</a> to turn every source on, or adjust the pills above.</p>
                    <?php else: ?>
                        <p>No entries yet. Run <code>?action=migrate</code> if this is a fresh install, then come back once a fetcher has populated the database.</p>
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
