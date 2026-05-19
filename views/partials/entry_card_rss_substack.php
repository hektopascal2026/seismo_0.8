<?php
/**
 * Single RSS / Substack (non–parl_press) feed_item card — used by
 * {@see views/partials/dashboard_entry_loop.php} and {@see FeedController::preview()}.
 *
 * Expects the same outer scope as the original dashboard loop branch:
 * - $itemWrapper (type `feed` or `substack`)
 * - $relevanceScore, $predictedLabel, $scoreBadgeClass
 * - $favouriteEntryType, $favouriteEntryId, $isFavourite, $searchQuery, $returnQuery, $showFavourites, $csrfField
 *
 * @var array<string, mixed> $itemWrapper
 */
declare(strict_types=1);

$item = $itemWrapper['data'];
$feedSourceType = (string)($item['feed_source_type'] ?? '');
$isParlPressFeed = ($feedSourceType === 'parl_press');
$itemUrl = seismo_feed_item_resolved_link($item);
$fromContent = trim(strip_tags((string)($item['content'] ?? '')));
$fromDesc = trim(strip_tags((string)($item['description'] ?? '')));
$fullContent = $fromContent !== '' ? $fromContent : $fromDesc;
if ($fullContent === '' && !empty($item['title'])) {
    $fullContent = trim((string)$item['title']);
}
$contentPreview = mb_substr($fullContent, 0, 200);
if (mb_strlen($fullContent) > 200) {
    $contentPreview .= '...';
}
$hasMore = mb_strlen($fullContent) > 200;
$feedCatTagClass = ($itemWrapper['type'] === 'substack') ? 'entry-tag--feed-substack' : 'entry-tag--feed-rss';
?>
                        <div class="entry-card">
                            <div class="entry-header">
                                <?php if ($isParlPressFeed): ?>
                                    <?php
                                        $parlCommission = seismo_parl_press_commission_from_guid((string)($item['guid'] ?? ''));
                                        $parlCat = strtolower(trim((string)($item['feed_category'] ?? '')));
                                        $parlIsSda = strncmp((string)($item['guid'] ?? ''), 'parl_sda:', 9) === 0
                                            || $parlCat === 'parl_sda';
                                    ?>
                                    <span class="entry-tag entry-tag--parl"><?= $parlIsSda ? '🇨🇭 SDA' : '🇨🇭 Parl MM' ?></span>
                                    <?php if ($parlCommission !== ''): ?>
                                        <span class="entry-tag entry-tag--meta"><?= htmlspecialchars($parlCommission) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php
                                        $feedCategory = trim((string)($item['feed_category'] ?? ''));
                                        $feedLabel = '';
                                        if ($feedCategory !== '' && $feedCategory !== 'unsortiert') {
                                            $feedLabel = $feedCategory;
                                        } else {
                                            $feedLabel = trim((string)($item['feed_title'] ?? ''));
                                            if ($feedLabel === '') {
                                                $feedLabel = trim((string)($item['feed_name'] ?? ''));
                                            }
                                        }
                                        if (mb_strlen($feedLabel) > 32) {
                                            $feedLabel = mb_substr($feedLabel, 0, 32) . '…';
                                        }
                                    ?>
                                    <?php if ($feedLabel !== ''): ?>
                                        <span class="entry-tag <?= $feedCatTagClass ?>"><?= htmlspecialchars($feedLabel) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($relevanceScore !== null): ?>
                                    <span class="magnitu-badge <?= $scoreBadgeClass ?>" title="<?= htmlspecialchars($predictedLabel ?? '') ?> (<?= round($relevanceScore * 100) ?>%)"><?= round($relevanceScore * 100) ?></span>
                                <?php endif; ?>
                            </div>
                            <h3 class="entry-title">
                                <?php if (seismo_is_navigable_url($itemUrl)): ?>
                                    <a href="<?= htmlspecialchars($itemUrl) ?>" target="_blank" rel="noopener">
                                        <?php if (!empty($searchQuery)): ?>
                                            <?= seismo_highlight_search_term($item['title'], $searchQuery) ?>
                                        <?php else: ?>
                                            <?= htmlspecialchars($item['title']) ?>
                                        <?php endif; ?>
                                    </a>
                                <?php else: ?>
                                    <?php if (!empty($searchQuery)): ?>
                                        <?= seismo_highlight_search_term($item['title'], $searchQuery) ?>
                                    <?php else: ?>
                                        <?= htmlspecialchars($item['title']) ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </h3>
                            <?php if ($fullContent !== ''): ?>
                                <div class="entry-content entry-preview">
                                    <?php
                                    if (!empty($searchQuery)) {
                                        echo seismo_highlight_search_term($contentPreview, $searchQuery);
                                    } else {
                                        echo htmlspecialchars($contentPreview);
                                    }
                                    ?>
                                    <?php if (seismo_is_navigable_url($itemUrl)): ?>
                                        <a href="<?= htmlspecialchars($itemUrl) ?>" target="_blank" rel="noopener" class="entry-link entry-link--after-preview">Read more →</a>
                                    <?php endif; ?>
                                </div>
                                <div class="entry-full-content"><?= htmlspecialchars($fullContent) ?></div>
                            <?php endif; ?>
                            <div class="entry-actions">
                                <div class="entry-actions-main">
                                    <?php if ($hasMore): ?>
                                        <button class="btn btn-secondary entry-expand-btn">expand &#9660;</button>
                                    <?php endif; ?>
                                </div>
                                <div class="entry-meta-right">
                                    <?php if (!empty($item['published_date'])): ?>
                                        <span class="entry-date"><?= date('d.m.Y H:i', strtotime((string)$item['published_date'])) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($showFavourites)): ?>
                                    <form method="POST" action="?action=toggle_favourite" class="favourite-form">
                                        <?= $csrfField ?>
                                        <input type="hidden" name="entry_type" value="<?= htmlspecialchars($favouriteEntryType) ?>">
                                        <input type="hidden" name="entry_id" value="<?= $favouriteEntryId ?>">
                                        <input type="hidden" name="return_query" value="<?= htmlspecialchars($returnQuery) ?>">
                                        <button type="submit" class="favourite-btn<?= $isFavourite ? ' is-favourite' : '' ?>" title="<?= $isFavourite ? 'Remove from favourites' : 'Add to favourites' ?>" aria-label="<?= $isFavourite ? 'Remove from favourites' : 'Add to favourites' ?>"><?= $isFavourite ? '★' : '☆' ?></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
