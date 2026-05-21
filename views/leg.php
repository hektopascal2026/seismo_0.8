<?php
/**
 * Leg (parliamentary business) — newly ingested affairs grouped by ingest date.
 *
 * @var array<int, array<string, mixed>> $events
 * @var array<string, mixed> $calendarCfg
 * @var list<string> $enabledSources
 * @var list<string> $activeSources
 * @var list<string> $eventTypes
 * @var string $eventType
 * @var bool $showPast
 * @var ?string $pageError
 * @var array<string, ?\DateTimeImmutable> $lastFetchedBySource
 * @var string $basePath
 * @var bool $satellite
 * @var array<string, mixed> $parlChCfg
 * @var int $totalRows        Total rows for current sources/type (past + upcoming)
 * @var int $hiddenPastRows   Rows the current filter is hiding (i.e. past-dated)
 * @var string $view 'items'|'sources'
 * @var string $csrfField Hidden CSRF inputs (LegController)
 * @var array<string, array<string, mixed>> $legEntryScores `entry_type:entry_id` → `entry_scores` row
 * @var bool $showModuleRefresh
 * @var string $moduleRefreshAction
 * @var string $moduleRefreshLabel
 * @var string $moduleRefreshReturnView
 */

declare(strict_types=1);

if (!function_exists('seismo_format_utc')) {
    require_once __DIR__ . '/helpers.php';
}

$accent = seismoBrandAccent();
$todayLocal = (new DateTimeImmutable('now', seismo_view_timezone()))->format('Y-m-d');
$itemsQs   = 'action=leg';
$sourcesQs = 'action=leg&view=sources';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leg — <?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
    <?php if ($accent): ?>
    <style>:root { --seismo-accent: <?= e($accent) ?>; }</style>
    <?php endif; ?>
</head>
<body>
    <div class="container">
        <?php
        $headerTitle = 'Leg';
        $headerSubtitle = 'Parliamentary business';
        $activeNav = 'leg';
        require __DIR__ . '/partials/site_header.php';
        ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="message message-success"><?= e((string)$_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="message message-error"><?= e((string)$_SESSION['error']) ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        <?php if ($pageError !== null): ?>
            <div class="message message-error"><?= e($pageError) ?></div>
        <?php endif; ?>

        <div class="view-toggle view-toggle-bar">
            <span class="view-toggle-label">View:</span>
            <a href="<?= e($basePath) ?>/index.php?<?= e($itemsQs) ?>" class="btn <?= $view === 'items' ? 'btn-primary' : 'btn-secondary' ?>">Items</a>
            <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>" class="btn <?= $view === 'sources' ? 'btn-primary' : 'btn-secondary' ?>">Sources</a>
        </div>

        <?php if ($satellite && $view === 'sources'): ?>
            <p class="message message-info">Satellite mode: Leg sources are read-only here. Manage them on the mothership.</p>
        <?php elseif ($satellite): ?>
            <p class="message message-info">Satellite mode: Leg rows are read from the mothership. Refresh is disabled.</p>
        <?php endif; ?>

        <?php if ($view === 'items'): ?>
        <form method="get" action="<?= e($basePath) ?>/index.php" id="leg-filter-form">
            <input type="hidden" name="action" value="leg">
            <input type="hidden" name="sources_submitted" value="1">
            <?php if ($showPast): ?>
                <input type="hidden" name="show_past" value="1">
            <?php endif; ?>
            <div class="tag-filter-section tag-filter-section--spaced-bottom">
                <div class="tag-filter-list">
                    <?php
                    $legPills = [
                        ['key' => 'parliament_ch', 'label' => '🇨🇭 Parlament CH'],
                    ];
                    foreach ($legPills as $pill):
                        if (!in_array($pill['key'], $enabledSources, true)) {
                            continue;
                        }
                        $isActive = in_array($pill['key'], $activeSources, true);
                    ?>
                    <label class="tag-filter-pill<?= $isActive ? ' tag-filter-pill-active tag-filter-pill--leg-source' : '' ?>">
                        <input type="checkbox" name="sources[]" value="<?= e($pill['key']) ?>" <?= $isActive ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span><?= e($pill['label']) ?></span>
                    </label>
                    <?php endforeach; ?>

                    <?php foreach ($eventTypes as $et):
                        $etSelected = ($eventType === $et);
                    ?>
                    <label class="tag-filter-pill<?= $etSelected ? ' tag-filter-pill-active tag-filter-pill--event-type' : '' ?>">
                        <input type="radio" name="event_type" value="<?= e($et) ?>" <?= $etSelected ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span><?= e(seismo_calendar_event_type_label($et)) ?></span>
                    </label>
                    <?php endforeach; ?>
                    <?php if ($eventType !== ''): ?>
                    <label class="tag-filter-pill">
                        <input type="radio" name="event_type" value="" onchange="this.form.submit()">
                        <span>All types</span>
                    </label>
                    <?php endif; ?>

                    <label class="tag-filter-pill<?= $showPast ? ' tag-filter-pill-active' : '' ?>">
                        <input type="checkbox" name="show_past" value="1" <?= $showPast ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span>Show all</span>
                    </label>
                </div>
            </div>
        </form>

        <div class="latest-entries-section">
            <div class="section-title-row">
                <h2 class="section-title">
                    <?php
                    $refreshLine = seismo_format_utc($lastFetchedBySource['parliament_ch'] ?? null);
                    if ($refreshLine !== null):
                    ?>
                        Refreshed: <?= e($refreshLine) ?>
                    <?php else: ?>
                        Refreshed: Never
                    <?php endif; ?>
                </h2>
            </div>

            <?php if ($events === []): ?>
                <div class="empty-state">
                    <?php if (($hiddenPastRows ?? 0) > 0): ?>
                        <?php
                            $showAllUrl = $basePath . '/index.php?action=leg&sources_submitted=1&show_past=1';
                            foreach ($activeSources as $s) {
                                $showAllUrl .= '&sources%5B%5D=' . rawurlencode($s);
                            }
                            if ($eventType !== '') {
                                $showAllUrl .= '&event_type=' . rawurlencode($eventType);
                            }
                        ?>
                        <p>
                            No newly ingested parliamentary business for the selected filter, but
                            <strong><?= (int)$hiddenPastRows ?></strong>
                            older
                            entr<?= $hiddenPastRows === 1 ? 'y is' : 'ies are' ?>
                            in the database.
                            <a href="<?= e($showAllUrl) ?>">Show all</a>
                            to browse them.
                        </p>
                        <p class="empty-state-tip">
                            Tip: this list shows <em>Geschäfte</em> when Seismo first picks them up
                            (<strong>New</strong>) or when a
                            <strong>Stellungnahme des Bundesrates</strong> appears
                            (<strong>Antwort BR</strong>). Follow other updates on parlament.ch
                            or via Medienmitteilungen in your feeds.
                        </p>
                    <?php else: ?>
                        <p>No Leg entries yet. Configure and refresh under <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>">Sources</a>, or run refresh from Diagnostics.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php $currentGroup = null; ?>
                <?php foreach ($events as $event): ?>
                    <?php
                        $eventMeta = seismo_calendar_event_metadata($event);
                        $legSignal = seismo_leg_parl_ch_signal($event);
                        $feedAtRaw = isset($eventMeta['leg_feed_at']) && is_string($eventMeta['leg_feed_at'])
                            ? $eventMeta['leg_feed_at']
                            : (isset($event['created_at']) ? (string)$event['created_at'] : null);
                        $ingestDt = \Seismo\Util\TimelineEntryDatetime::parseStoredUtcDatetime($feedAtRaw);
                        $groupKey = $ingestDt !== null
                            ? $ingestDt->setTimezone(seismo_view_timezone())->format('Y-m-d')
                            : 'undated';
                        if ($groupKey !== $currentGroup):
                            $currentGroup = $groupKey;
                            if ($ingestDt !== null) {
                                $ingestLocal = $ingestDt->setTimezone(seismo_view_timezone());
                                $ingestDay = $ingestLocal->format('Y-m-d');
                                $daysUntil = (int)floor((strtotime($ingestDay) - strtotime($todayLocal)) / 86400);
                                $groupLabel = 'Added ' . $ingestLocal->format('l, d. F Y');
                                if ($daysUntil === 0)       { $groupLabel .= ' (today)'; }
                                elseif ($daysUntil === 1)   { $groupLabel .= ' (yesterday)'; }
                                elseif ($daysUntil < 0)     { $groupLabel .= ' (' . abs($daysUntil) . ' days ago)'; }
                            } else {
                                $groupLabel = 'Ingest date unknown';
                            }
                    ?>
                    <div class="leg-date-heading">
                        <?= e($groupLabel) ?>
                    </div>
                    <?php endif; ?>

                    <?php
                        $typeLabel = seismo_calendar_event_type_label((string)($event['event_type'] ?? ''));
                        $councilLabel = seismo_council_label((string)($event['council'] ?? ''));
                        $statusRaw = (string)($event['status'] ?? 'scheduled');
                        $statusLabel = ucfirst($statusRaw);

                        $combined = seismo_calendar_event_body_text($event);
                        $preview = mb_substr($combined, 0, 300);
                        $hasMore = mb_strlen($combined) > 300;
                        if ($hasMore) { $preview .= '...'; }

                        $eventUrl = (string)($event['url'] ?? '');

                        $legEid = (int)($event['id'] ?? 0);
                        $legScoreRow = ($legEntryScores['calendar_event:' . $legEid] ?? null);
                        $legRel = is_array($legScoreRow) ? (float)($legScoreRow['relevance_score'] ?? 0) : null;
                        if ($legRel === null || $legRel <= 0.0) {
                            $legRel = null;
                        }
                        $legPred = is_array($legScoreRow) ? ($legScoreRow['predicted_label'] ?? null) : null;
                        $legBadgeClass = '';
                        if ($legRel !== null) {
                            $legPct = (int)round($legRel * 100);
                            if ($legPct <= 25) {
                                $legBadgeClass = 'magnitu-badge-noise';
                            } elseif ($legPct <= 50) {
                                $legBadgeClass = 'magnitu-badge-background';
                            } elseif ($legPct <= 75) {
                                $legBadgeClass = 'magnitu-badge-important';
                            } else {
                                $legBadgeClass = 'magnitu-badge-investigation';
                            }
                        }
                    ?>
                    <div class="entry-card">
                        <div class="entry-header">
                            <span class="entry-tag entry-tag--leg-type"><?= e($typeLabel) ?></span>
                            <?php if ($councilLabel !== ''): ?>
                                <span class="entry-tag entry-tag--leg-council"><?= e($councilLabel) ?></span>
                            <?php endif; ?>
                            <?php if ($legRel !== null): ?>
                                <span class="magnitu-badge <?= e($legBadgeClass) ?>" title="<?= e((string)$legPred) ?> (<?= round($legRel * 100) ?>%)"><?= round($legRel * 100) ?></span>
                            <?php endif; ?>
                            <?php
                                $legSignalLabel = $legSignal !== null
                                    ? \Seismo\Util\ParlChLegSignal::signalLabel($legSignal)
                                    : '';
                            ?>
                            <?php if ($legSignalLabel !== ''): ?>
                                <span class="entry-tag entry-tag--leg-signal"><?= e($legSignalLabel) ?></span>
                            <?php elseif ($statusRaw !== 'scheduled'): ?>
                                <?php
                                $statusTagClass = $statusRaw === 'completed'
                                    ? 'entry-tag--status-completed'
                                    : ($statusRaw === 'cancelled' ? 'entry-tag--status-cancelled' : 'entry-tag--status-other');
                                ?>
                                <span class="entry-tag <?= e($statusTagClass) ?>"><?= e($statusLabel) ?></span>
                            <?php endif; ?>
                        </div>
                        <h3 class="entry-title">
                            <?php if (seismo_is_navigable_url($eventUrl)): ?>
                                <a href="<?= e($eventUrl) ?>" target="_blank" rel="noopener"><?= e((string)($event['title'] ?? '')) ?></a>
                            <?php else: ?>
                                <?= e((string)($event['title'] ?? '')) ?>
                            <?php endif; ?>
                        </h3>
                        <?php if ($preview !== ''): ?>
                            <div class="entry-content entry-preview"><?= nl2br(e($preview)) ?></div>
                            <?php if ($hasMore): ?>
                                <div class="entry-full-content"><?= nl2br(e($combined)) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="entry-actions">
                            <div class="entry-actions-main">
                                <?php if ($hasMore): ?>
                                    <button type="button" class="btn btn-secondary entry-expand-btn">expand &#9660;</button>
                                <?php endif; ?>
                                <?php if (seismo_is_navigable_url($eventUrl)): ?>
                                    <a href="<?= e($eventUrl) ?>" target="_blank" rel="noopener" class="entry-link">parlament.ch →</a>
                                <?php endif; ?>
                            </div>
                            <?php
                                $ingestLabel = $ingestDt !== null
                                    ? seismo_format_utc($ingestDt, 'd.m.Y H:i')
                                    : null;
                            ?>
                            <?php if ($ingestLabel !== null): ?>
                                <span class="entry-date"><?= e($ingestLabel) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="latest-entries-section">
            <h2 class="section-title">Leg sources</h2>
            <p class="admin-intro">Parliamentary business ingest. Use <strong>Refresh Parlament CH</strong> in the top bar to run the <code>parl_ch</code> plugin.</p>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>Source</th>
                        <th>Enabled</th>
                        <th>Last refreshed</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>🇨🇭 Parlament CH</td>
                        <td><?= !empty($parlChCfg['enabled']) ? '<span class="pill pill-on">yes</span>' : '<span class="pill pill-off">no</span>' ?></td>
                        <td><?php
                            $refreshLine = seismo_format_utc($lastFetchedBySource['parliament_ch'] ?? null);
                            echo $refreshLine !== null ? e($refreshLine) : '<span class="table-cell-placeholder">Never</span>';
                        ?></td>
                    </tr>
                </tbody>
            </table>

            <?php if (!$satellite): ?>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=save_leg_parl_ch" class="admin-form-card admin-form-card-narrow">
                <?= $csrfField ?>
                <h3>Parlament CH</h3>
                <div class="admin-form-field">
                    <label><input type="checkbox" name="parliament_ch_enabled" value="1" <?= !empty($parlChCfg['enabled']) ? 'checked' : '' ?>> Enabled</label>
                </div>
                <div class="admin-form-field">
                    <label>Language (DE, FR, IT, EN, RM)<br>
                    <input type="text" name="parliament_ch_language" value="<?= e((string)($parlChCfg['language'] ?? 'DE')) ?>" maxlength="2" class="search-input" style="width:100%; max-width:8rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Lookforward days (7–365)<br>
                    <input type="number" name="parliament_ch_lookforward_days" value="<?= (int)($parlChCfg['lookforward_days'] ?? 90) ?>" min="7" max="365" class="search-input" style="width:100%; max-width:10rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Lookback days (1–90)<br>
                    <input type="number" name="parliament_ch_lookback_days" value="<?= (int)($parlChCfg['lookback_days'] ?? 28) ?>" min="1" max="90" class="search-input" style="width:100%; max-width:10rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Row limit (10–500)<br>
                    <input type="number" name="parliament_ch_limit" value="<?= (int)($parlChCfg['limit'] ?? 200) ?>" min="10" max="500" class="search-input" style="width:100%; max-width:10rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Notes<br>
                    <textarea name="parliament_ch_notes" rows="2" class="search-input" style="width:100%;"><?= e((string)($parlChCfg['notes'] ?? '')) ?></textarea></label>
                </div>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success">Save Parlament CH settings</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
    (function() {
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.entry-expand-btn');
            if (!btn) return;
            var card = btn.closest('.entry-card');
            if (!card) return;
            var preview = card.querySelector('.entry-preview');
            var full = card.querySelector('.entry-full-content');
            if (!preview || !full) return;
            if (full.style.display === 'block') {
                full.style.display = 'none';
                preview.style.display = '';
                btn.innerHTML = 'expand \u25BC';
            } else {
                full.style.display = 'block';
                preview.style.display = 'none';
                btn.innerHTML = 'collapse \u25B2';
            }
        });
    })();
    </script>
</body>
</html>
