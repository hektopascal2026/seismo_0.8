<?php
/**
 * Highlights timeline sort toggle (newest-first vs highest score).
 *
 * @var bool $timelineHighlightsSortHighestOn
 * @var string $timelineHighlightsSortToggleHref Full URL query string (no leading ?)
 */

declare(strict_types=1);

$timelineHighlightsSortHighestOn = !empty($timelineHighlightsSortHighestOn);
$timelineHighlightsSortToggleHref = trim((string)($timelineHighlightsSortToggleHref ?? ''));
if ($timelineHighlightsSortToggleHref === '') {
    return;
}
$basePath = getBasePath();
$toggleUrl = $basePath . '/index.php?' . $timelineHighlightsSortToggleHref;
?>
<button type="button"
        class="btn btn-secondary timeline-highlights-sort-toggle-btn<?= $timelineHighlightsSortHighestOn ? ' is-active' : '' ?>"
        data-href="<?= e($toggleUrl) ?>"
        title="<?= $timelineHighlightsSortHighestOn ? 'Sort by date (newest first)' : 'Sort by highest score' ?>"
        aria-pressed="<?= $timelineHighlightsSortHighestOn ? 'true' : 'false' ?>">highest</button>
