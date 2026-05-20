<?php
/**
 * Compact favourites-only timeline toggle (index / filter preview).
 *
 * @var bool $timelineFavouritesOn
 * @var string $timelineFavouritesToggleHref Full URL query string (no leading ?)
 */

declare(strict_types=1);

$timelineFavouritesOn = !empty($timelineFavouritesOn);
$timelineFavouritesToggleHref = trim((string)($timelineFavouritesToggleHref ?? ''));
if ($timelineFavouritesToggleHref === '') {
    return;
}
$basePath = getBasePath();
?>
<a href="<?= e($basePath) ?>/index.php?<?= e($timelineFavouritesToggleHref) ?>"
   class="btn btn-secondary timeline-favourites-toggle-btn<?= $timelineFavouritesOn ? ' is-active' : '' ?>"
   title="<?= $timelineFavouritesOn ? 'Show all timeline entries' : 'Show starred favourites only' ?>"
   aria-pressed="<?= $timelineFavouritesOn ? 'true' : 'false' ?>">★ favs</a>
