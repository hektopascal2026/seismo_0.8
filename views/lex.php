<?php
/**
 * Lex legislation list (multi-source read; Fedlex refresh in 0.5 only).
 *
 * @var array<int, array<string, mixed>> $lexItems
 * @var array<string, mixed> $lexCfg
 * @var list<string> $enabledLexSources
 * @var list<string> $activeSources
 * @var ?string $pageError
 * @var array<string, ?\DateTimeImmutable> $lastFetchedBySource Per-source MAX(fetched_at) in UTC (view formats to Zurich).
 * @var string $basePath
 * @var bool $satellite
 * @var array<string, mixed> $chCfg
 * @var array<string, mixed> $euCfg
 * @var array<string, mixed> $deCfg
 * @var array<string, mixed> $frCfg
 * @var array<string, mixed> $jusBgerCfg
 * @var array<string, mixed> $jusBgeCfg
 * @var array<string, mixed> $jusBvgerCfg
 * @var string $jusBannedWordsStr
 * @var string $view 'items'|'sources'
 * @var string $csrfField Hidden CSRF inputs (LexController)
 * @var bool $showModuleRefresh
 * @var string $moduleRefreshAction
 * @var string $moduleRefreshLabel
 * @var string $moduleRefreshReturnView
 */

declare(strict_types=1);

if (!function_exists('seismo_format_lex_refresh_utc')) {
    require_once __DIR__ . '/helpers.php';
}

$accent = seismoBrandAccent();
$frNaturesStr = '';
if (!empty($frCfg['natures']) && is_array($frCfg['natures'])) {
    $frNaturesStr = implode(', ', array_map('strval', $frCfg['natures']));
}

$chResourceTypesStr = '';
if (!empty($chCfg['resource_types']) && is_array($chCfg['resource_types'])) {
    $ids = [];
    foreach ($chCfg['resource_types'] as $rt) {
        if (is_array($rt) && isset($rt['id'])) {
            $ids[] = (string)(int)$rt['id'];
        }
    }
    $chResourceTypesStr = implode(', ', $ids);
}

$deExcludeStr = '';
if (!empty($deCfg['exclude_document_types']) && is_array($deCfg['exclude_document_types'])) {
    $deExcludeStr = implode(', ', array_map(static fn ($v): string => (string)$v, $deCfg['exclude_document_types']));
}

$itemsQs   = 'action=lex';
$sourcesQs = 'action=lex&view=sources';
$lexReturnViewHidden = '<input type="hidden" name="return_view" value="sources">';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lex — <?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
    <?php if ($accent): ?>
    <style>:root { --seismo-accent: <?= e($accent) ?>; }</style>
    <?php endif; ?>
</head>
<body>
    <div class="container">
        <?php
        $headerTitle = 'Lex';
        $headerSubtitle = 'EU, Swiss & German legislation';
        $activeNav = 'lex';
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
            <p class="message message-info">Satellite mode: Lex sources are read-only here. Manage them on the mothership.</p>
        <?php elseif ($satellite): ?>
            <p class="message message-info">Satellite mode: legislation rows are read from the mothership. Refresh is disabled.</p>
        <?php endif; ?>

        <?php if ($view === 'items'): ?>
        <form method="get" action="<?= e($basePath) ?>/index.php" id="lex-filter-form">
            <input type="hidden" name="action" value="lex">
            <input type="hidden" name="sources_submitted" value="1">
            <div class="tag-filter-section tag-filter-section--spaced-bottom">
                <div class="tag-filter-list">
                    <?php
                    $lexPagePills = [
                        ['key' => 'eu', 'label' => '🇪🇺 EU'],
                        ['key' => 'ch', 'label' => '🇨🇭 Switzerland'],
                        ['key' => 'de', 'label' => '🇩🇪 Germany'],
                        ['key' => 'fr', 'label' => '🇫🇷 France'],
                    ];
                    foreach ($lexPagePills as $pill):
                        if (!in_array($pill['key'], $enabledLexSources, true)) {
                            continue;
                        }
                        $isActive = in_array($pill['key'], $activeSources, true);
                    ?>
                    <label class="tag-filter-pill<?= $isActive ? ' tag-filter-pill-active' : '' ?>">
                        <input type="checkbox" name="sources[]" value="<?= e($pill['key']) ?>" <?= $isActive ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span><?= e($pill['label']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </form>

        <div class="latest-entries-section">
            <div class="section-title-row">
                <h2 class="section-title">
                    <?php
                    $lexRefreshLineMeta = [
                        ['key' => 'eu', 'emoji' => '🇪🇺'],
                        ['key' => 'ch', 'emoji' => '🇨🇭'],
                        ['key' => 'de', 'emoji' => '🇩🇪'],
                        ['key' => 'fr', 'emoji' => '🇫🇷'],
                    ];
                    $refreshParts = [];
                    foreach ($lexRefreshLineMeta as $meta) {
                        $dtUtc = $lastFetchedBySource[$meta['key']] ?? null;
                        $line = seismo_format_lex_refresh_utc($dtUtc);
                        if ($line !== null && $line !== '') {
                            $refreshParts[] = $meta['emoji'] . ' ' . $line;
                        }
                    }
                    if ($refreshParts !== []):
                    ?>
                        Refreshed: <?= implode(' · ', array_map('e', $refreshParts)) ?>
                    <?php else: ?>
                        Refreshed: Never
                    <?php endif; ?>
                </h2>
            </div>

            <?php if ($lexItems === []): ?>
                <div class="empty-state">
                    <p>No legislation in this filter yet. Enable a source under <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>">Sources</a>, or run a refresh from Diagnostics.</p>
                </div>
            <?php else: ?>
                <?php
                    $activeCount = count($activeSources);
                    $showSourceTag = ($activeCount > 1);
                ?>
                <?php foreach ($lexItems as $item): ?>
                    <?php
                        $source = $item['source'] ?? 'eu';
                        if ($source === 'fr') {
                            $sourceEmoji = '🇫🇷';
                            $sourceLabel = 'FR';
                            $linkLabel = 'Légifrance →';
                        } elseif ($source === 'de') {
                            $sourceEmoji = '🇩🇪';
                            $sourceLabel = 'DE';
                            $linkLabel = 'recht.bund.de →';
                        } elseif ($source === 'ch') {
                            $sourceEmoji = '🇨🇭';
                            $sourceLabel = 'CH';
                            $linkLabel = 'Fedlex →';
                        } else {
                            $sourceEmoji = '🇪🇺';
                            $sourceLabel = 'EU';
                            $linkLabel = 'EUR-Lex →';
                        }
                        $celexRow = (string)($item['celex'] ?? '');
                        $isParlSwissLex = (bool) preg_match('/^parl_(mm|sda):/i', $celexRow)
                            || in_array($source, ['parl_mm', 'parl_sda'], true);
                        if ($isParlSwissLex) {
                            $linkLabel = 'parlament.ch →';
                        }
                        /** 0.4 hid CELEX / outbound link for Parl MM dossiers — keep for legacy lex rows */
                        $hideParlMmLexFooterIds = in_array($source, ['parl_mm', 'parl_sda'], true);
                        /** EUR-Lex: CELEX surplus to title; DE RSS synthetic key; FR JORFTEXT API id — not footer labels */
                        $lexLexPageFooterMonoHide = ($source === 'eu' && !$isParlSwissLex)
                            || ($source === 'de' && str_starts_with($celexRow, 'de_rss_'))
                            || ($source === 'fr' && preg_match('/^JORFTEXT[0-9]+/i', $celexRow))
                            || ($source === 'ch' && !$isParlSwissLex);
                        $docType = (string)($item['document_type'] ?? 'Legislation');
                        if ($source === 'eu' && function_exists('seismo_lex_eu_document_type_for_display')) {
                            $docType = seismo_lex_eu_document_type_for_display($item);
                        } elseif ($source === 'ch' && function_exists('seismo_lex_ch_document_type_for_display')) {
                            $docType = seismo_lex_ch_document_type_for_display($item);
                        }
                        $itemUrl = trim((string)($item['eurlex_url'] ?? ''));
                        if ($itemUrl === '') {
                            $itemUrl = trim((string)($item['work_uri'] ?? ''));
                        }
                        $lexHasUrl = seismo_is_navigable_url($itemUrl);
                        $lexDesc = function_exists('seismo_lex_card_preview_text')
                            ? seismo_lex_card_preview_text($item)
                            : trim((string)($item['description'] ?? ''));
                        $lexPreview = mb_substr($lexDesc, 0, 300);
                        if (mb_strlen($lexDesc) > 300) {
                            $lexPreview .= '...';
                        }
                        $lexHasMore = mb_strlen($lexDesc) > 300;
                        /**
                         * 0.4 always used `$item['title']`. EUR-Lex often stores CELEX as title with real
                         * prose in `description`; use the helper only for eu (dashboard behaviour).
                         */
                        if (($item['source'] ?? 'eu') === 'eu' && function_exists('seismo_lex_card_heading_title')) {
                            $lexHeadingTitle = seismo_lex_card_heading_title($item);
                        } else {
                            $lexHeadingTitle = trim((string)($item['title'] ?? ''));
                            if ($lexHeadingTitle === '' && function_exists('seismo_lex_card_heading_title')) {
                                $lexHeadingTitle = seismo_lex_card_heading_title($item);
                            }
                        }
                        $lexSkipDescPreview = ($lexHeadingTitle !== '' && $lexDesc !== '' && $lexHeadingTitle === $lexDesc);
                        if (function_exists('seismo_lex_bge_footer_mono_hide')) {
                            $lexLexPageFooterMonoHide = $lexLexPageFooterMonoHide
                                || seismo_lex_bge_footer_mono_hide($source, $celexRow, $lexHeadingTitle);
                        }
                    ?>
                    <div class="entry-card">
                        <?php if ($source === 'ch'): ?>
                        <div class="entry-header entry-header--lex-eu">
                            <div class="entry-header--lex-eu-left">
                                <span class="entry-lex-ch-mark" title="Fedlex (Schweiz)"><span class="entry-lex-ch-mark__flag" aria-hidden="true">🇨🇭</span><span class="entry-lex-ch-mark__text">CH</span></span>
                                <span class="entry-lex-eu-doc-type"><?= e($docType) ?></span>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="entry-header">
                            <?php if ($showSourceTag): ?>
                                <span class="entry-tag entry-tag--lex-source">
                                    <?= e($sourceEmoji) ?> <?= e($sourceLabel) ?>
                                </span>
                            <?php endif; ?>
                            <span class="entry-tag entry-tag--lex-doc">
                                <?= e($docType) ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <h3 class="entry-title">
                            <?php if ($lexHasUrl): ?>
                            <a href="<?= e($itemUrl) ?>" target="_blank" rel="noopener"><?= e($lexHeadingTitle) ?></a>
                            <?php else: ?>
                            <?= e($lexHeadingTitle) ?>
                            <?php endif; ?>
                        </h3>
                        <?php if ($lexDesc !== '' && !$lexSkipDescPreview): ?>
                            <div class="entry-content entry-preview"><?= nl2br(e($lexPreview)) ?></div>
                            <?php if ($lexHasMore): ?>
                                <div class="entry-full-content"><?= nl2br(e($lexDesc)) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="entry-actions">
                            <div class="entry-actions-main">
                                <?php if ($lexHasMore && !$lexSkipDescPreview): ?>
                                    <button type="button" class="btn btn-secondary entry-expand-btn">expand &#9660;</button>
                                <?php endif; ?>
                                <?php if (!$hideParlMmLexFooterIds && !$lexLexPageFooterMonoHide): ?>
                                    <span class="entry-meta-mono"><?= e((string)($item['celex'] ?? '')) ?></span>
                                <?php endif; ?>
                                <?php if ($lexHasUrl && !$hideParlMmLexFooterIds): ?>
                                        <a href="<?= e($itemUrl) ?>" target="_blank" rel="noopener" class="entry-link"><?= e($linkLabel) ?></a>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($item['document_date'])): ?>
                                <span class="entry-date"><?= e(date('d.m.Y', strtotime((string)$item['document_date']))) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <p class="message message-info">
            <strong>Lex refresh:</strong> Use <strong>Refresh Lex</strong> in the top bar or <strong>Refresh all Lex sources</strong> below for every plugin at once (EU, CH, DE, FR, Jus), or a single-source button, or <a href="<?= e($basePath) ?>/index.php?action=settings&amp;tab=diagnostics">Settings → Diagnostics</a> / cron.
            <strong>Parlament Medien</strong> (press) are <code>feed_items</code> — configure <code>parl_press</code> on <a href="<?= e($basePath) ?>/index.php?action=feeds&amp;view=sources">Feeds</a>.
        </p>

        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">Lex sources</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Source</th>
                        <th>Enabled</th>
                        <th>Last refreshed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $lexSourceRows = [
                        ['key' => 'eu', 'label' => '🇪🇺 EUR-Lex (EU)', 'cfg' => $euCfg],
                        ['key' => 'ch', 'label' => '🇨🇭 Fedlex (CH)', 'cfg' => $chCfg],
                        ['key' => 'de', 'label' => '🇩🇪 recht.bund.de (DE)', 'cfg' => $deCfg],
                        ['key' => 'fr', 'label' => '🇫🇷 Légifrance (FR)', 'cfg' => $frCfg],
                        ['key' => 'ch_bger', 'label' => 'Jus: BGer', 'cfg' => $jusBgerCfg],
                        ['key' => 'ch_bge', 'label' => 'Jus: BGE', 'cfg' => $jusBgeCfg],
                        ['key' => 'ch_bvger', 'label' => 'Jus: BVGer', 'cfg' => $jusBvgerCfg],
                    ];
                    foreach ($lexSourceRows as $row):
                        $cfg = is_array($row['cfg']) ? $row['cfg'] : [];
                        $refreshLine = seismo_format_lex_refresh_utc($lastFetchedBySource[$row['key']] ?? null);
                    ?>
                    <tr>
                        <td><?= e($row['label']) ?></td>
                        <td><?= !empty($cfg['enabled']) ? '<span class="pill pill-on">yes</span>' : '<span class="pill pill-off">no</span>' ?></td>
                        <td><?= $refreshLine !== null ? e($refreshLine) : '<span class="table-cell-placeholder">Never</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (!$satellite): ?>
        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">Refresh legislation sources</h2>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_lex_all" class="admin-inline-form seismo-ajax-refresh-form" style="margin-bottom: 1rem;">
                <?= $csrfField ?>
                <?= $lexReturnViewHidden ?>
                <button type="submit" class="btn btn-primary" data-refresh-label="Refresh all Lex sources">Refresh all Lex sources</button>
            </form>
            <p class="admin-intro">Runs every enabled Lex plugin (EUR-Lex, Fedlex, DE, FR, Jus) in one request. Below: one plugin per button (same as Diagnostics).</p>
            <div class="admin-form-actions">
                <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_lex_eu" class="admin-inline-form seismo-ajax-refresh-form">
                    <?= $csrfField ?>
                    <?= $lexReturnViewHidden ?>
                    <button type="submit" class="btn btn-primary" data-refresh-label="Refresh EUR-Lex (EU)">Refresh EUR-Lex (EU)</button>
                </form>
                <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_fedlex" class="admin-inline-form seismo-ajax-refresh-form">
                    <?= $csrfField ?>
                    <?= $lexReturnViewHidden ?>
                    <button type="submit" class="btn btn-primary" data-refresh-label="Refresh Fedlex (CH)">Refresh Fedlex (CH)</button>
                </form>
                <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_recht_bund" class="admin-inline-form seismo-ajax-refresh-form">
                    <?= $csrfField ?>
                    <?= $lexReturnViewHidden ?>
                    <button type="submit" class="btn btn-primary" data-refresh-label="Refresh recht.bund (DE)">Refresh recht.bund (DE)</button>
                </form>
                <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_legifrance" class="admin-inline-form seismo-ajax-refresh-form">
                    <?= $csrfField ?>
                    <?= $lexReturnViewHidden ?>
                    <button type="submit" class="btn btn-primary" data-refresh-label="Refresh Légifrance (FR)">Refresh Légifrance (FR)</button>
                </form>
                <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_jus_bger" class="admin-inline-form seismo-ajax-refresh-form">
                    <?= $csrfField ?>
                    <?= $lexReturnViewHidden ?>
                    <button type="submit" class="btn btn-primary" data-refresh-label="Refresh Jus: BGer">Refresh Jus: BGer</button>
                </form>
                <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_jus_bge" class="admin-inline-form seismo-ajax-refresh-form">
                    <?= $csrfField ?>
                    <?= $lexReturnViewHidden ?>
                    <button type="submit" class="btn btn-primary" data-refresh-label="Refresh Jus: BGE">Refresh Jus: BGE</button>
                </form>
                <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_jus_bvger" class="admin-inline-form seismo-ajax-refresh-form">
                    <?= $csrfField ?>
                    <?= $lexReturnViewHidden ?>
                    <button type="submit" class="btn btn-primary" data-refresh-label="Refresh Jus: BVGer">Refresh Jus: BVGer</button>
                </form>
            </div>
        </div>

        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">Jus (Swiss case law) — entscheidsuche.ch</h2>
            <p class="admin-intro">BGer, BGE, and BVGer are separate plugins. If refresh reports <em>Disabled in config</em>, enable the source below and save before refreshing. Use at least <strong>365</strong> lookback days for BGer if a 90-day window returns no rows — entscheidsuche updates can be several months behind.</p>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=save_lex_jus" class="admin-form-card">
                <?= $csrfField ?>
                <div class="admin-form-field">
                    <label><input type="checkbox" name="ch_bger_enabled" value="1" <?= !empty($jusBgerCfg['enabled']) ? 'checked' : '' ?>> BGer enabled</label>
                </div>
                <div class="admin-form-field">
                    <label>BGer lookback days<br>
                    <input type="number" name="ch_bger_lookback_days" value="<?= (int)($jusBgerCfg['lookback_days'] ?? 90) ?>" min="1" class="search-input" style="width:100%; max-width:10rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>BGer row limit (max 500)<br>
                    <input type="number" name="ch_bger_limit" value="<?= (int)($jusBgerCfg['limit'] ?? 100) ?>" min="1" max="500" class="search-input" style="width:100%; max-width:10rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label><input type="checkbox" name="ch_bge_enabled" value="1" <?= !empty($jusBgeCfg['enabled']) ? 'checked' : '' ?>> BGE enabled</label>
                </div>
                <div class="admin-form-field">
                    <label>BGE lookback days<br>
                    <input type="number" name="ch_bge_lookback_days" value="<?= (int)($jusBgeCfg['lookback_days'] ?? 90) ?>" min="1" class="search-input" style="width:100%; max-width:10rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>BGE row limit (max 500)<br>
                    <input type="number" name="ch_bge_limit" value="<?= (int)($jusBgeCfg['limit'] ?? 50) ?>" min="1" max="500" class="search-input" style="width:100%; max-width:10rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label><input type="checkbox" name="ch_bvger_enabled" value="1" <?= !empty($jusBvgerCfg['enabled']) ? 'checked' : '' ?>> BVGer enabled</label>
                </div>
                <div class="admin-form-field">
                    <label>BVGer lookback days<br>
                    <input type="number" name="ch_bvger_lookback_days" value="<?= (int)($jusBvgerCfg['lookback_days'] ?? 90) ?>" min="1" class="search-input" style="width:100%; max-width:10rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>BVGer row limit (max 500)<br>
                    <input type="number" name="ch_bvger_limit" value="<?= (int)($jusBvgerCfg['limit'] ?? 100) ?>" min="1" max="500" class="search-input" style="width:100%; max-width:10rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Banned title words (comma-separated, case-insensitive)<br>
                    <input type="text" name="jus_banned_words" value="<?= e($jusBannedWordsStr) ?>" autocomplete="off" class="search-input" style="width:100%;"></label>
                    <p class="admin-hint">Decisions whose title contains any listed word are skipped on ingest.</p>
                </div>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success">Save Jus settings</button>
                </div>
            </form>
        </div>

        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">EUR-Lex (EU) settings</h2>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=save_lex_eu" class="admin-form-card">
                <?= $csrfField ?>
                <div class="admin-form-field">
                    <label><input type="checkbox" name="eu_enabled" value="1" <?= !empty($euCfg['enabled']) ? 'checked' : '' ?>> Enabled</label>
                </div>
                <div class="admin-form-field">
                    <label>SPARQL endpoint (https only)<br>
                    <input type="url" name="eu_endpoint" value="<?= e((string)($euCfg['endpoint'] ?? '')) ?>" class="search-input" style="width:100%;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Language code (EU authority, e.g. ENG, DEU, FRA)<br>
                    <input type="text" name="eu_language" value="<?= e((string)($euCfg['language'] ?? 'ENG')) ?>" maxlength="8" class="search-input" style="width:100%; max-width:12rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Document class (CDM curie, e.g. <code>cdm:legislation_secondary</code>)<br>
                    <input type="text" name="eu_document_class" value="<?= e((string)($euCfg['document_class'] ?? 'cdm:legislation_secondary')) ?>" class="search-input" style="width:100%;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Lookback days<br>
                    <input type="number" name="eu_lookback_days" value="<?= (int)($euCfg['lookback_days'] ?? 90) ?>" min="1" class="search-input" style="width:100%; max-width:10rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Row limit (max 200)<br>
                    <input type="number" name="eu_limit" value="<?= (int)($euCfg['limit'] ?? 100) ?>" min="1" max="200" class="search-input" style="width:100%; max-width:10rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Notes<br>
                    <textarea name="eu_notes" rows="2" class="search-input" style="width:100%;"><?= e((string)($euCfg['notes'] ?? '')) ?></textarea></label>
                </div>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success">Save EU settings</button>
                </div>
            </form>
        </div>

        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">Fedlex settings (CH only)</h2>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=save_lex_ch" class="admin-form-card admin-form-card-narrow">
                <?= $csrfField ?>
                <input type="hidden" name="ch_fedlex_settings_form" value="1">
                <div class="admin-form-field">
                    <label><input type="checkbox" name="ch_enabled" value="1" <?= !empty($chCfg['enabled']) ? 'checked' : '' ?>> Enabled</label>
                </div>
                <div class="admin-form-field">
                    <?php $vmIngest = \Seismo\Plugin\LexFedlex\LexFedlexPlugin::ingestVernehmlassungen($chCfg); ?>
                    <label><input type="checkbox" name="ch_ingest_vernehmlassungen" value="1" <?= $vmIngest ? 'checked' : '' ?>> Vernehmlassungen (consultation procedures via SPARQL)</label>
                </div>
                <div class="admin-form-field">
                    <label>Language (Fedlex expression)<br>
                    <input type="text" name="ch_language" value="<?= e((string)($chCfg['language'] ?? 'DEU')) ?>" maxlength="8" class="search-input" style="width:100%; max-width:12rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Lookback days<br>
                    <input type="number" name="ch_lookback_days" value="<?= (int)($chCfg['lookback_days'] ?? 90) ?>" min="1" class="search-input" style="width:100%; max-width:10rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>SPARQL row limit<br>
                    <input type="number" name="ch_limit" value="<?= (int)($chCfg['limit'] ?? 100) ?>" min="1" class="search-input" style="width:100%; max-width:10rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Resource type IDs (comma-separated)<br>
                    <input type="text" name="ch_resource_types" value="<?= e($chResourceTypesStr) ?>" class="search-input" style="width:100%;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Notes<br>
                    <textarea name="ch_notes" rows="2" class="search-input" style="width:100%;"><?= e((string)($chCfg['notes'] ?? '')) ?></textarea></label>
                </div>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success">Save Fedlex settings</button>
                </div>
            </form>
        </div>

        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">recht.bund.de (DE) settings</h2>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=save_lex_de" class="admin-form-card">
                <?= $csrfField ?>
                <div class="admin-form-field">
                    <label><input type="checkbox" name="de_enabled" value="1" <?= !empty($deCfg['enabled']) ? 'checked' : '' ?>> Enabled</label>
                </div>
                <div class="admin-form-field">
                    <label>RSS feed URL (https, <code>www.recht.bund.de</code>)<br>
                    <input type="url" name="de_feed_url" value="<?= e((string)($deCfg['feed_url'] ?? '')) ?>" class="search-input" style="width:100%;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Lookback days<br>
                    <input type="number" name="de_lookback_days" value="<?= (int)($deCfg['lookback_days'] ?? 90) ?>" min="1" class="search-input" style="width:100%; max-width:10rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Row limit (max 200)<br>
                    <input type="number" name="de_limit" value="<?= (int)($deCfg['limit'] ?? 100) ?>" min="1" max="200" class="search-input" style="width:100%; max-width:10rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Exclude derived document types<br>
                    <input type="text" name="de_exclude_document_types" value="<?= e($deExcludeStr) ?>" autocomplete="off" class="search-input" style="width:100%;" placeholder="e.g. Bekanntmachung"></label>
                    <p class="admin-hint">Comma- or semicolon-separated. Each RSS item is labelled from its title only: Verordnung, Gesetz, Bekanntmachung, or BGBl (everything else). Case-insensitive. Leave empty to ingest all types (existing DB rows are unchanged).</p>
                </div>
                <div class="admin-form-field">
                    <label>Notes<br>
                    <textarea name="de_notes" rows="2" class="search-input" style="width:100%;"><?= e((string)($deCfg['notes'] ?? '')) ?></textarea></label>
                </div>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success">Save DE settings</button>
                </div>
            </form>
        </div>

        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">Légifrance (FR) — PISTE API</h2>
            <p class="admin-intro">Create an application on <a href="https://piste.gouv.fr/" target="_blank" rel="noopener">PISTE</a>, subscribe to the Légifrance API, then paste OAuth client credentials below. Leave the secret field blank to keep the stored secret.</p>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=save_lex_fr" class="admin-form-card">
                <?= $csrfField ?>
                <div class="admin-form-field">
                    <label><input type="checkbox" name="fr_enabled" value="1" <?= !empty($frCfg['enabled']) ? 'checked' : '' ?>> Enabled</label>
                </div>
                <div class="admin-form-field">
                    <label>OAuth client id<br>
                    <input type="text" name="fr_client_id" value="<?= e((string)($frCfg['client_id'] ?? '')) ?>" autocomplete="off" class="search-input" style="width:100%;"></label>
                </div>
                <div class="admin-form-field">
                    <label>OAuth client secret<br>
                    <input type="password" name="fr_client_secret" value="" autocomplete="new-password" placeholder="(unchanged if empty)" class="search-input" style="width:100%;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Token URL<br>
                    <input type="url" name="fr_oauth_token_url" value="<?= e((string)($frCfg['oauth_token_url'] ?? 'https://oauth.piste.gouv.fr/api/oauth/token')) ?>" class="search-input" style="width:100%;"></label>
                </div>
                <div class="admin-form-field">
                    <label>API base (no trailing /search)<br>
                    <input type="url" name="fr_api_base_url" value="<?= e((string)($frCfg['api_base_url'] ?? 'https://api.piste.gouv.fr/dila/legifrance/lf-engine-app')) ?>" class="search-input" style="width:100%;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Fond (e.g. JORF)<br>
                    <input type="text" name="fr_fond" value="<?= e((string)($frCfg['fond'] ?? 'JORF')) ?>" maxlength="32" class="search-input" style="width:100%; max-width:16rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Natures (comma-separated, JORF facet)<br>
                    <input type="text" name="fr_natures" value="<?= e($frNaturesStr) ?>" class="search-input" style="width:100%;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Lookback days<br>
                    <input type="number" name="fr_lookback_days" value="<?= (int)($frCfg['lookback_days'] ?? 90) ?>" min="1" class="search-input" style="width:100%; max-width:10rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Row limit (max 200)<br>
                    <input type="number" name="fr_limit" value="<?= (int)($frCfg['limit'] ?? 100) ?>" min="1" max="200" class="search-input" style="width:100%; max-width:10rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Notes<br>
                    <textarea name="fr_notes" rows="2" class="search-input" style="width:100%;"><?= e((string)($frCfg['notes'] ?? '')) ?></textarea></label>
                </div>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success">Save FR settings</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
    (function() {
        function collapseEntry(card, btn) {
            var preview = card.querySelector('.entry-preview');
            var full = card.querySelector('.entry-full-content');
            if (!preview || !full) return;
            full.style.display = 'none';
            preview.style.display = '';
            if (btn) btn.innerHTML = 'expand \u25BC';
        }
        function expandEntry(card, btn) {
            var preview = card.querySelector('.entry-preview');
            var full = card.querySelector('.entry-full-content');
            if (!preview || !full) return;
            preview.style.display = 'none';
            full.style.display = 'block';
            if (btn) btn.innerHTML = 'collapse \u25B2';
        }
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.entry-expand-btn');
            if (!btn) return;
            var card = btn.closest('.entry-card');
            if (!card) return;
            var full = card.querySelector('.entry-full-content');
            if (full && full.style.display === 'block') {
                collapseEntry(card, btn);
            } else {
                expandEntry(card, btn);
            }
        });
    })();
    </script>
</body>
</html>
