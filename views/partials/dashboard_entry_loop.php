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
?>
                <?php foreach ($allItems as $itemWrapper): ?>
                    <?php
                        // Magnitu score data for this entry (badge only, no explanation on index)
                        $entryScore = $itemWrapper['score'] ?? null;
                        $relevanceScore = $entryScore ? (float)$entryScore['relevance_score'] : null;
                        $predictedLabel = $entryScore['predicted_label'] ?? null;
                        $scoreBadgeClass = '';
                        if ($relevanceScore !== null) {
                            $scorePercent = (int)round($relevanceScore * 100);
                            if ($scorePercent <= 25) {
                                $scoreBadgeClass = 'magnitu-badge-noise';
                            } elseif ($scorePercent <= 50) {
                                $scoreBadgeClass = 'magnitu-badge-background';
                            } elseif ($scorePercent <= 75) {
                                $scoreBadgeClass = 'magnitu-badge-important';
                            } else {
                                $scoreBadgeClass = 'magnitu-badge-investigation';
                            }
                        }
                        $favouriteEntryType = $itemWrapper['entry_type'] ?? '';
                        $favouriteEntryId = (int)($itemWrapper['entry_id'] ?? 0);
                        $isFavourite = !empty($itemWrapper['is_favourite']);
                    ?>
                    <?php if ($showDaySeparators): ?>
                        <?php
                            $__ts = (int)($itemWrapper['date'] ?? 0);
                            $__dk = $__ts > 0 ? date('Y-m-d', $__ts) : '';
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
                            $lexSource = $lexItem['source'] ?? 'eu';
                            if ($lexSource === 'ch_bger') {
                                $lexSourceEmoji = '⚖️';
                                $lexSourceLabel = 'BGer';
                            } elseif ($lexSource === 'ch_bge') {
                                $lexSourceEmoji = '⚖️';
                                $lexSourceLabel = 'BGE';
                            } elseif ($lexSource === 'ch_bvger') {
                                $lexSourceEmoji = '⚖️';
                                $lexSourceLabel = 'BVGer';
                            } elseif ($lexSource === 'de') {
                                $lexSourceEmoji = '🇩🇪';
                                $lexSourceLabel = 'DE';
                            } elseif ($lexSource === 'ch') {
                                $lexSourceEmoji = '🇨🇭';
                                $lexSourceLabel = 'CH';
                            } elseif ($lexSource === 'fr') {
                                $lexSourceEmoji = '🇫🇷';
                                $lexSourceLabel = 'FR';
                            } else {
                                $lexSourceEmoji = '🇪🇺';
                                $lexSourceLabel = 'EU';
                            }
                            $lexDocType = $lexItem['document_type'] ?? 'Legislation';
                            if (($lexSource === 'eu')) {
                                $lexDocType = function_exists('seismo_lex_eu_document_type_for_display')
                                    ? seismo_lex_eu_document_type_for_display($lexItem)
                                    : ($lexDocType !== '' ? $lexDocType : 'EU legislation');
                            }
                            $lexUrl = trim((string)($lexItem['eurlex_url'] ?? ''));
                            if ($lexUrl === '') {
                                $lexUrl = trim((string)($lexItem['work_uri'] ?? ''));
                            }
                            $lexHasUrl = seismo_is_navigable_url($lexUrl);
                            $lexDate = $lexItem['document_date'] ? date('d.m.Y', strtotime($lexItem['document_date'])) : '';
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

                            $lexDesc = trim($lexItem['description'] ?? '');
                            $lexPreview = mb_substr($lexDesc, 0, 300);
                            if (mb_strlen($lexDesc) > 300) $lexPreview .= '...';
                            $lexHasMore = mb_strlen($lexDesc) > 300;
                            $isEuLexCard = ($lexSource === 'eu' && !$isParlSwissLex);
                            $useLexJurisdictionHeader = $isParlSwissLex || $isEuLexCard;
                            $lexHeadingTitle = function_exists('seismo_lex_card_heading_title')
                                ? seismo_lex_card_heading_title($lexItem)
                                : trim((string)($lexItem['title'] ?? ''));
                            $lexSkipDescPreview = ($lexHeadingTitle !== '' && $lexDesc !== '' && $lexHeadingTitle === $lexDesc);

                            $lexFooterMonoHide = ($lexSource === 'eu' && !$isParlSwissLex)
                                || ($lexSource === 'de' && str_starts_with($lexCelexRaw, 'de_rss_'))
                                || ($lexSource === 'fr' && preg_match('/^JORFTEXT[0-9]+/i', $lexCelexRaw))
                                || seismo_lex_bge_footer_mono_hide($lexSource, $lexCelexRaw, $lexHeadingTitle);
                        ?>
                        <div class="entry-card">
                            <?php if ($useLexJurisdictionHeader): ?>
                            <div class="entry-header entry-header--lex-eu">
                                <div class="entry-header--lex-eu-left">
                                    <?php if ($isParlSwissLex): ?>
                                    <span class="entry-lex-ch-mark" title="Bundeshaus Medien (Schweiz)"><span class="entry-lex-ch-mark__flag" aria-hidden="true">🇨🇭</span><span class="entry-lex-ch-mark__text">CH</span></span>
                                    <?php else: ?>
                                    <span class="entry-lex-eu-mark" title="EUR-Lex (EU)"><span class="entry-lex-eu-mark__flag" aria-hidden="true">🇪🇺</span><span class="entry-lex-eu-mark__text">EU</span></span>
                                    <?php endif; ?>
                                    <span class="entry-lex-eu-doc-type"><?= htmlspecialchars($lexDocType) ?></span>
                                </div>
                                <div class="entry-header--lex-eu-right">
                                    <?php require __DIR__ . '/entry_header_score_actions.php'; ?>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="entry-header">
                                <?php if ($lexSource === 'ch'): ?>
                                    <span class="entry-lex-ch-mark" title="Fedlex (Schweiz)"><span class="entry-lex-ch-mark__flag" aria-hidden="true">🇨🇭</span><span class="entry-lex-ch-mark__text">CH</span></span>
                                <?php else: ?>
                                    <span class="entry-tag entry-tag--lex-source"><?= $lexSourceEmoji ?> <?= $lexSourceLabel ?></span>
                                <?php endif; ?>
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
                                <div class="entry-content entry-preview"><?= nl2br(htmlspecialchars($lexPreview)) ?></div>
                                <?php if ($lexHasMore): ?>
                                    <div class="entry-full-content"><?= nl2br(htmlspecialchars($lexDesc)) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <div class="entry-actions">
                                <div class="entry-actions-main">
                                    <?php if (!empty($lexDesc) && $lexHasMore && !$lexSkipDescPreview): ?>
                                        <button class="btn btn-secondary entry-expand-btn">expand &#9660;</button>
                                    <?php endif; ?>
                                    <?php if (!$lexFooterMonoHide): ?>
                                    <span class="entry-meta-mono<?= $isJus ? ' entry-meta-mono--jus' : '' ?>"><?= htmlspecialchars((string)$lexCelexDisplay) ?></span>
                                    <?php endif; ?>
                                    <?php if ($lexHasUrl): ?>
                                    <a href="<?= htmlspecialchars($lexUrl) ?>" target="_blank" rel="noopener" class="entry-link"><?= $lexLinkLabel ?></a>
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
                            $calEventDate = $calEvent['event_date'] ?? null;
                            $calDaysUntil = $calEventDate ? (int)((strtotime($calEventDate) - strtotime('today')) / 86400) : null;
                            $calDateLabel = '';
                            if ($calEventDate) {
                                $calDateLabel = date('d.m.Y', strtotime($calEventDate));
                                if ($calDaysUntil === 0) $calDateLabel .= ' (today)';
                                elseif ($calDaysUntil === 1) $calDateLabel .= ' (tomorrow)';
                                elseif ($calDaysUntil > 1 && $calDaysUntil <= 14) $calDateLabel .= " (in {$calDaysUntil}d)";
                            }
                            $calDesc = seismo_calendar_event_body_text($calEvent);
                            $calPreview = mb_substr($calDesc, 0, 200);
                            if (mb_strlen($calDesc) > 200) {
                                $calPreview .= '...';
                            }
                            $calHasMore = mb_strlen($calDesc) > 200;
                        ?>
                        <div class="entry-card">
                            <div class="entry-header">
                                <span class="entry-tag entry-tag--leg-type"><?= htmlspecialchars($calTypeLabel) ?></span>
                                <?php if ($calCouncil): ?>
                                    <span class="entry-tag entry-tag--leg-council"><?= htmlspecialchars($calCouncil) ?></span>
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
                            <?php if ($calDesc !== ''): ?>
                                <div class="entry-content entry-preview"><?= nl2br(htmlspecialchars($calPreview, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
                                <div class="entry-full-content"><?= nl2br(htmlspecialchars($calDesc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
                            <?php endif; ?>
                            <div class="entry-actions">
                                <div class="entry-actions-main">
                                    <?php if ($calHasUrl): ?>
                                    <a href="<?= htmlspecialchars($calUrl) ?>" target="_blank" rel="noopener" class="entry-link">parlament.ch &rarr;</a>
                                    <?php endif; ?>
                                    <?php if ($calHasMore): ?>
                                        <button class="btn btn-secondary entry-expand-btn">expand &#9660;</button>
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
                            $dateValue = $email['date_received'] ?? $email['date_utc'] ?? $email['created_at'] ?? $email['date_sent'] ?? null;
                            $createdAt = seismo_format_stored_utc_datetime(is_string($dateValue) ? $dateValue : null);
                            
                            $fromName = trim((string)($email['from_name'] ?? ''));
                            $fromEmail = trim((string)($email['from_email'] ?? ''));
                            $fromDisplay = $fromName !== '' ? $fromName : ($fromEmail !== '' ? $fromEmail : 'Unknown sender');

                            $subject = trim((string)($email['subject'] ?? ''));
                            if ($subject === '') {
                                $subject = '(No subject)';
                            }
                            $displayTitle = seismo_email_display_title($email);

                            $body = (string)($email['text_body'] ?? $email['body_text'] ?? '');
                            if ($body === '') {
                                $body = strip_tags((string)($email['html_body'] ?? $email['body_html'] ?? ''));
                            }
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
                            $webViewUrl = seismo_email_web_view_url($email);
                        ?>
                        <div class="entry-card">
                            <div class="entry-header">
                                <?php
                                    $subLabel = trim((string)($email['subscription_display_name'] ?? ''));
                                    $legacySenderTag = !empty($email['sender_tag']) && $email['sender_tag'] !== 'unclassified';
                                ?>
                                <?php if ($subLabel !== ''): ?>
                                    <span class="entry-tag entry-tag--email-sender"><?= htmlspecialchars($subLabel) ?></span>
                                <?php elseif ($legacySenderTag): ?>
                                    <span class="entry-tag entry-tag--email-sender"><?= htmlspecialchars($email['sender_tag']) ?></span>
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
                                <?php 
                                    if ($bodyPreview === '' && $body === '') {
                                        echo '<span class="entry-muted">(No body text)</span>';
                                    } elseif (!empty($searchQuery)) {
                                        echo seismo_highlight_search_term($bodyPreview, $searchQuery);
                                    } else {
                                        echo htmlspecialchars($bodyPreview);
                                    }
                                ?>
                                <?php if ($webViewUrl !== null): ?>
                                    <a href="<?= htmlspecialchars($webViewUrl) ?>" target="_blank" rel="noopener" class="entry-link entry-link--after-preview">View in browser &rarr;</a>
                                <?php endif; ?>
                            </div>
                            <div class="entry-full-content"><?= htmlspecialchars($bodyDisplay) ?></div>
                            <div class="entry-actions">
                                <div class="entry-actions-main">
                                    <?php if ($hasMore): ?>
                                        <button class="btn btn-secondary entry-expand-btn">expand &#9660;</button>
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
