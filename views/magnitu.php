<?php
/**
 * Highlights — entries with a score (Magnitu or recipe) ≥ alert threshold.
 *
 * @var array<int, array<string, mixed>> $allItems
 * @var string $csrfField
 * @var ?string $dashboardError
 * @var float $alertThreshold
 * @var bool $showDaySeparators
 * @var bool $showFavourites
 * @var string $searchQuery
 * @var string $returnQuery
 * @var string $currentView
 * @var string $emptyTimelineHint
 * @var \Seismo\Repository\TimelineFilter $timelineFilter
 * @var array{feed_categories: list<string>, lex_sources: list<string>, email_tags: list<string>} $filterPillOptions
 * @var bool $showTimelineHighlightsSortToggle
 * @var bool $timelineHighlightsSortHighestOn
 * @var string $timelineHighlightsSortToggleHref
 */

declare(strict_types=1);

$basePath = getBasePath();
$accent   = seismoBrandAccent();

$headerTitle    = 'Highlights';
$headerSubtitle = 'Scores ≥ ' . round($alertThreshold * 100) . '% (alert threshold)';
$activeNav      = 'magnitu';

$timelineHighlightsSortHighestOn = !empty($timelineHighlightsSortHighestOn);
$highlightsSortLabel = $timelineHighlightsSortHighestOn ? 'highest score first' : 'newest first';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($headerTitle) ?> — <?= e(seismoBrandTitle()) ?></title>
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

        <p class="admin-intro">
            Feed, email, Lex, and Leg entries whose current score is at or above your
            <a href="<?= e($basePath) ?>/index.php?action=settings&amp;tab=magnitu">alert threshold</a>
            — Magnitu (ML) and recipe scores both qualify. Sorted <?= e($highlightsSortLabel) ?>.
            <a href="<?= e($basePath) ?>/index.php?action=index">← Timeline</a>
        </p>

        <?php if ($dashboardError !== null): ?>
            <div class="message message-error"><?= e($dashboardError) ?></div>
        <?php endif; ?>

        <div class="latest-entries-section">
            <?php if ($dashboardError !== null): ?>
            <?php elseif ($allItems !== []): ?>
                <?php
                $embedTimelineExpandAllInDayRow = true;
                ?>
                <?php include __DIR__ . '/partials/dashboard_entry_loop.php'; ?>
            <?php else: ?>
                <div class="timeline-day-row timeline-day-row--expand-only">
                    <?php require __DIR__ . '/partials/timeline_day_row_actions.php'; ?>
                </div>
                <div class="empty-state">
                    <?php if ($emptyTimelineHint === 'highlights'): ?>
                        <p>No entries match this threshold yet. Lower the alert threshold under
                            <a href="<?= e($basePath) ?>/index.php?action=settings&amp;tab=magnitu">Settings → Magnitu</a>,
                            wait for the next refresh to recipe-score new items, or for Magnitu to score more items.</p>
                    <?php else: ?>
                        <p>No entries to show.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    (function() {
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.timeline-highlights-sort-toggle-btn');
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
