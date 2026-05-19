<?php
/**
 * Dashboard / timeline view.
 *
 * Slice 1.5: search (GET), newest/favourites toggle, star buttons, session flash.
 * Slice 4 (legacy): tag-filter pills on index — superseded by ?action=filter.
 */

declare(strict_types=1);

/** @var array<int, array<string, mixed>> $allItems */
/** @var string $searchQuery */
/** @var bool $showDaySeparators */
/** @var bool $showFavourites */
/** @var string $returnQuery */
/** @var ?string $dashboardError */
/** @var string $currentView 'newest'|'favourites' */
/** @var string $emptyTimelineHint 'default'|'favourites'|'search'|'filters' */
/** @var string $csrfField CSRF hidden input HTML from DashboardController::show() */
/** @var \Seismo\Repository\TimelineFilter $timelineFilter */
/** @var float $alertThreshold Magnitu alert threshold (used e.g. by Highlights; timeline score badges stay numeric) */

$basePath = getBasePath();
$accent   = seismoBrandAccent();

$headerTitle    = seismoBrandTitle();
$headerSubtitle = null;
$activeNav      = 'index';

$indexLinkParams = ['action' => 'index'];
if ($searchQuery !== '') {
    $indexLinkParams['q'] = $searchQuery;
}
$indexNewestQs = http_build_query($indexLinkParams);

$indexFavParams = ['action' => 'index', 'view' => 'favourites'];
if ($searchQuery !== '') {
    $indexFavParams['q'] = $searchQuery;
}
$indexFavouritesQs = http_build_query($indexFavParams);

$clearSearchParams = ['action' => 'index'];
if ($currentView === 'favourites') {
    $clearSearchParams['view'] = 'favourites';
}
$clearSearchQs = http_build_query($clearSearchParams);

$filterPageParams = ['action' => 'filter'];
foreach (['q', 'view', 'limit', 'offset', 'none', 'filter_form', 'filters'] as $k) {
    if (!isset($_GET[$k])) {
        continue;
    }
    $v = $_GET[$k];
    if (is_array($v)) {
        $filterPageParams[$k] = $v;
    } elseif (is_scalar($v)) {
        $filterPageParams[$k] = $v;
    }
}
$filterPageQs = http_build_query($filterPageParams);
$filterNavQs  = $filterPageQs;

$clearTimelineFiltersParams = ['action' => 'index'];
if ($searchQuery !== '') {
    $clearTimelineFiltersParams['q'] = $searchQuery;
}
if ($currentView === 'favourites') {
    $clearTimelineFiltersParams['view'] = 'favourites';
}
$clearTimelineFiltersQs = http_build_query($clearTimelineFiltersParams);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(seismoBrandTitle()) ?></title>
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

        <div class="search-section search-section-spaced dashboard-search-desktop<?= $allItems !== [] ? ' dashboard-search-desktop--hide-on-mobile' : '' ?>">
                <?php $inlineTimeline = false; ?>
                <?php require __DIR__ . '/partials/dashboard_index_search_form.php'; ?>
        </div>

        <div class="latest-entries-section">
            <?php if ($dashboardError !== null): ?>
                <?php // Error banner above — no empty-state. ?>
            <?php elseif ($allItems !== []): ?>
                <?php
                $embedTimelineExpandAllInDayRow = true;
                $embedDashboardTimelineSearch   = true;
                ?>
                <?php include __DIR__ . '/partials/dashboard_entry_loop.php'; ?>
            <?php else: ?>
                <div class="empty-state">
                    <?php if ($emptyTimelineHint === 'favourites'): ?>
                        <p>No favourites yet. Star entries with the ☆ button on each card, or switch back to <a href="?<?= e($indexNewestQs) ?>">Newest</a>.</p>
                    <?php elseif ($emptyTimelineHint === 'search'): ?>
                        <p>No entries match your search. Try different words or <a href="?action=index">clear the query</a>.</p>
                    <?php elseif ($emptyTimelineHint === 'filters'): ?>
                        <p>No entries match the current filters. <a href="?<?= e($clearTimelineFiltersQs) ?>">Show everything on the timeline</a> or <a href="?<?= e($filterPageQs) ?>">adjust filters</a>.</p>
                    <?php else: ?>
                        <p>No entries yet. Run <code>?action=migrate</code> if this is a fresh install, then come back once a fetcher has populated the database.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

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
