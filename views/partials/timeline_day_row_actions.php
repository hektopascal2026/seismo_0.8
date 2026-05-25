<?php
/**
 * Right-side timeline toolbar: favourites / highlights sort toggles + expand all.
 *
 * @var bool $showTimelineMediaToggle
 * @var bool $showTimelineFavouritesToggle
 * @var bool $timelineFavouritesOn
 * @var string $timelineFavouritesToggleHref
 * @var bool $showTimelineHighlightsSortToggle
 * @var bool $timelineHighlightsSortHighestOn
 * @var string $timelineHighlightsSortToggleHref
 */

declare(strict_types=1);

$showTimelineMediaToggle = !empty($showTimelineMediaToggle);
$showTimelineFavouritesToggle = !empty($showTimelineFavouritesToggle);
$showTimelineHighlightsSortToggle = !empty($showTimelineHighlightsSortToggle);
?>
<div class="timeline-day-row-actions">
<?php if ($showTimelineMediaToggle): ?>
    <?php require __DIR__ . '/timeline_media_toggle.php'; ?>
<?php endif; ?>
<?php if ($showTimelineFavouritesToggle): ?>
    <?php require __DIR__ . '/timeline_favourites_toggle.php'; ?>
<?php endif; ?>
<?php if ($showTimelineHighlightsSortToggle): ?>
    <?php require __DIR__ . '/timeline_highlights_sort_toggle.php'; ?>
<?php endif; ?>
<button type="button" class="btn btn-secondary entry-expand-all-btn">expand all &#9660;</button>
</div>
