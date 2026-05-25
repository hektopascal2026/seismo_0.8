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
$timelineMediaCardClass = !empty($timelineMediaToggleFeature) && seismo_feed_item_is_timeline_media($item)
    ? ' entry-card--timeline-media'
    : '';
?>
                        <div class="entry-card<?= $timelineMediaCardClass ?>">
                            <div class="entry-header">
                                <?php if ($isParlPressFeed): ?>
                                    <?php
                                        $parlCommission = seismo_parl_press_commission_from_guid((string)($item['guid'] ?? ''));
                                        $parlCat = strtolower(trim((string)($item['feed_category'] ?? '')));
                                        $parlIsSda = strncmp((string)($item['guid'] ?? ''), 'parl_sda:', 9) === 0
                                            || $parlCat === 'parl_sda';
                                        $parlMetaLabel = $parlIsSda
                                            ? 'Session'
                                            : ($parlCommission !== '' ? $parlCommission : 'Medienmitteilung');
                                    ?>
                                    <span class="entry-tag entry-tag--parl"><?= $parlIsSda ? '🇨🇭 Parl SDA' : '🇨🇭 Parl MM' ?></span>
                                    <span class="entry-tag entry-tag--meta"><?= htmlspecialchars($parlMetaLabel) ?></span>
                                <?php else: ?>
                                    <?php $feedLabel = seismo_feed_item_pill_label($item); ?>
                                    <?php if ($feedLabel !== ''): ?>
                                        <span class="entry-tag <?= $feedCatTagClass ?>"><?= htmlspecialchars($feedLabel) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php require __DIR__ . '/entry_header_score_actions.php'; ?>
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
                                    <?php $feedTimelineDate = (string)($itemWrapper['clock_label'] ?? ''); ?>
                                    <?php if ($feedTimelineDate !== ''): ?>
                                        <span class="entry-date"><?= htmlspecialchars($feedTimelineDate) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
