<?php
/**
 * Single scraper feed_item card — used by the dashboard loop and Scraper preview (dry run).
 *
 * Expects the same outer scope as the original {@see dashboard_entry_loop.php} branch:
 * - $itemWrapper (type scraper), with score / entry_type / entry_id / is_favourite
 * - $relevanceScore, $predictedLabel, $scoreBadgeClass
 * - $favouriteEntryType, $favouriteEntryId, $isFavourite
 * - $csrfField, $returnQuery, $searchQuery, $showFavourites
 *
 * @var array<string, mixed> $itemWrapper
 */
declare(strict_types=1);

$item = $itemWrapper['data'];
$scraperLink = seismo_feed_item_resolved_link($item);
$rawSc = trim(strip_tags((string)($item['content'] ?? '')));
$rawDesc = trim(strip_tags((string)($item['description'] ?? '')));
$scraperContent = $rawSc !== '' ? $rawSc : $rawDesc;
if ($scraperContent === '' && !empty($item['title'])) {
    $scraperContent = trim((string)$item['title']);
}
$scraperPreview = mb_substr($scraperContent, 0, 200);
if (mb_strlen($scraperContent) > 200) {
    $scraperPreview .= '...';
}
$scraperHasMore = mb_strlen($scraperContent) > 200;
$isTimelineMediaEntry = !empty($itemWrapper['timeline_media']);
$timelineMediaCardClass = '';
$timelineMediaDataAttr  = '';
if (!empty($timelineMediaToggleFeature) && $isTimelineMediaEntry) {
    $timelineMediaCardClass = ' entry-card--timeline-media';
    $timelineMediaDataAttr  = ' data-timeline-media="1"';
}
?>
                        <div class="entry-card<?= $timelineMediaCardClass ?>"<?= $timelineMediaDataAttr ?>>
                            <div class="entry-header">
                                <span class="entry-tag entry-tag--scraper">🌐 <?= htmlspecialchars((string)($item['feed_name'] ?? 'Scraper')) ?></span>
                                <?php require __DIR__ . '/entry_header_score_actions.php'; ?>
                            </div>
                            <h3 class="entry-title">
                                <?php if (seismo_is_navigable_url($scraperLink)): ?>
                                    <a href="<?= htmlspecialchars($scraperLink) ?>" target="_blank" rel="noopener">
                                        <?= htmlspecialchars((string)($item['title'] ?? '')) ?>
                                    </a>
                                <?php else: ?>
                                    <?= htmlspecialchars((string)($item['title'] ?? '')) ?>
                                <?php endif; ?>
                            </h3>
                            <?php if (!empty($scraperContent)): ?>
                                <div class="entry-content entry-preview">
                                    <?php if (!empty($searchQuery)): ?>
                                        <?= seismo_highlight_search_term($scraperPreview, (string)$searchQuery) ?>
                                    <?php else: ?>
                                        <?= htmlspecialchars($scraperPreview) ?>
                                    <?php endif; ?>
                                    <?php if (seismo_is_navigable_url($scraperLink)): ?>
                                    <a href="<?= htmlspecialchars($scraperLink) ?>" target="_blank" rel="noopener" class="entry-link entry-link--after-preview">Open page &rarr;</a>
                                    <?php endif; ?>
                                </div>
                                <div class="entry-full-content"><?= htmlspecialchars($scraperContent) ?></div>
                            <?php endif; ?>
                            <div class="entry-actions">
                                <div class="entry-actions-main">
                                    <?php if ($scraperHasMore): ?>
                                        <button type="button" class="btn btn-secondary entry-expand-btn">expand &#9660;</button>
                                    <?php endif; ?>
                                </div>
                                <div class="entry-meta-right">
                                    <?php $scraperTimelineDate = (string)($itemWrapper['clock_label'] ?? ''); ?>
                                    <?php if ($scraperTimelineDate !== ''): ?>
                                        <span class="entry-date"><?= htmlspecialchars($scraperTimelineDate) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
