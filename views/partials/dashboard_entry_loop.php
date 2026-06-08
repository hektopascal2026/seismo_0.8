<?php
/**
 * Dashboard card-loop partial — the polymorphic timeline renderer.
 *
 * DO NOT "TIDY" THIS FILE. It ports from 0.4 with one deliberate deviation:
 * the three helper calls were renamed to the `seismo_*` prefix (see
 * views/helpers.php). Everything else — including the use of htmlspecialchars()
 * instead of the global e() helper — is retained verbatim. The consistent
 * card layout across feed / email / Lex / Leg entry types is the product
 * achievement this partial defends; every style, class name, and branch here
 * was ground out against real content and should not be "modernised" casually.
 * Changes here must be matched to a consolidation-plan.md slice.
 *
 * Defensive rendering (Slice 1.5+): never wrap titles in &lt;a href=""&gt; or
 * href="#"; promote description→body when stripped content is empty; legacy
 * rows stay readable until fetchers enforce a normalisation contract (Slice 3+).
 *
 * @var string $csrfField From DashboardController (CSRF hidden input HTML)
 * @var bool $embedTimelineExpandAllInDayRow When true (index only), first day heading shares a row with "expand all".
 * @var bool $embedDashboardTimelineSearch When true (index + items), mobile search form is embedded in that row.
 * @var bool $timelineMediaToggleFeature When true, mark media-category feed cards for the index Media toggle.
 * @var bool $showTimelineMediaToggle When true, show Media visibility toggle (main index timeline).
 * @var bool $showTimelineFavouritesToggle When true, show ★ favs link beside expand all (index / filter preview).
 * @var bool $timelineFavouritesOn
 * @var string $timelineFavouritesToggleHref
 * @var bool $showTimelineHighlightsSortToggle When true, show highest-sort toggle (Highlights page).
 * @var bool $timelineHighlightsSortHighestOn
 * @var string $timelineHighlightsSortToggleHref
 */
$searchQuery = $searchQuery ?? '';
if (!isset($showFavourites)) {
    $showFavourites = true;
}
$returnQuery = $returnQuery ?? ($_SERVER['QUERY_STRING'] ?? 'action=index');
$showDaySeparators = !empty($showDaySeparators);
$feedLoopPrevDayKey = null;
$embedTimelineExpandAllInDayRow = !empty($embedTimelineExpandAllInDayRow);
$embedDashboardTimelineSearch   = !empty($embedDashboardTimelineSearch);
$timelineExpandAllInDayRowDone  = false;
$entryLoopIndex                 = 0;
$renderNestedDigestStories      = !empty($renderNestedDigestStories);
?>
                <?php foreach ($allItems as $itemWrapper): ?>
                    <?php
                        // Magnitu score data for this entry (badge only, no explanation on index)
                        $entryScore = $itemWrapper['score'] ?? null;
                        $relevanceScore = $entryScore ? (float)$entryScore['relevance_score'] : null;
                        $predictedLabel = $entryScore['predicted_label'] ?? null;
                        $scoreBadgeClass = '';
                        if ($relevanceScore !== null) {
                            $scoreBadgeClass = \Seismo\Core\MagnituScoreBands::badgeCssClass((float)$relevanceScore);
                        }
                        $favouriteEntryType = $itemWrapper['entry_type'] ?? '';
                        $favouriteEntryId = (int)($itemWrapper['entry_id'] ?? 0);
                        $isFavourite = !empty($itemWrapper['is_favourite']);
                    ?>
                    <?php if ($showDaySeparators): ?>
                        <?php
                            $__ts = (int)($itemWrapper['date'] ?? 0);
                            $__dk = $__ts > 0 ? seismo_timeline_day_key_in_view_tz($__ts) : '';
                            if ($__dk !== '' && ($feedLoopPrevDayKey === null || $feedLoopPrevDayKey !== $__dk)) {
                                $__h = seismo_magnitu_day_heading($__ts);
                                if ($__h !== '') {
                                    if ($embedTimelineExpandAllInDayRow && !$timelineExpandAllInDayRowDone) {
                                        $timelineExpandAllInDayRowDone = true;
                                        $rowClass = 'timeline-day-row' . ($embedDashboardTimelineSearch ? ' timeline-day-row--with-inline-search' : '');
                                        echo '<div class="' . $rowClass . '"><div class="magnitu-day-separator"><span class="magnitu-day-separator-text">' . htmlspecialchars($__h) . '</span></div>';
                                        if ($embedDashboardTimelineSearch) {
                                            echo '<div class="timeline-inline-search">';
                                            $inlineTimeline = true;
                                            require __DIR__ . '/dashboard_index_search_form.php';
                                            echo '</div>';
                                        }
                                        require __DIR__ . '/timeline_day_row_actions.php';
                                        echo '</div>';
                                    } else {
                                        echo '<div class="magnitu-day-separator"><span class="magnitu-day-separator-text">' . htmlspecialchars($__h) . '</span></div>';
                                    }
                                }
                            }
                            if ($__dk !== '') {
                                $feedLoopPrevDayKey = $__dk;
                            }
                        ?>
                    <?php endif; ?>
                    <?php
                    if ($embedTimelineExpandAllInDayRow && !$timelineExpandAllInDayRowDone && $entryLoopIndex === 0) {
                        $rowClass = 'timeline-day-row timeline-day-row--expand-only' . ($embedDashboardTimelineSearch ? ' timeline-day-row--with-inline-search' : '');
                        echo '<div class="' . $rowClass . '">';
                        if ($embedDashboardTimelineSearch) {
                            echo '<div class="timeline-inline-search">';
                            $inlineTimeline = true;
                            require __DIR__ . '/dashboard_index_search_form.php';
                            echo '</div>';
                        }
                        require __DIR__ . '/timeline_day_row_actions.php';
                        echo '</div>';
                        $timelineExpandAllInDayRowDone = true;
                    }
                    ?>
                    <?php if ($itemWrapper['type'] === 'feed' || $itemWrapper['type'] === 'substack'): ?>
                        <?php require __DIR__ . '/entry_card_rss_substack.php'; ?>
                    <?php elseif ($itemWrapper['type'] === 'scraper'): ?>
                        <?php require __DIR__ . '/entry_card_scraper.php'; ?>
                    <?php elseif ($itemWrapper['type'] === 'lex'): ?>
                        <?php $lexItem = $itemWrapper['data']; ?>
                        <?php
                            $lexSource = (string)($lexItem['source'] ?? 'eu');
                            $lexPillParts = seismo_lex_source_pill_parts($lexSource);
                            $lexSourceEmoji = $lexPillParts['emoji'];
                            $lexSourceLabel = $lexPillParts['label'];
                            $lexDocType = $lexItem['document_type'] ?? 'Legislation';
                            if (($lexSource === 'eu')) {
                                $lexDocType = function_exists('seismo_lex_eu_document_type_for_display')
                                    ? seismo_lex_eu_document_type_for_display($lexItem)
                                    : ($lexDocType !== '' ? $lexDocType : 'EU legislation');
                            } elseif ($lexSource === 'ch' && function_exists('seismo_lex_ch_document_type_for_display')) {
                                $lexDocType = seismo_lex_ch_document_type_for_display($lexItem);
                            }
                            $lexUrl = trim((string)($lexItem['eurlex_url'] ?? ''));
                            if ($lexUrl === '') {
                                $lexUrl = trim((string)($lexItem['work_uri'] ?? ''));
                            }
                            $lexHasUrl = seismo_is_navigable_url($lexUrl);
                            $lexDate = (string)($itemWrapper['clock_label'] ?? '');
                            $isJus = in_array($lexSource, ['ch_bger', 'ch_bge', 'ch_bvger']);
                            
                            // For JUS items: parse readable case number from slug
                            $lexCelexRaw = (string)($lexItem['celex'] ?? '');
                            $lexCelexDisplay = $lexCelexRaw;
                            if ($lexSource === 'ch_bge' && preg_match('/^\d+-[IVX]+-\d+$/i', $lexCelexRaw)) {
                                $lexCelexDisplay = seismo_lex_bge_celex_for_display($lexCelexRaw);
                            }
                            if ($isJus && preg_match('/^CH_(?:BGer|BGE|BVGE)_\d{3}_(.+)_\d{4}-\d{2}-\d{2}$/', $lexCelexDisplay, $m)) {
                                $rawCn = $m[1];
                                $isBVGer = (strpos($lexCelexDisplay, 'CH_BVGE_') === 0);
                                $lastDash = strrpos($rawCn, '-');
                                if ($lastDash !== false) {
                                    $prefix = substr($rawCn, 0, $lastDash);
                                    $year = substr($rawCn, $lastDash + 1);
                                    $lexCelexDisplay = $isBVGer 
                                        ? $prefix . '/' . $year 
                                        : str_replace('-', ' ', $prefix) . '/' . $year;
                                } else {
                                    $lexCelexDisplay = $rawCn;
                                }
                            }
                            
                            // Link label per source
                            if ($lexSource === 'ch_bger') $lexLinkLabel = 'Entscheid →';
                            elseif ($lexSource === 'ch_bge') $lexLinkLabel = 'Leitentscheid →';
                            elseif ($lexSource === 'ch_bvger') $lexLinkLabel = 'Urteil →';
                            elseif ($lexSource === 'de') $lexLinkLabel = 'recht.bund.de →';
                            elseif ($lexSource === 'ch') $lexLinkLabel = 'Fedlex →';
                            elseif ($lexSource === 'fr') $lexLinkLabel = 'Légifrance →';
                            else {
                                $lexLinkLabel = 'EUR-Lex →';
                            }

                            $celexForParl = (string)($lexItem['celex'] ?? '');
                            $isParlSwissLex = (bool) preg_match('/^parl_(mm|sda):/i', $celexForParl)
                                || in_array($lexSource, ['parl_mm', 'parl_sda'], true);
                            if ($isParlSwissLex) {
                                $lexLinkLabel = 'parlament.ch →';
                            }

                            $lexDesc = function_exists('seismo_lex_card_preview_text')
                                ? seismo_lex_card_preview_text($lexItem)
                                : trim((string)($lexItem['description'] ?? ''));
                            $lexPreview = mb_substr($lexDesc, 0, 300);
                            if (mb_strlen($lexDesc) > 300) $lexPreview .= '...';
                            $lexHasMore = mb_strlen($lexDesc) > 300;
                            $isEuLexCard = ($lexSource === 'eu' && !$isParlSwissLex);
                            $isChFedlexCard = ($lexSource === 'ch' && !$isParlSwissLex);
                            $useLexJurisdictionHeader = $isParlSwissLex || $isEuLexCard || $isChFedlexCard;
                            $lexHeadingTitle = function_exists('seismo_lex_card_heading_title')
                                ? seismo_lex_card_heading_title($lexItem)
                                : trim((string)($lexItem['title'] ?? ''));
                            $lexSkipDescPreview = ($lexHeadingTitle !== '' && $lexDesc !== '' && $lexHeadingTitle === $lexDesc);

                            $lexFooterMonoHide = ($lexSource === 'eu' && !$isParlSwissLex)
                                || ($lexSource === 'de' && str_starts_with($lexCelexRaw, 'de_rss_'))
                                || ($lexSource === 'fr' && preg_match('/^JORFTEXT[0-9]+/i', $lexCelexRaw))
                                || ($lexSource === 'ch' && !$isParlSwissLex)
                                || seismo_lex_bge_footer_mono_hide($lexSource, $lexCelexRaw, $lexHeadingTitle);
                        ?>
                        <div class="entry-card">
                            <?php if ($useLexJurisdictionHeader): ?>
                            <div class="entry-header entry-header--lex-eu">
                                <div class="entry-header--lex-eu-left">
                                    <?php if ($isEuLexCard): ?>
                                    <span class="entry-lex-eu-mark" title="EUR-Lex (EU)"><span class="entry-lex-eu-mark__flag" aria-hidden="true">🇪🇺</span><span class="entry-lex-eu-mark__text">EU</span></span>
                                    <?php else: ?>
                                    <span class="entry-lex-ch-mark" title="<?= $isParlSwissLex ? 'Bundeshaus Medien (Schweiz)' : 'Fedlex (Schweiz)' ?>"><span class="entry-lex-ch-mark__flag" aria-hidden="true">🇨🇭</span><span class="entry-lex-ch-mark__text">CH</span></span>
                                    <?php endif; ?>
                                    <span class="entry-lex-eu-doc-type"><?= htmlspecialchars($lexDocType) ?></span>
                                </div>
                                <div class="entry-header--lex-eu-right">
                                    <?php require __DIR__ . '/entry_header_score_actions.php'; ?>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="entry-header">
                                <span class="entry-tag entry-tag--lex-source"><span class="entry-tag-emoji" aria-hidden="true"><?= $lexSourceEmoji ?></span> <span class="entry-tag-text"><?= htmlspecialchars($lexSourceLabel) ?></span></span>
                                <span class="entry-tag entry-tag--lex-doc"><?= htmlspecialchars($lexDocType) ?></span>
                                <?php require __DIR__ . '/entry_header_score_actions.php'; ?>
                            </div>
                            <?php endif; ?>
                            <h3 class="entry-title">
                                <?php if ($lexHasUrl): ?>
                                    <a href="<?= htmlspecialchars($lexUrl) ?>" target="_blank" rel="noopener">
                                        <?php if (!empty($searchQuery)): ?>
                                            <?= seismo_highlight_search_term($lexHeadingTitle, $searchQuery) ?>
                                        <?php else: ?>
                                            <?= htmlspecialchars($lexHeadingTitle) ?>
                                        <?php endif; ?>
                                    </a>
                                <?php else: ?>
                                    <?php if (!empty($searchQuery)): ?>
                                        <?= seismo_highlight_search_term($lexHeadingTitle, $searchQuery) ?>
                                    <?php else: ?>
                                        <?= htmlspecialchars($lexHeadingTitle) ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </h3>
                            <?php if (!empty($lexDesc) && !$lexSkipDescPreview): ?>
                                <div class="entry-content entry-preview">
                                    <?= nl2br(htmlspecialchars($lexPreview)) ?>
                                    <?php if ($lexHasUrl): ?>
                                        <a href="<?= htmlspecialchars($lexUrl) ?>" target="_blank" rel="noopener" class="entry-link entry-link--after-preview"><?= $lexLinkLabel ?></a>
                                    <?php endif; ?>
                                </div>
                                <?php if ($lexHasMore): ?>
                                    <div class="entry-full-content"><?= nl2br(htmlspecialchars($lexDesc)) ?></div>
                                <?php endif; ?>
                            <?php elseif ($lexHasUrl): ?>
                                <div class="entry-content entry-preview">
                                    <a href="<?= htmlspecialchars($lexUrl) ?>" target="_blank" rel="noopener" class="entry-link entry-link--after-preview"><?= $lexLinkLabel ?></a>
                                </div>
                            <?php endif; ?>
                            <div class="entry-actions">
                                <div class="entry-actions-main">
                                    <?php if (!empty($lexDesc) && $lexHasMore && !$lexSkipDescPreview): ?>
                                        <button class="btn btn-secondary entry-expand-btn">expand &#9662;</button>
                                    <?php endif; ?>
                                    <?php if (!$lexFooterMonoHide): ?>
                                    <span class="entry-meta-mono<?= $isJus ? ' entry-meta-mono--jus' : '' ?>"><?= htmlspecialchars((string)$lexCelexDisplay) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="entry-meta-right">
                                    <?php if ($lexDate): ?>
                                        <span class="entry-date"><?= $lexDate ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php elseif ($itemWrapper['type'] === 'calendar'): ?>
                        <?php $calEvent = $itemWrapper['data']; ?>
                        <?php
                            $calTypeLabel = seismo_calendar_event_type_label($calEvent['event_type'] ?? '');
                            $calCouncil = seismo_council_label($calEvent['council'] ?? '');
                            $calUrl = trim((string)($calEvent['url'] ?? ''));
                            $calHasUrl = seismo_is_navigable_url($calUrl);
                            $calDateLabel = (string)($itemWrapper['clock_label'] ?? '');
                            $calDesc = seismo_calendar_event_body_text($calEvent);
                            $calPreview = mb_substr($calDesc, 0, 300);
                            $calHasMore = mb_strlen($calDesc) > 300;
                            if ($calHasMore) {
                                $calPreview .= '...';
                            }
                            $calSignal = seismo_leg_parl_ch_signal($calEvent);
                            $calSignalLabel = $calSignal !== null
                                ? \Seismo\Util\ParlChLegSignal::signalLabel($calSignal)
                                : '';
                            $calStatusRaw = (string)($calEvent['status'] ?? 'scheduled');
                            $calStatusLabel = ucfirst($calStatusRaw);
                        ?>
                        <div class="entry-card">
                            <div class="entry-header">
                                <span class="entry-tag entry-tag--leg-type"><?= htmlspecialchars($calTypeLabel) ?></span>
                                <?php if ($calCouncil): ?>
                                    <span class="entry-tag entry-tag--leg-council"><?= htmlspecialchars($calCouncil) ?></span>
                                <?php endif; ?>
                                <?php if ($calSignalLabel !== ''): ?>
                                    <span class="entry-tag entry-tag--leg-signal"><?= htmlspecialchars($calSignalLabel) ?></span>
                                <?php elseif ($calStatusRaw !== 'scheduled'): ?>
                                    <?php
                                    $calStatusTagClass = $calStatusRaw === 'completed'
                                        ? 'entry-tag--status-completed'
                                        : ($calStatusRaw === 'cancelled' ? 'entry-tag--status-cancelled' : 'entry-tag--status-other');
                                    ?>
                                    <span class="entry-tag <?= htmlspecialchars($calStatusTagClass) ?>"><?= htmlspecialchars($calStatusLabel) ?></span>
                                <?php endif; ?>
                                <?php require __DIR__ . '/entry_header_score_actions.php'; ?>
                            </div>
                            <h3 class="entry-title">
                                <?php if ($calHasUrl): ?>
                                    <a href="<?= htmlspecialchars($calUrl) ?>" target="_blank" rel="noopener">
                                        <?= htmlspecialchars($calEvent['title']) ?>
                                    </a>
                                <?php else: ?>
                                    <?= htmlspecialchars($calEvent['title']) ?>
                                <?php endif; ?>
                            </h3>
                            <?php if ($calPreview !== ''): ?>
                                <div class="entry-content entry-preview"><?= nl2br(htmlspecialchars($calPreview, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
                                <?php if ($calHasMore): ?>
                                    <div class="entry-full-content"><?= nl2br(htmlspecialchars($calDesc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <div class="entry-actions">
                                <div class="entry-actions-main">
                                    <?php if ($calHasMore): ?>
                                        <button class="btn btn-secondary entry-expand-btn">expand &#9662;</button>
                                    <?php endif; ?>
                                    <?php if ($calHasUrl): ?>
                                    <a href="<?= htmlspecialchars($calUrl) ?>" target="_blank" rel="noopener" class="entry-link">parlament.ch &rarr;</a>
                                    <?php endif; ?>
                                </div>
                                <div class="entry-meta-right">
                                    <?php if ($calDateLabel): ?>
                                        <span class="entry-date"><?= htmlspecialchars($calDateLabel) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php $email = $itemWrapper['data']; ?>
                        <?php
                            $createdAt = (string)($itemWrapper['clock_label'] ?? '');
                            
                            $fromName = trim((string)($email['from_name'] ?? ''));
                            $fromEmail = trim((string)($email['from_email'] ?? ''));
                            $fromDisplay = $fromName !== '' ? $fromName : ($fromEmail !== '' ? $fromEmail : 'Unknown sender');

                            $subject = trim((string)($email['subject'] ?? ''));
                            if ($subject === '') {
                                $subject = '(No subject)';
                            }
                            $displayTitle = seismo_email_display_title($email);

                            $body = seismo_email_plain_body_for_display($email);
                            $body = seismo_strip_email_listing_boilerplate(
                                $body,
                                $fromEmail,
                                $subject,
                                !empty($email['subscription_strip_listing_boilerplate'])
                            );
                            $bodyDisplay = $body !== '' ? seismo_format_email_body_for_display($body) : '';
                            if ($bodyDisplay === '') {
                                $bodyPreview = '';
                            } else {
                                $previewFlat = trim(preg_replace('/\s+/', ' ', $bodyDisplay) ?? '');
                                $bodyPreview = mb_substr($previewFlat, 0, 200);
                                if (mb_strlen($previewFlat) > 200) {
                                    $bodyPreview .= '...';
                                }
                            }
                            $hasMore = $bodyDisplay !== '' && mb_strlen(trim(preg_replace('/\s+/', ' ', $bodyDisplay) ?? '')) > 200;
                            $digestChildren = is_array($email['child_stories'] ?? null) ? $email['child_stories'] : [];
                            $digestChildCount = count($digestChildren);
                            if ($renderNestedDigestStories && $digestChildCount > 0) {
                                $bodyPreview = $digestChildCount . ' '
                                    . ($digestChildCount === 1 ? 'story' : 'stories')
                                    . ' in this digest — see below.';
                                $bodyDisplay = '';
                                $hasMore = false;
                            }
                            $webViewUrl = seismo_email_web_view_url($email);
                            if ($webViewUrl !== null && $bodyPreview !== '') {
                                $bodyPreview = seismo_trim_email_preview_for_webview_link($bodyPreview);
                            }
                            if ($renderNestedDigestStories && $digestChildCount > 0) {
                                foreach ($digestChildren as $digestChildRow) {
                                    $digestChildMeta = $digestChildRow['metadata'] ?? null;
                                    if (is_string($digestChildMeta)) {
                                        $digestChildMeta = json_decode($digestChildMeta, true);
                                    }
                                    if (!is_array($digestChildMeta)) {
                                        continue;
                                    }
                                    $digestChildWebView = trim((string)($digestChildMeta['link'] ?? $digestChildMeta['web_view_url'] ?? ''));
                                    if ($digestChildWebView !== '' && seismo_is_navigable_url($digestChildWebView)) {
                                        $webViewUrl = $digestChildWebView;
                                        break;
                                    }
                                }
                            }
                            $isDigestChildEmail = !empty($email['parent_email_id']);
                        ?>
                        <div class="entry-card">
                            <div class="entry-header">
                                <?php
                                    $subLabel = trim((string)($email['subscription_display_name'] ?? ''));
                                    $legacySenderTag = !empty($email['sender_tag']) && $email['sender_tag'] !== 'unclassified';
                                ?>
                                <?php
                                    $emailTagClass = (($email['subscription_module_scope'] ?? '') === 'newsletter')
                                        ? 'entry-tag--newsletter-sender'
                                        : 'entry-tag--email-sender';
                                ?>
                                <?php if ($subLabel !== ''): ?>
                                    <span class="entry-tag <?= e($emailTagClass) ?>"><?= htmlspecialchars($subLabel) ?></span>
                                <?php elseif ($legacySenderTag): ?>
                                    <span class="entry-tag <?= e($emailTagClass) ?>"><?= htmlspecialchars($email['sender_tag']) ?></span>
                                <?php endif; ?>
                                <?php if ($isDigestChildEmail): ?>
                                    <?php
                                        $storyIndex = null;
                                        $emailMeta = $email['metadata'] ?? null;
                                        if (is_string($emailMeta)) {
                                            $emailMeta = json_decode($emailMeta, true);
                                        }
                                        if (is_array($emailMeta) && isset($emailMeta['story_index'])) {
                                            $storyIndex = (int)$emailMeta['story_index'];
                                        } elseif (!empty($email['message_id']) && preg_match('/_story_(\d+)$/', (string)$email['message_id'], $matches)) {
                                            $storyIndex = (int)$matches[1];
                                        }
                                        $tagText = $storyIndex !== null ? '#' . ($storyIndex + 1) : 'Digest story';
                                    ?>
                                    <span class="entry-tag entry-tag--digest-story"><?= htmlspecialchars($tagText) ?></span>
                                <?php endif; ?>
                                <?php require __DIR__ . '/entry_header_score_actions.php'; ?>
                            </div>
                            <h3 class="entry-title">
                                <?php if (!empty($searchQuery)): ?>
                                    <?= seismo_highlight_search_term($displayTitle, $searchQuery) ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($displayTitle) ?>
                                <?php endif; ?>
                            </h3>
                            <div class="entry-content entry-preview">
                                <?php if ($bodyPreview === '' && $body === ''): ?>
                                    <span class="entry-muted">(No body text)</span>
                                    <?php if ($webViewUrl !== null): ?>
                                        <a href="<?= htmlspecialchars($webViewUrl) ?>" target="_blank" rel="noopener" class="entry-link entry-link--after-preview">View in browser &rarr;</a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($bodyPreview !== ''): ?>
                                        <span class="entry-preview-text"><?php
                                            if (!empty($searchQuery)) {
                                                echo seismo_highlight_search_term($bodyPreview, $searchQuery);
                                            } else {
                                                echo htmlspecialchars($bodyPreview);
                                            }
                                        ?></span><?php if ($webViewUrl !== null): ?> <a href="<?= htmlspecialchars($webViewUrl) ?>" target="_blank" rel="noopener" class="entry-link entry-link--after-preview">View in browser &rarr;</a><?php endif; ?>
                                    <?php elseif ($webViewUrl !== null): ?>
                                        <a href="<?= htmlspecialchars($webViewUrl) ?>" target="_blank" rel="noopener" class="entry-link entry-link--after-preview">View in browser &rarr;</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($renderNestedDigestStories && !empty($email['child_stories'])): ?>
                                <div class="digest-child-stories" aria-label="Stories in this digest">
                                    <?php foreach ($email['child_stories'] as $child): ?>
                                        <?php
                                            $childScore = $child['score'] ?? null;
                                            $childRelScore = $childScore ? (float)$childScore['relevance_score'] : null;
                                            $childScoreBadgeClass = $childRelScore !== null ? \Seismo\Core\MagnituScoreBands::badgeCssClass($childRelScore) : '';
                                            
                                            $childTitle = trim((string)($child['derived_title'] ?? $child['subject'] ?? ''));
                                            $childBody = seismo_email_plain_body_for_display($child);
                                            $childBodyDisplay = $childBody !== '' ? seismo_format_email_body_for_display($childBody) : '';
                                            if ($childBodyDisplay === '') {
                                                $childPreview = '';
                                                $childHasMore = false;
                                            } else {
                                                $childPreviewFlat = trim(preg_replace('/\s+/u', ' ', $childBodyDisplay) ?? '');
                                                $childPreview = mb_substr($childPreviewFlat, 0, 200);
                                                if (mb_strlen($childPreviewFlat) > 200) {
                                                    $childPreview .= '...';
                                                }
                                                $childHasMore = mb_strlen($childPreviewFlat) > 200;
                                            }
                                            
                                        ?>
                                        <div class="digest-child-item">
                                            <div class="digest-child-item__head">
                                                <h4 class="digest-child-item__title"><?= htmlspecialchars($childTitle) ?></h4>
                                                <?php if ($childRelScore !== null): ?>
                                                    <span class="magnitu-badge <?= htmlspecialchars($childScoreBadgeClass) ?>"><?= number_format($childRelScore * 100, 0) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($childPreview !== '' || $childBodyDisplay !== ''): ?>
                                                <div class="entry-content entry-preview digest-child-item__body">
                                                    <?php if ($childPreview === ''): ?>
                                                        <span class="entry-muted">(No body text)</span>
                                                    <?php elseif (!empty($searchQuery)): ?>
                                                        <?= seismo_highlight_search_term($childPreview, $searchQuery) ?>
                                                    <?php else: ?>
                                                        <?= htmlspecialchars($childPreview) ?>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($childHasMore): ?>
                                                    <div class="entry-full-content"><?= htmlspecialchars($childBodyDisplay) ?></div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($childHasMore): ?>
                                            <div class="digest-child-item__actions entry-actions">
                                                <div class="entry-actions-main">
                                                    <button type="button" class="btn btn-secondary entry-expand-btn">expand &#9662;</button>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($digestChildCount === 0 || !$renderNestedDigestStories): ?>
                            <div class="entry-full-content"><?= htmlspecialchars($bodyDisplay) ?></div>
                            <?php endif; ?>
                            <div class="entry-actions">
                                <div class="entry-actions-main">
                                    <?php if ($hasMore): ?>
                                        <button class="btn btn-secondary entry-expand-btn">expand &#9662;</button>
                                    <?php endif; ?>
                                    <?php if ($webViewUrl !== null && $bodyPreview === ''): ?>
                                        <a href="<?= htmlspecialchars($webViewUrl) ?>" target="_blank" rel="noopener" class="entry-link">View in browser &rarr;</a>
                                    <?php endif; ?>
                                </div>
                                <div class="entry-meta-right">
                                    <?php if ($createdAt): ?>
                                        <span class="entry-date"><?= htmlspecialchars($createdAt) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php $entryLoopIndex++; ?>
                <?php endforeach; ?>
