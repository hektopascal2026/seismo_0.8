<?php
/**
 * Entry card header right cluster: favourite/hide actions, then Magnitu score badge.
 *
 * Expects $relevanceScore, $predictedLabel, $scoreBadgeClass plus favourite/hide vars
 * for {@see entry_meta_favourite_hide.php} when used on feed/email cards.
 */
declare(strict_types=1);

$hasScore = $relevanceScore !== null;
$showFavourites = !empty($showFavourites);
$showHide = !isset($showHide) || $showHide;
$canHide = $showHide
    && in_array((string)($favouriteEntryType ?? ''), ['feed_item', 'email'], true)
    && (int)($favouriteEntryId ?? 0) > 0;
$hasCardActions = $canHide || $showFavourites;

if (!$hasScore && !$hasCardActions) {
    return;
}
?>
<div class="entry-header-end">
<?php if ($hasCardActions): ?>
    <?php require __DIR__ . '/entry_meta_favourite_hide.php'; ?>
<?php endif; ?>
<?php if ($hasScore): ?>
    <span class="magnitu-badge <?= $scoreBadgeClass ?>" title="<?= htmlspecialchars($predictedLabel ?? '') ?> (<?= round($relevanceScore * 100) ?>%)"><?= round($relevanceScore * 100) ?></span>
<?php endif; ?>
</div>
