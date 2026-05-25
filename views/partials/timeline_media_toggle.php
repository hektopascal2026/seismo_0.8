<?php
/**
 * Media monitoring visibility toggle (main index timeline).
 *
 * @var bool $timelineMediaOn
 * @var string $timelineMediaToggleHref Full URL query string (no leading ?)
 */

declare(strict_types=1);

$timelineMediaOn = !empty($timelineMediaOn);
$timelineMediaToggleHref = trim((string)($timelineMediaToggleHref ?? ''));
if ($timelineMediaToggleHref === '') {
    return;
}
$basePath = getBasePath();
$toggleUrl = $basePath . '/index.php?' . $timelineMediaToggleHref;
?>
<button type="button"
        class="btn btn-secondary timeline-media-toggle-btn<?= $timelineMediaOn ? ' is-active' : '' ?>"
        data-href="<?= e($toggleUrl) ?>"
        title="<?= $timelineMediaOn ? 'Hide media monitoring entries' : 'Show media monitoring entries' ?>"
        aria-pressed="<?= $timelineMediaOn ? 'true' : 'false' ?>">Media</button>
