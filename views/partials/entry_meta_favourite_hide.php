<?php
/**
 * Star + hide controls for feed_item and email cards.
 *
 * Expects: $csrfField, $returnQuery, $favouriteEntryType, $favouriteEntryId,
 *          $isFavourite, $showFavourites (optional), $showHide (optional, default true).
 */
$showFavourites = !empty($showFavourites);
$showHide       = !isset($showHide) || $showHide;
$canHide        = $showHide
    && in_array((string)($favouriteEntryType ?? ''), ['feed_item', 'email'], true)
    && (int)($favouriteEntryId ?? 0) > 0;
?>
<?php if ($canHide): ?>
<form method="POST" action="?action=hide_entry" class="hide-entry-form" onsubmit="return confirm('Hide this entry? It stays hidden after refresh.');">
    <?= $csrfField ?>
    <input type="hidden" name="entry_type" value="<?= htmlspecialchars((string)$favouriteEntryType) ?>">
    <input type="hidden" name="entry_id" value="<?= (int)$favouriteEntryId ?>">
    <input type="hidden" name="return_query" value="<?= htmlspecialchars((string)$returnQuery) ?>">
    <button type="submit" class="hide-entry-btn" title="Hide entry" aria-label="Hide entry">&#10005;</button>
</form>
<?php endif; ?>
<?php if ($showFavourites): ?>
<form method="POST" action="?action=toggle_favourite" class="favourite-form">
    <?= $csrfField ?>
    <input type="hidden" name="entry_type" value="<?= htmlspecialchars((string)$favouriteEntryType) ?>">
    <input type="hidden" name="entry_id" value="<?= (int)$favouriteEntryId ?>">
    <input type="hidden" name="return_query" value="<?= htmlspecialchars((string)$returnQuery) ?>">
    <button type="submit" class="favourite-btn<?= !empty($isFavourite) ? ' is-favourite' : '' ?>" title="<?= !empty($isFavourite) ? 'Remove from favourites' : 'Add to favourites' ?>" aria-label="<?= !empty($isFavourite) ? 'Remove from favourites' : 'Add to favourites' ?>"><?= !empty($isFavourite) ? '★' : '☆' ?></button>
</form>
<?php endif; ?>
