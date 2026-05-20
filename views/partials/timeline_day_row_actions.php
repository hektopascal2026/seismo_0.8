<?php
/**
 * Right-side timeline toolbar: favourites toggle + expand all.
 *
 * @var bool $showTimelineFavouritesToggle
 * @var bool $timelineFavouritesOn
 * @var string $timelineFavouritesToggleHref
 */

declare(strict_types=1);

$showTimelineFavouritesToggle = !empty($showTimelineFavouritesToggle);
?>
<div class="timeline-day-row-actions">
<?php if ($showTimelineFavouritesToggle): ?>
    <?php require __DIR__ . '/timeline_favourites_toggle.php'; ?>
<?php endif; ?>
<button type="button" class="btn btn-secondary entry-expand-all-btn">expand all &#9660;</button>
</div>
