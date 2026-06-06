<?php
/**
 * Email timeline — Items | Sources (Slice 8).
 *
 * @var array<int, array<string, mixed>> $allItems
 * @var list<array<string, mixed>> $subscriptions
 * @var list<array<string, mixed>> $pendingSenders
 * @var array<int, ?array{email_id: int, subject: ?string}> $subscriptionLatest
 * @var array<int, ?array{email_id: int, subject: ?string}> $pendingLatest
 * @var ?array<string, mixed> $subscriptionFilter
 * @var ?array<string, mixed> $editRow
 * @var bool $reviewingPending
 * @var ?string $pageError
 * @var string $csrfField
 * @var float $alertThreshold
 * @var string $view 'items'|'sources'
 * @var bool $satellite
 * @var ?string $dashboardError
 * @var list<string> $categorySuggestions
 * @var \Seismo\Mail\MailModule $mailModule
 */

declare(strict_types=1);

use Seismo\Core\Mail\EmailBodyProcessorRegistry;
use Seismo\Http\CsrfToken;
use Seismo\Mail\MailModule;

$basePath = getBasePath();
$processorChoices = EmailBodyProcessorRegistry::choicesForAdmin();
$accent     = seismoBrandAccent();

$mailModule = $mailModule ?? MailModule::mail();

$headerTitle    = $mailModule->pageTitle;
$headerSubtitle = $mailModule->subtitle;
$activeNav      = $mailModule->navKey;

$itemsQs   = 'action=' . $mailModule->action;
$sourcesQs = 'action=' . $mailModule->action . '&view=sources';
$subscriptionReprocessAction = $mailModule->reprocessAction;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($headerTitle) ?> — <?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
    <?php if ($accent): ?>
    <style>
        :root { --seismo-accent: <?= e($accent) ?>; }
        .split-preview-glue-connector {
            display: flex;
            justify-content: center;
            align-items: center;
            margin: -0.25rem 0;
            position: relative;
            z-index: 10;
        }
        .btn-split-glue {
            background: #ffffff;
            border: 2px solid black;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: bold;
            padding: 2px 12px;
            cursor: pointer;
            box-shadow: 2px 2px 0px rgba(0,0,0,1);
            transition: all 0.15s ease;
        }
        .btn-split-glue:hover {
            transform: scale(1.05);
            background: #f3f4f6;
        }
        .split-preview-glue-connector.active .btn-split-glue {
            background: #22c55e;
            color: white;
            border-color: black;
            box-shadow: 1px 1px 0px rgba(0,0,0,1);
        }
        .split-preview-glue-connector.active::before {
            content: "";
            position: absolute;
            top: -10px;
            bottom: -10px;
            width: 4px;
            background: #22c55e;
            z-index: -1;
            border-left: 2px solid black;
            border-right: 2px solid black;
        }
    </style>
    <?php else: ?>
    <style>
        .split-preview-glue-connector {
            display: flex;
            justify-content: center;
            align-items: center;
            margin: -0.25rem 0;
            position: relative;
            z-index: 10;
        }
        .btn-split-glue {
            background: #ffffff;
            border: 2px solid black;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: bold;
            padding: 2px 12px;
            cursor: pointer;
            box-shadow: 2px 2px 0px rgba(0,0,0,1);
            transition: all 0.15s ease;
        }
        .btn-split-glue:hover {
            transform: scale(1.05);
            background: #f3f4f6;
        }
        .split-preview-glue-connector.active .btn-split-glue {
            background: #22c55e;
            color: white;
            border-color: black;
            box-shadow: 1px 1px 0px rgba(0,0,0,1);
        }
        .split-preview-glue-connector.active::before {
            content: "";
            position: absolute;
            top: -10px;
            bottom: -10px;
            width: 4px;
            background: #22c55e;
            z-index: -1;
            border-left: 2px solid black;
            border-right: 2px solid black;
        }
    </style>
    <?php endif; ?>
</head>
<body>
    <div class="container">
        <?php require __DIR__ . '/partials/site_header.php'; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="message message-success"><?= e((string)$_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="message message-error"><?= e((string)$_SESSION['error']) ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div class="view-toggle view-toggle-bar">
            <span class="view-toggle-label">View:</span>
            <a href="<?= e($basePath) ?>/index.php?<?= e($itemsQs) ?>" class="btn <?= $view === 'items' ? 'btn-primary' : 'btn-secondary' ?>">Items</a>
            <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>" class="btn <?= $view === 'sources' ? 'btn-primary' : 'btn-secondary' ?>">Sources</a>
        </div>

        <?php if ($satellite && $view === 'sources'): ?>
            <p class="message message-info">Satellite mode: mail sources are read-only here. Manage them on the mothership.</p>
        <?php endif; ?>

        <?php if ($pageError !== null): ?>
            <div class="message message-error"><?= e($pageError) ?></div>
        <?php endif; ?>

        <?php if ($view === 'items'): ?>
        <div class="latest-entries-section">
            <?php if ($subscriptionFilter !== null): ?>
                <?php
                $sfLabel = trim((string)($subscriptionFilter['display_name'] ?? ''));
                if ($sfLabel === '') {
                    $sfLabel = (string)$subscriptionFilter['match_type'] . ': ' . (string)$subscriptionFilter['match_value'];
                }
                ?>
                <p class="message message-info" style="margin-bottom:1rem;">
                    <?= e($mailModule->pageTitle) ?> items filtered to subscription #<?= (int)$subscriptionFilter['id'] ?> (<?= e($sfLabel) ?>).
                    <a href="<?= e($basePath) ?>/index.php?<?= e($itemsQs) ?>">Show all</a>
                    · <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>&amp;edit=<?= (int)$subscriptionFilter['id'] ?>">Edit source</a>
                </p>
                <?php if (!$satellite): ?>
                    <?php $subscriptionReprocessId = (int)$subscriptionFilter['id']; require __DIR__ . '/partials/mail_subscription_reprocess.php'; ?>
                <?php endif; ?>
            <?php endif; ?>
            <div class="section-title-row">
                <h2 class="section-title">
                    <?= count($allItems) ?> <?= count($allItems) === 1 ? 'entry' : 'entries' ?>
                </h2>
                <button class="btn btn-secondary entry-expand-all-btn">expand all &#9662;</button>
            </div>
            <?php if ($dashboardError !== null): ?>
            <?php elseif ($allItems !== []): ?>
                <?php
                    $renderNestedDigestStories = $mailModule->isNewsletter();
                    include __DIR__ . '/partials/dashboard_entry_loop.php';
                ?>
            <?php else: ?>
                <div class="empty-state">
                    <?php $sourcesHref = $basePath . '/index.php?' . $sourcesQs; ?>
                    <p><?= str_replace('{sources_href}', e($sourcesHref), $mailModule->emptyItemsHtml) ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="latest-entries-section">
            <h2 class="section-title"><?= e($mailModule->sourcesHeading) ?></h2>
            <?= $mailModule->sourcesIntroHtml ?>

            <?php if ($mailModule->showsPendingSenders && $pendingSenders !== []): ?>
            <section class="admin-new-senders-section" aria-labelledby="new-senders-heading">
                <h3 id="new-senders-heading" class="section-title section-title--compact"><?= e($mailModule->pendingSectionTitle) ?> <span class="admin-new-senders-badge"><?= count($pendingSenders) ?></span></h3>
                <p class="admin-hint"><?= e($mailModule->pendingSectionHint) ?></p>
                <table class="data-table data-table--new-senders">
                    <thead>
                        <tr>
                            <th>Match</th>
                            <?php if ($mailModule->isNewsletter()): ?>
                            <th>Subject filter</th>
                            <?php endif; ?>
                            <th>Proposed name</th>
                            <th>Latest</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendingSenders as $row): ?>
                        <?php
                        $sid = (int)$row['id'];
                        $peek = $pendingLatest[$sid] ?? null;
                        $latestQs = 'action=' . $mailModule->action . '&view=items&subscription=' . $sid;
                        ?>
                        <tr>
                            <td><?= e((string)$row['match_type']) ?>: <?= e((string)$row['match_value']) ?></td>
                            <?php if ($mailModule->isNewsletter()): ?>
                            <td><?= e((string)($row['subject_filter'] ?? '')) !== '' ? e((string)$row['subject_filter']) : '—' ?></td>
                            <?php endif; ?>
                            <td><?= e((string)$row['display_name']) ?></td>
                            <td>
                                <?php if ($peek !== null): ?>
                                    <?php
                                    $subj = $peek['subject'] ?? null;
                                    $linkText = 'Latest';
                                    $trunc = false;
                                    if ($subj !== null && $subj !== '') {
                                        $max = 56;
                                        if (function_exists('mb_strlen')) {
                                            $trunc = mb_strlen($subj) > $max;
                                            $linkText = mb_substr($subj, 0, $max);
                                        } else {
                                            $trunc = strlen($subj) > $max;
                                            $linkText = substr($subj, 0, $max);
                                        }
                                    }
                                    if ($trunc) {
                                        $linkText .= '…';
                                    }
                                    ?>
                                    <a href="<?= e($basePath) ?>/index.php?<?= e($latestQs) ?>" title="<?= e($subj ?? 'Open matching mail items') ?>"><?= e($linkText) ?></a>
                                <?php else: ?>
                                    <span class="table-cell-placeholder">No messages yet</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$satellite): ?>
                                <div class="admin-table-actions">
                                    <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>&amp;edit=<?= $sid ?>" class="btn btn-primary btn-sm">Review</a>
                                    <form method="post" action="<?= e($basePath) ?>/index.php?action=<?= e($mailModule->deleteAction) ?>" class="admin-inline-form" onsubmit="return confirm('Dismiss this proposed sender?');">
                                        <?= $csrfField ?>
                                        <input type="hidden" name="id" value="<?= $sid ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Dismiss</button>
                                    </form>
                                </div>
                                <?php else: ?>
                                <span class="table-cell-placeholder">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <?php endif; ?>

            <?php if (!$satellite && ($editRow === null || !$reviewingPending)): ?>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=<?= e($mailModule->saveAction) ?>" class="admin-form-card">
                <?= $csrfField ?>
                <input type="hidden" name="id" value="<?= $editRow ? (int)$editRow['id'] : '' ?>">
                <h3><?= $editRow ? 'Edit subscription' : 'Add subscription' ?></h3>
                <div class="admin-form-field">
                    <label>Match type
                        <?php $mt = (string)($editRow['match_type'] ?? 'domain'); ?>
                        <select name="match_type" class="search-input" style="width:100%; max-width:16rem;">
                            <option value="domain" <?= $mt === 'domain' ? 'selected' : '' ?>>domain</option>
                            <option value="email" <?= $mt === 'email' ? 'selected' : '' ?>>email</option>
                        </select>
                    </label>
                </div>
                <div class="admin-form-field">
                    <label>Match value <input type="text" name="match_value" required class="search-input" style="width:100%;" value="<?= e((string)($editRow['match_value'] ?? '')) ?>" placeholder="example.com or user@example.com"></label>
                </div>
                <div class="admin-form-field">
                    <label>Display name <input type="text" name="display_name" class="search-input" style="width:100%;" value="<?= e((string)($editRow['display_name'] ?? '')) ?>"></label>
                </div>
                <?php if ($mailModule->isNewsletter()): ?>
                <div class="admin-form-field">
                    <label>Subject filter <input type="text" name="subject_filter" class="search-input" style="width:100%;" value="<?= e((string)($editRow['subject_filter'] ?? '')) ?>" placeholder="e.g. Politpuls — required when several newsletters share the same sender address"></label>
                </div>
                <div class="admin-form-field">
                    <label>Digest Split Config (JSON)
                        <textarea name="digest_split_config" class="search-input" style="width:100%; height:6rem; font-family: monospace; font-size: 0.85rem;" placeholder='{"is_digest": true, "split_rules": {"split_method": "html_selector", "story_selector": "div.story", "title_selector": "h2", "body_selector": "p", "link_selector": "a"}}'><?= e((string)($editRow['digest_split_config'] ?? '')) ?></textarea>
                    </label>
                </div>
                <?php else: ?>
                <input type="hidden" name="subject_filter" value="">
                <input type="hidden" name="digest_split_config" value="">
                <?php endif; ?>
                <?php
                $categoryValue = (string)($editRow['category'] ?? '');
                $datalistId = 'mail-category-suggestions';
                require __DIR__ . '/partials/category_field.php';
                ?>
                <div class="admin-form-field">
                    <input type="hidden" name="disabled" value="0">
                    <label><input type="checkbox" name="disabled" value="1" <?= !empty($editRow['disabled']) ? 'checked' : '' ?>> Disabled</label>
                </div>
                <div class="admin-form-field">
                    <input type="hidden" name="show_in_magnitu" value="0">
                    <label><input type="checkbox" name="show_in_magnitu" value="1" <?= ($editRow === null || !isset($editRow['show_in_magnitu']) || !empty($editRow['show_in_magnitu'])) ? 'checked' : '' ?>> Show in Magnitu (stored preference)</label>
                </div>
                <?php if ($editRow && !empty($editRow['cleanup_config'])): ?>
                <div class="admin-form-field">
                    <input type="hidden" name="strip_listing_boilerplate" value="0">
                    <label><input type="checkbox" name="strip_listing_boilerplate" value="1" <?= !empty($editRow['strip_listing_boilerplate']) ? 'checked' : '' ?>> Gemini: Cleanup and Webview</label>
                </div>
                <?php else: ?>
                <input type="hidden" name="strip_listing_boilerplate" value="<?= !empty($editRow['strip_listing_boilerplate']) ? '1' : '0' ?>">
                <?php endif; ?>
                <input type="hidden" name="body_processor" value="<?= e((string)($editRow['body_processor'] ?? '')) ?>">
                <div class="admin-form-field">
                    <label>Unsubscribe URL <input type="url" name="unsubscribe_url" class="search-input" style="width:100%;" value="<?= e((string)($editRow['unsubscribe_url'] ?? '')) ?>"></label>
                </div>
                <div class="admin-form-field">
                    <label>Unsubscribe mailto <input type="text" name="unsubscribe_mailto" class="search-input" style="width:100%;" value="<?= e((string)($editRow['unsubscribe_mailto'] ?? '')) ?>"></label>
                </div>
                <div class="admin-form-field">
                    <input type="hidden" name="unsubscribe_one_click" value="0">
                    <label><input type="checkbox" name="unsubscribe_one_click" value="1" <?= !empty($editRow['unsubscribe_one_click']) ? 'checked' : '' ?>> One-click unsubscribe</label>
                </div>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success"><?= $editRow ? 'Save' : 'Add subscription' ?></button>
                    <?php if ($editRow): ?>
                        <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>" class="btn btn-secondary">Cancel edit</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($editRow && !$satellite): ?>
            <?php
            $cleanupConfigRaw = (string)($editRow['cleanup_config'] ?? '');
            ?>
            <div class="admin-form-card" style="margin-top: 1.5rem;" id="ai-cleanup-configurator">
                <h3>AI Cleanup &amp; WebView Configurator</h3>
                <p class="admin-intro">Uses 3 recent sample emails (max 2 Gemini calls, ~1–3 min). Generates regex cleanup rules and WebView keywords.</p>
                

                <div class="admin-form-field">
                    <button type="button" id="btn-ai-analyze" class="btn btn-secondary" onclick="runAiAnalysis(<?= (int)$editRow['id'] ?>)">
                        Analyze sample emails with Gemini
                    </button>
                    <span id="ai-analysis-status" style="margin-left: 10px; font-weight: bold; display: none;" class="type-sample-small"></span>
                    <div id="ai-cleanup-verification" style="display: none; margin-top: 0.5rem; font-size: 0.85rem; line-height: 1.4;"></div>
                </div>

                <div id="ai-results-panel" style="display: <?= $cleanupConfigRaw !== '' ? 'block' : 'none' ?>;">
                    <div class="admin-form-field">
                        <label>Generated JSON Config:
                            <textarea id="cleanup_config_json" class="search-input" style="width: 100%; height: 8rem; font-family: monospace; font-size: 0.85rem;" placeholder='{"strip_regexes": [], "webview_keywords": [], "title_extractor": null}'><?= e($cleanupConfigRaw) ?></textarea>
                        </label>
                    </div>

                    <?php if ($mailModule->isNewsletter()): ?>
                    <div class="admin-form-field" id="digest-split-proposed-panel" style="margin-top: 1rem;">
                        <label>Proposed Digest Split Config (JSON):
                            <?php $digestSplitConfigRaw = (string)($editRow['digest_split_config'] ?? ''); ?>
                            <textarea id="digest_split_config_json" class="search-input" style="width: 100%; height: 6rem; font-family: monospace; font-size: 0.85rem;" placeholder='(Not a digest or no split config generated yet)'><?= e($digestSplitConfigRaw) ?></textarea>
                        </label>
                    </div>
                    <?php endif; ?>

                    <div class="admin-form-field" id="ai-preview-section" style="display: none; margin-top: 1.5rem;">
                        <h4 style="margin-bottom: 0.75rem; font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: bold; border-bottom: 1px solid #ccc; padding-bottom: 0.25rem;">Before / After Preview</h4>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 0.75rem; flex-wrap: wrap;">
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <label for="preview-sample-select" style="font-size: 0.85rem; font-weight: bold;">Select Email Sample:</label>
                                <select id="preview-sample-select" class="search-input" style="padding: 2px 5px; font-size: 0.85rem;" onchange="updatePreview()"></select>
                            </div>
                            
                            <!-- Custom Tab Buttons using Seismo settings-tabs visual language -->
                            <div class="settings-tabs" style="margin-bottom: 0; padding-bottom: 0; border-bottom: none; display: flex; gap: 4px;">
                                <button type="button" id="tab-btn-before" class="btn active" onclick="switchPreviewTab('before')" style="padding: 0.35rem 0.875rem; font-size: 0.8rem; font-weight: 600; border-radius: 0; background-color: var(--seismo-accent, #ffd95a); border-width: 2px; border-color: black; cursor: pointer;">Before (Raw)</button>
                                <button type="button" id="tab-btn-after" class="btn" onclick="switchPreviewTab('after')" style="padding: 0.35rem 0.875rem; font-size: 0.8rem; font-weight: 600; border-radius: 0; background-color: #ffffff; border-width: 2px; border-color: black; cursor: pointer;">After (Cleaned)</button>
                            </div>
                        </div>

                        <!-- Tab Content Boxes -->
                        <div id="tab-content-before" style="display: block;">
                            <pre id="preview-before" style="background: #fdfdfd; border: 2px solid #ccc; padding: 0.75rem; height: 18rem; overflow-y: auto; white-space: pre-wrap; font-family: monospace; font-size: 0.8rem; margin: 0; color: #555;"></pre>
                        </div>
                        <div id="tab-content-after" style="display: none;">
                            <pre id="preview-after" style="background: #fff; border: 2px solid black; padding: 0.75rem; height: 18rem; overflow-y: auto; white-space: pre-wrap; font-family: monospace; font-size: 0.8rem; margin: 0; color: #000;"></pre>
                        </div>

                        <div id="cleanup-refine-panel" style="display: none; margin-top: 1rem; padding-top: 0.75rem; border-top: 1px dashed #ccc;">
                            <p class="admin-hint" style="margin: 0 0 0.5rem 0;">Still see noise in <strong>After</strong>? Paste phrases (one per line). Optionally list content wrongly removed from <strong>Before</strong>.</p>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                                <label style="font-size: 0.85rem; font-weight: bold;">Still visible noise
                                    <textarea id="cleanup-still-noise" class="search-input" style="width: 100%; height: 5rem; font-family: monospace; font-size: 0.8rem;" placeholder="View in browser&#10;Unsubscribe from this list"></textarea>
                                </label>
                                <label style="font-size: 0.85rem; font-weight: bold;">Wrongly removed content
                                    <textarea id="cleanup-wrongly-removed" class="search-input" style="width: 100%; height: 5rem; font-family: monospace; font-size: 0.8rem;" placeholder="(optional) paste article text that disappeared"></textarea>
                                </label>
                            </div>
                            <button type="button" id="btn-ai-cleanup-refine" class="btn btn-secondary" style="margin-top: 0.5rem;" disabled onclick="runAiCleanupRefine()">
                                Refine cleanup rules
                            </button>
                        </div>
                    </div>

                    <div class="admin-form-field">
                        <p class="admin-hint">Make adjustments to the JSON above if needed, then save to apply locally.</p>
                        <form method="post" action="<?= e($basePath) ?>/index.php?action=<?= e($mailModule->saveAction) ?>">
                            <?= $csrfField ?>
                            <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
                            <input type="hidden" name="match_type" value="<?= e((string)$editRow['match_type']) ?>">
                            <input type="hidden" name="match_value" value="<?= e((string)$editRow['match_value']) ?>">
                            <input type="hidden" name="display_name" value="<?= e((string)$editRow['display_name']) ?>">
                            <input type="hidden" name="category" value="<?= e((string)$editRow['category']) ?>">
                            <input type="hidden" name="disabled" value="<?= !empty($editRow['disabled']) ? '1' : '0' ?>">
                            <input type="hidden" name="show_in_magnitu" value="<?= !isset($editRow['show_in_magnitu']) || !empty($editRow['show_in_magnitu']) ? '1' : '0' ?>">
                            <input type="hidden" name="strip_listing_boilerplate" value="1">
                            <input type="hidden" name="body_processor" value="<?= e((string)$editRow['body_processor']) ?>">
                            <input type="hidden" name="unsubscribe_url" value="<?= e((string)$editRow['unsubscribe_url']) ?>">
                            <input type="hidden" name="unsubscribe_mailto" value="<?= e((string)$editRow['unsubscribe_mailto']) ?>">
                            <input type="hidden" name="unsubscribe_one_click" value="<?= !empty($editRow['unsubscribe_one_click']) ? '1' : '0' ?>">
                            <input type="hidden" name="subject_filter" value="<?= e((string)($editRow['subject_filter'] ?? '')) ?>">
                            <input type="hidden" name="digest_split_config" value="<?= e((string)($editRow['digest_split_config'] ?? '')) ?>">
                            
                            <input type="hidden" id="cleanup_config_hidden" name="cleanup_config" value="<?= e($cleanupConfigRaw) ?>">
                            
                            <button type="submit" class="btn btn-success" onclick="document.getElementById('cleanup_config_hidden').value = document.getElementById('cleanup_config_json').value;">
                                Save Config &amp; Apply
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <?php if ($mailModule->isNewsletter()): ?>
            <div class="admin-form-card" style="margin-top: 1.5rem;" id="ai-split-configurator">
                <h3>AI Split Configurator</h3>
                <p class="admin-intro">Uses 3 recent sample emails (max 2 Gemini calls, ~1–3 min). Generates selector rules to split digests into individual cards.</p>

                <div class="admin-form-field">
                    <label style="font-size: 0.85rem; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 6px; margin-bottom: 0.5rem;">
                        <input type="checkbox" id="split-advanced-mode" onchange="toggleSplitAdvancedMode()">
                        Advanced Selection Mode
                    </label>
                    <div id="split-advanced-container" style="display: none; margin-bottom: 1rem;">
                        <label style="font-weight: bold; font-size: 0.85rem; display: block; margin-bottom: 0.25rem;">What do you want to keep? (Paste interesting parts from email body / source):</label>
                        <textarea id="split-keep-text" class="search-input" style="width: 100%; height: 6rem; font-family: monospace; font-size: 0.85rem;" placeholder="Paste text or HTML snippets of the articles/sections you want to keep..."></textarea>
                    </div>
                    <button type="button" id="btn-ai-split-analyze" class="btn btn-secondary" onclick="runAiSplitAnalysis(<?= (int)$editRow['id'] ?>)">
                        Analyze sample emails for splitting
                    </button>
                    <span id="ai-split-status" style="margin-left: 10px; font-weight: bold; display: none;" class="type-sample-small"></span>
                    <div id="ai-split-verification" style="display: none; margin-top: 0.5rem; font-size: 0.85rem; line-height: 1.4;"></div>
                </div>

                <div id="ai-split-results-panel" style="display: <?= !empty($editRow['digest_split_config']) ? 'block' : 'none' ?>;">
                    <div class="admin-form-field">
                        <label>Proposed Split Config (JSON):
                            <textarea id="split_config_json" class="search-input" style="width: 100%; height: 8rem; font-family: monospace; font-size: 0.85rem;" placeholder='{"is_digest": true, "split_rules": {"split_method": "html_selector", "story_selector": "div.story", "title_selector": "h2", "link_selector": "a", "body_selector": "p"}}'><?= e((string)($editRow['digest_split_config'] ?? '')) ?></textarea>
                        </label>
                    </div>

                    <div class="admin-form-field" id="ai-split-preview-section" style="display: none; margin-top: 1.5rem;">
                        <h4 style="margin-bottom: 0.75rem; font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: bold; border-bottom: 1px solid #ccc; padding-bottom: 0.25rem;">Split Preview (Generated Cards)</h4>
                        <div id="split-preview-toolbar" style="display: none; margin-bottom: 0.75rem;">
                            <p class="admin-hint" style="margin: 0 0 0.5rem 0;">Check <strong>Noise</strong> on blocks that are not real stories (headers, ads, footers). <strong>Apply Split Config</strong> saves those exclusions; use <strong>Refine</strong> only when selectors still need adjustment.</p>
                            <button type="button" id="btn-ai-split-refine" class="btn btn-secondary" disabled onclick="runAiSplitRefine()">
                                Refine rules (exclude marked noise)
                            </button>
                        </div>
                        <div id="split-preview-cards-container" style="display: grid; grid-template-columns: 1fr; gap: 1rem; margin-bottom: 1rem; max-height: 25rem; overflow-y: auto; padding: 0.5rem; border: 1px dashed #ccc; background-color: #fafafa;">
                            <!-- Dynamically generated story cards will go here -->
                        </div>
                    </div>

                    <div class="admin-form-field">
                        <p class="admin-hint">Review the generated splits. Click "Apply Split Config" to save the splitting rules.</p>
                        <form method="post" action="<?= e($basePath) ?>/index.php?action=<?= e($mailModule->saveAction) ?>">
                            <?= $csrfField ?>
                            <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
                            <input type="hidden" name="match_type" value="<?= e((string)$editRow['match_type']) ?>">
                            <input type="hidden" name="match_value" value="<?= e((string)$editRow['match_value']) ?>">
                            <input type="hidden" name="display_name" value="<?= e((string)$editRow['display_name']) ?>">
                            <input type="hidden" name="category" value="<?= e((string)$editRow['category']) ?>">
                            <input type="hidden" name="disabled" value="<?= !empty($editRow['disabled']) ? '1' : '0' ?>">
                            <input type="hidden" name="show_in_magnitu" value="<?= !isset($editRow['show_in_magnitu']) || !empty($editRow['show_in_magnitu']) ? '1' : '0' ?>">
                            <input type="hidden" name="strip_listing_boilerplate" value="<?= !empty($editRow['strip_listing_boilerplate']) ? '1' : '0' ?>">
                            <input type="hidden" name="body_processor" value="<?= e((string)$editRow['body_processor']) ?>">
                            <input type="hidden" name="unsubscribe_url" value="<?= e((string)$editRow['unsubscribe_url']) ?>">
                            <input type="hidden" name="unsubscribe_mailto" value="<?= e((string)$editRow['unsubscribe_mailto']) ?>">
                            <input type="hidden" name="unsubscribe_one_click" value="<?= !empty($editRow['unsubscribe_one_click']) ? '1' : '0' ?>">
                            <input type="hidden" name="subject_filter" value="<?= e((string)($editRow['subject_filter'] ?? '')) ?>">
                            <input type="hidden" name="cleanup_config" value="<?= e($cleanupConfigRaw) ?>">
                            
                            <input type="hidden" id="split_config_hidden" name="digest_split_config" value="<?= e((string)($editRow['digest_split_config'] ?? '')) ?>">
                            <input type="hidden" id="split_config_feedback_hidden" name="digest_split_feedback" value="">
                            
                            <button type="submit" class="btn btn-success" onclick="return prepareSplitConfigApply()">
                                Apply Split Config
                            </button>
                            <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>" class="btn btn-secondary">Cancel</a>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($editRow): ?>
                <?php $subscriptionReprocessId = (int)$editRow['id']; require __DIR__ . '/partials/mail_subscription_reprocess.php'; ?>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (!$satellite && $reviewingPending && $editRow !== null): ?>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=<?= e($mailModule->saveAction) ?>" class="admin-form-card admin-form-card--review">
                <?= $csrfField ?>
                <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
                <h3><?= e($mailModule->pendingReviewTitle) ?></h3>
                <p class="admin-hint">From Gmail: <?= e((string)$editRow['match_type']) ?>: <?= e((string)$editRow['match_value']) ?><?php if ($mailModule->isNewsletter() && trim((string)($editRow['subject_filter'] ?? '')) !== ''): ?> · subject filter <code><?= e((string)$editRow['subject_filter']) ?></code><?php endif; ?></p>
                <div class="admin-form-field">
                    <label>Match type
                        <?php $mt = (string)($editRow['match_type'] ?? 'domain'); ?>
                        <select name="match_type" class="search-input" style="width:100%; max-width:16rem;">
                            <option value="domain" <?= $mt === 'domain' ? 'selected' : '' ?>>domain</option>
                            <option value="email" <?= $mt === 'email' ? 'selected' : '' ?>>email</option>
                        </select>
                    </label>
                </div>
                <div class="admin-form-field">
                    <label>Match value <input type="text" name="match_value" required class="search-input" style="width:100%;" value="<?= e((string)($editRow['match_value'] ?? '')) ?>"></label>
                </div>
                <div class="admin-form-field">
                    <label>Display name <input type="text" name="display_name" class="search-input" style="width:100%;" value="<?= e((string)($editRow['display_name'] ?? '')) ?>"></label>
                </div>
                <?php if ($mailModule->isNewsletter()): ?>
                <div class="admin-form-field">
                    <label>Subject filter <input type="text" name="subject_filter" class="search-input" style="width:100%;" value="<?= e((string)($editRow['subject_filter'] ?? '')) ?>" placeholder="e.g. Politpuls — required when several newsletters share the same sender address"></label>
                </div>
                <div class="admin-form-field">
                    <label>Digest Split Config (JSON)
                        <textarea name="digest_split_config" class="search-input" style="width:100%; height:6rem; font-family: monospace; font-size: 0.85rem;" placeholder='{"is_digest": true, "split_rules": {"split_method": "html_selector", "story_selector": "div.story", "title_selector": "h2", "body_selector": "p", "link_selector": "a"}}'><?= e((string)($editRow['digest_split_config'] ?? '')) ?></textarea>
                    </label>
                </div>
                <?php else: ?>
                <input type="hidden" name="subject_filter" value="">
                <input type="hidden" name="digest_split_config" value="">
                <?php endif; ?>
                <?php
                $categoryValue = (string)($editRow['category'] ?? '');
                $datalistId = 'mail-category-suggestions';
                require __DIR__ . '/partials/category_field.php';
                ?>
                <div class="admin-form-field">
                    <input type="hidden" name="disabled" value="0">
                    <label><input type="checkbox" name="disabled" value="1" <?= !empty($editRow['disabled']) ? 'checked' : '' ?>> Disabled</label>
                </div>
                <div class="admin-form-field">
                    <input type="hidden" name="show_in_magnitu" value="0">
                    <label><input type="checkbox" name="show_in_magnitu" value="1" <?= !isset($editRow['show_in_magnitu']) || !empty($editRow['show_in_magnitu']) ? 'checked' : '' ?>> Show in Magnitu (stored preference)</label>
                </div>
                <?php if ($editRow && !empty($editRow['cleanup_config'])): ?>
                <div class="admin-form-field">
                    <input type="hidden" name="strip_listing_boilerplate" value="0">
                    <label><input type="checkbox" name="strip_listing_boilerplate" value="1" <?= !empty($editRow['strip_listing_boilerplate']) ? 'checked' : '' ?>> Gemini: Cleanup and Webview</label>
                </div>
                <?php else: ?>
                <input type="hidden" name="strip_listing_boilerplate" value="<?= !empty($editRow['strip_listing_boilerplate']) ? '1' : '0' ?>">
                <?php endif; ?>
                <input type="hidden" name="body_processor" value="<?= e((string)($editRow['body_processor'] ?? '')) ?>">
                <div class="admin-form-field">
                    <label>Unsubscribe URL <input type="url" name="unsubscribe_url" class="search-input" style="width:100%;" value="<?= e((string)($editRow['unsubscribe_url'] ?? '')) ?>"></label>
                </div>
                <div class="admin-form-field">
                    <label>Unsubscribe mailto <input type="text" name="unsubscribe_mailto" class="search-input" style="width:100%;" value="<?= e((string)($editRow['unsubscribe_mailto'] ?? '')) ?>"></label>
                </div>
                <div class="admin-form-field">
                    <input type="hidden" name="unsubscribe_one_click" value="0">
                    <label><input type="checkbox" name="unsubscribe_one_click" value="1" <?= !empty($editRow['unsubscribe_one_click']) ? 'checked' : '' ?>> One-click unsubscribe</label>
                </div>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success">Confirm subscription</button>
                    <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
            <?php $subscriptionReprocessId = (int)$editRow['id']; require __DIR__ . '/partials/mail_subscription_reprocess.php'; ?>
            <?php endif; ?>

            <h3 class="section-title section-title--compact">Active subscriptions</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Match</th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Latest</th>
                        <th>Disabled</th>
                        <th>Magnitu</th>
                        <th>Strip boilerplate</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($subscriptions as $row): ?>
                    <?php
                    $sid = (int)$row['id'];
                    $peek = $subscriptionLatest[$sid] ?? null;
                    $latestQs = 'action=' . $mailModule->action . '&view=items&subscription=' . $sid;
                    ?>
                    <tr>
                        <td><?= $sid ?></td>
                        <td><?= e((string)$row['match_type']) ?>: <?= e((string)$row['match_value']) ?></td>
                        <td><?= e((string)$row['display_name']) ?></td>
                        <td>
                            <?php
                            $cat = trim((string)($row['category'] ?? ''));
                            if ($cat === '') {
                                echo '<span class="table-cell-placeholder">—</span>';
                            } else {
                                echo e($cat);
                            }
                            ?>
                        </td>
                        <td>
                            <?php if ($peek !== null): ?>
                                <?php
                                $subj = $peek['subject'] ?? null;
                                $linkText = 'Latest';
                                $trunc = false;
                                if ($subj !== null && $subj !== '') {
                                    $max = 56;
                                    if (function_exists('mb_strlen')) {
                                        $trunc = mb_strlen($subj) > $max;
                                        $linkText = mb_substr($subj, 0, $max);
                                    } else {
                                        $trunc = strlen($subj) > $max;
                                        $linkText = substr($subj, 0, $max);
                                    }
                                }
                                if ($trunc) {
                                    $linkText .= '…';
                                }
                                ?>
                                <a href="<?= e($basePath) ?>/index.php?<?= e($latestQs) ?>" title="<?= e($subj ?? 'Open matching mail items') ?>"><?= e($linkText) ?></a>
                            <?php else: ?>
                                <span class="table-cell-placeholder">No messages yet</span>
                            <?php endif; ?>
                        </td>
                        <td><?= !empty($row['disabled']) ? 'yes' : 'no' ?></td>
                        <td><?= !isset($row['show_in_magnitu']) || !empty($row['show_in_magnitu']) ? 'on' : 'off' ?></td>
                        <td><?= !empty($row['strip_listing_boilerplate']) ? 'on' : 'off' ?></td>
                        <td>
                            <?php if (!$satellite): ?>
                            <div class="admin-table-actions">
                                <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>&amp;edit=<?= (int)$row['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                                <?php if ($mailModule->moveAction !== null): ?>
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=<?= e($mailModule->moveAction) ?>" class="admin-inline-form">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm"><?= e($mailModule->moveTargetLabel) ?></button>
                                </form>
                                <?php endif; ?>
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=<?= e($mailModule->disableAction) ?>" class="admin-inline-form">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="btn btn-warning btn-sm" title="Disable">Unsubscribe</button>
                                </form>
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=<?= e($mailModule->deleteAction) ?>" class="admin-inline-form" onsubmit="return confirm('Remove this subscription row?');">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                                </form>
                            </div>
                            <?php else: ?>
                            <span class="table-cell-placeholder">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($subscriptions === []): ?>
                    <tr class="data-table-empty"><td colspan="9">No subscriptions.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/partials/timeline_entry_expand_script.php'; ?>
    <script>
    var activeSamples = <?= json_encode($editRowSamples ?? []) ?>;

    function switchPreviewTab(tab) {
        var btnBefore = document.getElementById('tab-btn-before');
        var btnAfter = document.getElementById('tab-btn-after');
        var contentBefore = document.getElementById('tab-content-before');
        var contentAfter = document.getElementById('tab-content-after');
        
        if (!btnBefore || !btnAfter || !contentBefore || !contentAfter) return;
        
        if (tab === 'before') {
            btnBefore.classList.add('active');
            btnAfter.classList.remove('active');
            btnBefore.style.backgroundColor = 'var(--seismo-accent, #ffd95a)';
            btnAfter.style.backgroundColor = '#ffffff';
            contentBefore.style.display = 'block';
            contentAfter.style.display = 'none';
        } else {
            btnBefore.classList.remove('active');
            btnAfter.classList.add('active');
            btnBefore.style.backgroundColor = '#ffffff';
            btnAfter.style.backgroundColor = 'var(--seismo-accent, #ffd95a)';
            contentBefore.style.display = 'none';
            contentAfter.style.display = 'block';
        }
    }

    function phpRegexToJs(phpRegexStr) {
        if (!phpRegexStr) return null;
        var match = phpRegexStr.match(/^\/(.*)\/([gimuy]*)$/s);
        if (!match) return null;
        var pattern = match[1];
        var flags = match[2] || '';
        
        pattern = pattern.replace(/\\x\{([0-9a-fA-F]+)\}/g, function(m, hex) {
            return '\\u' + ('0000' + hex).slice(-4);
        });

        if (flags.indexOf('g') === -1) {
            flags += 'g';
        }

        try {
            return new RegExp(pattern, flags);
        } catch (e) {
            return null;
        }
    }

    function applyCleanup(text, config) {
        if (!config) return text;
        var cleaned = text;
        if (config.strip_regexes && Array.isArray(config.strip_regexes)) {
            config.strip_regexes.forEach(function(regexStr) {
                var re = phpRegexToJs(regexStr);
                if (re) {
                    cleaned = cleaned.replace(re, '');
                }
            });
        }
        return cleaned;
    }

    function updatePreview() {
        var select = document.getElementById('preview-sample-select');
        var beforeEl = document.getElementById('preview-before');
        var afterEl = document.getElementById('preview-after');
        var textarea = document.getElementById('cleanup_config_json');
        
        if (!select || !beforeEl || !afterEl || !textarea) return;
        
        var index = parseInt(select.value, 10);
        if (isNaN(index) || !activeSamples[index]) {
            beforeEl.textContent = '';
            afterEl.textContent = '';
            return;
        }
        
        var sample = activeSamples[index];
        beforeEl.textContent = "Subject: " + sample.subject + "\n\n" + sample.body;
        
        var config = null;
        try {
            config = JSON.parse(textarea.value);
        } catch (e) {}
        
        var cleanedBody = applyCleanup(sample.body, config);
        afterEl.textContent = "Subject: " + sample.subject + "\n\n" + cleanedBody;
    }

    function initPreviewSelect() {
        var select = document.getElementById('preview-sample-select');
        var section = document.getElementById('ai-preview-section');
        if (!select || !section) return;
        
        select.innerHTML = '';
        if (activeSamples.length === 0) {
            section.style.display = 'none';
            return;
        }
        
        activeSamples.forEach(function(sample, i) {
            var opt = document.createElement('option');
            opt.value = i;
            var title = sample.subject || ('Sample #' + (i + 1));
            if (title.length > 50) {
                title = title.substring(0, 47) + '...';
            }
            opt.textContent = title;
            select.appendChild(opt);
        });
        
        section.style.display = 'block';
        updatePreview();
    }

    document.addEventListener('DOMContentLoaded', function() {
        initPreviewSelect();
        var textarea = document.getElementById('cleanup_config_json');
        if (textarea) {
            textarea.addEventListener('input', updatePreview);
        }
        ['cleanup-still-noise', 'cleanup-wrongly-removed'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', updateCleanupRefineButton);
            }
        });
        var refinePanel = document.getElementById('cleanup-refine-panel');
        if (refinePanel && document.getElementById('cleanup_config_json') && document.getElementById('cleanup_config_json').value.trim() !== '') {
            refinePanel.style.display = 'block';
        }
        updateCleanupRefineButton();
    });

    function parseJsonFetchResponse(res) {
        return res.text().then(function(text) {
            var data = null;
            if (text !== '') {
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    var snippet = text.replace(/\s+/g, ' ').trim().slice(0, 160);
                    throw new Error(snippet || ('Server returned HTTP ' + res.status + ' (expected JSON)'));
                }
            }
            if (!res.ok) {
                var msg = (data && data.error) ? data.error : ('Request failed (HTTP ' + res.status + ')');
                throw new Error(msg);
            }
            return data || {};
        });
    }

    var cleanupSubscriptionId = <?= (int)($editRow['id'] ?? 0) ?>;

    function parseCleanupFeedbackLines(textareaId) {
        var el = document.getElementById(textareaId);
        if (!el) return [];
        return el.value.split('\n').map(function(line) {
            return line.trim();
        }).filter(function(line) {
            return line !== '';
        }).map(function(snippet) {
            return { snippet: snippet };
        });
    }

    function updateCleanupRefineButton() {
        var btn = document.getElementById('btn-ai-cleanup-refine');
        if (!btn) return;
        var stillNoise = parseCleanupFeedbackLines('cleanup-still-noise');
        var wronglyRemoved = parseCleanupFeedbackLines('cleanup-wrongly-removed');
        btn.disabled = stillNoise.length === 0 && wronglyRemoved.length === 0;
    }

    function applyCleanupAnalysisResponse(data) {
        var status = document.getElementById('ai-analysis-status');
        var verificationBox = document.getElementById('ai-cleanup-verification');
        var textarea = document.getElementById('cleanup_config_json');
        var resultsPanel = document.getElementById('ai-results-panel');
        var refinePanel = document.getElementById('cleanup-refine-panel');
        var verified = data.verification && data.verification.verified;

        if (status) {
            status.style.display = 'inline';
            status.style.color = verified ? 'green' : '#b45309';
            status.textContent = data.verification && data.verification.message
                ? data.verification.message
                : (data.refined ? 'Refinement complete!' : 'Analysis complete!');
        }

        if (verificationBox && data.verification) {
            var lines = [];
            if (data.verification.attempts) {
                lines.push('Gemini attempts: ' + data.verification.attempts);
            }
            if (data.verification.issues && data.verification.issues.length > 0) {
                data.verification.issues.slice(0, 3).forEach(function(issue) {
                    lines.push('Sample ' + issue.sample_index + ': ' + issue.type + ' — ' + (issue.snippet || '').substring(0, 60));
                });
            }
            if (lines.length > 0) {
                verificationBox.textContent = lines.join(' · ');
                verificationBox.style.display = 'block';
                verificationBox.style.color = verified ? '#166534' : '#b45309';
            } else {
                verificationBox.style.display = 'none';
                verificationBox.textContent = '';
            }
        }

        if (textarea && data.config) {
            textarea.value = JSON.stringify(data.config, null, 2);
        }

        var dscTextarea = document.getElementById('digest_split_config_json');
        if (dscTextarea) {
            if (data.digest_split_config) {
                try {
                    var parsed = typeof data.digest_split_config === 'string'
                        ? JSON.parse(data.digest_split_config)
                        : data.digest_split_config;
                    dscTextarea.value = JSON.stringify(parsed, null, 2);
                } catch (e) {
                    dscTextarea.value = data.digest_split_config;
                }
            } else {
                dscTextarea.value = '';
            }
        }

        activeSamples = data.samples || [];
        initPreviewSelect();
        if (resultsPanel) resultsPanel.style.display = 'block';
        if (refinePanel) refinePanel.style.display = 'block';
        updateCleanupRefineButton();
    }

    function runAiAnalysis(id) {
        var btn = document.getElementById('btn-ai-analyze');
        var status = document.getElementById('ai-analysis-status');
        var verificationBox = document.getElementById('ai-cleanup-verification');

        cleanupSubscriptionId = id;
        btn.disabled = true;
        status.style.display = 'inline';
        status.style.color = '#333';
        status.textContent = 'Analyzing with Gemini (up to 2 API calls, may take 1–3 min)…';
        if (verificationBox) {
            verificationBox.style.display = 'none';
            verificationBox.textContent = '';
        }

        var formData = new FormData();
        formData.append('id', id);
        formData.append('_csrf', '<?= CsrfToken::ensure() ?>');

        fetch('<?= e($basePath) ?>/index.php?action=<?= e($mailModule->analyzeAction) ?>', {
            method: 'POST',
            body: formData
        })
        .then(parseJsonFetchResponse)
        .then(function(data) {
            applyCleanupAnalysisResponse(data);
            btn.disabled = false;
        })
        .catch(function(err) {
            status.style.color = 'red';
            status.textContent = 'Error: ' + err.message;
            btn.disabled = false;
        });
    }

    function runAiCleanupRefine() {
        if (!cleanupSubscriptionId) return;

        var refineBtn = document.getElementById('btn-ai-cleanup-refine');
        var analyzeBtn = document.getElementById('btn-ai-analyze');
        var status = document.getElementById('ai-analysis-status');
        var textarea = document.getElementById('cleanup_config_json');
        var feedback = {
            still_noise: parseCleanupFeedbackLines('cleanup-still-noise'),
            wrongly_removed: parseCleanupFeedbackLines('cleanup-wrongly-removed')
        };

        if (feedback.still_noise.length === 0 && feedback.wrongly_removed.length === 0) {
            status.style.color = '#b45309';
            status.textContent = 'Add still-visible noise or wrongly removed content first.';
            return;
        }

        refineBtn.disabled = true;
        if (analyzeBtn) analyzeBtn.disabled = true;
        status.style.display = 'inline';
        status.style.color = '#333';
        status.textContent = 'Refining cleanup rules with Gemini...';

        var formData = new FormData();
        formData.append('id', cleanupSubscriptionId);
        formData.append('refine', '1');
        formData.append('cleanup_config', textarea.value);
        formData.append('feedback', JSON.stringify(feedback));
        formData.append('_csrf', '<?= CsrfToken::ensure() ?>');

        fetch('<?= e($basePath) ?>/index.php?action=<?= e($mailModule->analyzeAction) ?>', {
            method: 'POST',
            body: formData
        })
        .then(parseJsonFetchResponse)
        .then(function(data) {
            applyCleanupAnalysisResponse(data);
            refineBtn.disabled = false;
            if (analyzeBtn) analyzeBtn.disabled = false;
        })
        .catch(function(err) {
            status.style.color = 'red';
            status.textContent = 'Error: ' + err.message;
            refineBtn.disabled = false;
            if (analyzeBtn) analyzeBtn.disabled = false;
            updateCleanupRefineButton();
        });
    }

    var splitSubscriptionId = <?= (int)($editRow['id'] ?? 0) ?>;

    function truncatePreviewText(text, maxLen) {
        if (!text) return '';
        if (text.length <= maxLen) return text;
        return text.substring(0, maxLen) + '…';
    }

    function updateSplitRefineButton() {
        var refineBtn = document.getElementById('btn-ai-split-refine');
        if (!refineBtn) return;
        var noiseCount = document.querySelectorAll('.split-noise-toggle:checked').length;
        refineBtn.disabled = noiseCount === 0;
    }

    function applySplitAnalysisResponse(data, options) {
        options = options || {};
        var status = document.getElementById('ai-split-status');
        var resultsPanel = document.getElementById('ai-split-results-panel');
        var textarea = document.getElementById('split_config_json');
        var verificationBox = document.getElementById('ai-split-verification');
        var verified = data.verification && data.verification.verified;
        var previewCount = (data.preview_stories && data.preview_stories.length) ? data.preview_stories.length : 0;
        var hasConfig = !!data.digest_split_config;

        if (status) {
            status.style.display = 'inline';
            if (hasConfig && previewCount > 0) {
                status.style.color = 'green';
                status.textContent = data.refined ? 'Refinement complete!' : 'Analysis complete!';
            } else if (!hasConfig && verified) {
                status.style.color = 'green';
                status.textContent = data.verification && data.verification.message
                    ? data.verification.message
                    : 'Not a digest — no split config needed.';
            } else {
                status.style.color = '#b45309';
                status.textContent = data.verification && data.verification.message
                    ? data.verification.message
                    : (data.refined ? 'Refinement did not produce cards.' : 'Analysis did not produce preview cards.');
            }
        }

        if (verificationBox && data.verification) {
            var lines = [];
            if (data.verification.expected_counts && data.verification.expected_counts.length > 0) {
                data.verification.expected_counts.forEach(function(expected, idx) {
                    var actual = (data.verification.actual_counts && data.verification.actual_counts[idx] !== undefined)
                        ? data.verification.actual_counts[idx]
                        : '?';
                    lines.push('Sample ' + (idx + 1) + ': expected ' + expected + ' cards, got ' + actual);
                });
            } else if (data.verification.actual_counts && data.verification.actual_counts.length > 0) {
                lines.push('Sample 1: got ' + data.verification.actual_counts[0] + ' card(s)');
            }
            if (data.verification.attempts) {
                lines.push('Gemini attempts: ' + data.verification.attempts);
            }
            if (lines.length > 0) {
                verificationBox.textContent = lines.join(' · ');
                verificationBox.style.display = 'block';
                verificationBox.style.color = verified ? '#166534' : '#b45309';
            }
        }

        if (textarea) {
            if (data.digest_split_config) {
                try {
                    var parsed = typeof data.digest_split_config === 'string'
                        ? JSON.parse(data.digest_split_config)
                        : data.digest_split_config;
                    textarea.value = JSON.stringify(parsed, null, 2);
                } catch (e) {
                    textarea.value = data.digest_split_config;
                }
            } else if (!options.keepConfigOnEmpty) {
                textarea.value = '';
            }
        }

        renderSplitPreviewCards(data.preview_stories || []);
        if (resultsPanel) {
            resultsPanel.style.display = 'block';
        }
    }

    function renderSplitPreviewCards(stories) {
        var previewSection = document.getElementById('ai-split-preview-section');
        var container = document.getElementById('split-preview-cards-container');
        var toolbar = document.getElementById('split-preview-toolbar');
        if (!container || !previewSection) return;

        container.innerHTML = '';
        if (toolbar) {
            toolbar.style.display = stories.length > 0 ? 'block' : 'none';
        }

        if (stories.length === 0) {
            var empty = document.createElement('div');
            empty.textContent = 'No split stories generated by the config rules.';
            empty.style.padding = '1rem';
            empty.style.color = '#888';
            empty.style.fontStyle = 'italic';
            container.appendChild(empty);
            previewSection.style.display = 'block';
            updateSplitRefineButton();
            return;
        }

        stories.forEach(function(story, index) {
            if (index > 0) {
                var connector = document.createElement('div');
                connector.className = 'split-preview-glue-connector';
                connector.dataset.aboveIndex = String(index - 1);
                connector.dataset.belowIndex = String(index);

                var glueBtn = document.createElement('button');
                glueBtn.type = 'button';
                glueBtn.className = 'btn-split-glue';
                glueBtn.innerHTML = '<span>➕ Merge Blocks</span>';
                glueBtn.addEventListener('click', function() {
                    connector.classList.toggle('active');
                    if (connector.classList.contains('active')) {
                        glueBtn.innerHTML = '<span>🔗 Merged</span>';
                    } else {
                        glueBtn.innerHTML = '<span>➕ Merge Blocks</span>';
                    }
                    updateSplitRefineButton();
                });
                connector.appendChild(glueBtn);
                container.appendChild(connector);
            }

            var card = document.createElement('div');
            card.className = 'split-preview-card';
            card.dataset.storyIndex = String(index);
            card.dataset.title = story.title || '';
            card.dataset.textPreview = truncatePreviewText(story.text_body || '', 400);
            card.dataset.htmlPreview = truncatePreviewText(story.html_body || '', 800);
            card.style.background = '#ffffff';
            card.style.border = '2px solid black';
            card.style.padding = '1rem';
            card.style.boxShadow = '3px 3px 0px rgba(0,0,0,1)';
            card.style.display = 'flex';
            card.style.flexDirection = 'column';
            card.style.gap = '0.5rem';

            var header = document.createElement('div');
            header.style.display = 'flex';
            header.style.justifyContent = 'space-between';
            header.style.alignItems = 'center';
            header.style.borderBottom = '1px solid #eee';
            header.style.paddingBottom = '0.25rem';
            header.style.flexWrap = 'wrap';
            header.style.gap = '0.5rem';

            var badge = document.createElement('span');
            badge.textContent = 'Block #' + (index + 1);
            badge.style.fontSize = '0.75rem';
            badge.style.fontWeight = 'bold';
            badge.style.background = 'var(--seismo-accent, #ffd95a)';
            badge.style.border = '1px solid black';
            badge.style.padding = '1px 6px';
            header.appendChild(badge);

            var noiseLabel = document.createElement('label');
            noiseLabel.style.display = 'flex';
            noiseLabel.style.alignItems = 'center';
            noiseLabel.style.gap = '0.35rem';
            noiseLabel.style.fontSize = '0.8rem';
            noiseLabel.style.fontWeight = 'bold';
            noiseLabel.style.cursor = 'pointer';

            var noiseToggle = document.createElement('input');
            noiseToggle.type = 'checkbox';
            noiseToggle.className = 'split-noise-toggle';
            noiseToggle.addEventListener('change', function() {
                if (noiseToggle.checked) {
                    card.style.opacity = '0.55';
                    card.style.borderColor = '#b91c1c';
                    card.style.background = '#fef2f2';
                } else {
                    card.style.opacity = '1';
                    card.style.borderColor = 'black';
                    card.style.background = '#ffffff';
                }
                updateSplitRefineButton();
            });
            noiseLabel.appendChild(noiseToggle);
            noiseLabel.appendChild(document.createTextNode('Noise (exclude)'));
            header.appendChild(noiseLabel);
            card.appendChild(header);

            var title = document.createElement('h4');
            title.textContent = story.title || '(No Title)';
            title.style.margin = '0';
            title.style.fontSize = '1rem';
            title.style.fontWeight = 'bold';
            card.appendChild(title);

            var body = document.createElement('div');
            body.textContent = story.text_body || '';
            body.style.fontSize = '0.85rem';
            body.style.color = '#333';
            body.style.lineHeight = '1.4';
            card.appendChild(body);

            if (story.link) {
                var link = document.createElement('a');
                link.href = story.link;
                link.target = '_blank';
                link.textContent = 'Read Link →';
                link.style.fontSize = '0.8rem';
                link.style.fontWeight = 'bold';
                link.style.color = 'black';
                link.style.textDecoration = 'underline';
                link.style.alignSelf = 'flex-start';
                card.appendChild(link);
            }

            container.appendChild(card);
        });

        previewSection.style.display = 'block';
        updateSplitRefineButton();
    }

    function mergeSplitNoiseFeedback(config, feedback) {
        if (!config || typeof config !== 'object' || !feedback || !Array.isArray(feedback.blocks)) {
            return config;
        }
        var rules = config.split_rules;
        if (!rules || typeof rules !== 'object') {
            rules = {};
            config.split_rules = rules;
        }
        var exclude = Array.isArray(rules.exclude_titles) ? rules.exclude_titles.slice() : [];
        feedback.blocks.forEach(function(block) {
            if (!block || block.verdict !== 'noise') {
                return;
            }
            var title = (block.title || '').trim();
            if (title === '' || title === '(No Title)' || exclude.indexOf(title) !== -1) {
                return;
            }
            exclude.push(title);
        });
        if (exclude.length > 0) {
            rules.exclude_titles = exclude;
        }
        return config;
    }

    function prepareSplitConfigApply() {
        var textarea = document.getElementById('split_config_json');
        var hidden = document.getElementById('split_config_hidden');
        var feedbackHidden = document.getElementById('split_config_feedback_hidden');
        var feedback = collectSplitFeedback();
        if (feedbackHidden) {
            feedbackHidden.value = JSON.stringify(feedback);
        }
        if (!textarea || !hidden) {
            return true;
        }
        var raw = textarea.value.trim();
        if (raw === '') {
            hidden.value = '';
            return true;
        }
        try {
            var config = JSON.parse(raw);
            config = mergeSplitNoiseFeedback(config, feedback);
            hidden.value = JSON.stringify(config);
            textarea.value = JSON.stringify(config, null, 2);
        } catch (e) {
            hidden.value = raw;
        }
        return true;
    }

    function collectSplitFeedback() {
        var blocks = [];
        var cards = document.querySelectorAll('#split-preview-cards-container .split-preview-card');
        cards.forEach(function(card, idx) {
            var toggle = card.querySelector('.split-noise-toggle');
            var nextConnector = card.nextElementSibling;
            var glueWithNext = false;
            if (nextConnector && nextConnector.classList.contains('split-preview-glue-connector')) {
                glueWithNext = nextConnector.classList.contains('active');
            }

            blocks.push({
                index: parseInt(card.dataset.storyIndex, 10),
                verdict: toggle && toggle.checked ? 'noise' : 'keep',
                title: card.dataset.title || '',
                text_preview: card.dataset.textPreview || '',
                html_preview: card.dataset.htmlPreview || '',
                glue_with_next: glueWithNext
            });
        });
        return { blocks: blocks };
    }

    function toggleSplitAdvancedMode() {
        var chk = document.getElementById('split-advanced-mode');
        var container = document.getElementById('split-advanced-container');
        if (chk && container) {
            container.style.display = chk.checked ? 'block' : 'none';
        }
    }

    function runAiSplitAnalysis(id) {
        var btn = document.getElementById('btn-ai-split-analyze');
        var status = document.getElementById('ai-split-status');
        var previewSection = document.getElementById('ai-split-preview-section');
        var verificationBox = document.getElementById('ai-split-verification');

        splitSubscriptionId = id;
        btn.disabled = true;
        status.style.display = 'inline';
        status.style.color = '#333';
        status.textContent = 'Analyzing splitting structure with Gemini...';
        if (previewSection) previewSection.style.display = 'none';
        if (verificationBox) {
            verificationBox.style.display = 'none';
            verificationBox.textContent = '';
        }

        var formData = new FormData();
        formData.append('id', id);
        formData.append('_csrf', '<?= CsrfToken::ensure() ?>');

        var advMode = document.getElementById('split-advanced-mode');
        var keepText = document.getElementById('split-keep-text');
        if (advMode && advMode.checked && keepText) {
            formData.append('keep_text', keepText.value);
        }

        fetch('<?= e($basePath) ?>/index.php?action=<?= e($mailModule->analyzeSplittingAction) ?>', {
            method: 'POST',
            body: formData
        })
        .then(parseJsonFetchResponse)
        .then(function(data) {
            applySplitAnalysisResponse(data);
            btn.disabled = false;
        })
        .catch(function(err) {
            status.style.color = 'red';
            status.textContent = 'Error: ' + err.message;
            btn.disabled = false;
        });
    }

    function runAiSplitRefine() {
        if (!splitSubscriptionId) return;

        var refineBtn = document.getElementById('btn-ai-split-refine');
        var analyzeBtn = document.getElementById('btn-ai-split-analyze');
        var status = document.getElementById('ai-split-status');
        var textarea = document.getElementById('split_config_json');
        var feedback = collectSplitFeedback();
        var noiseMarked = feedback.blocks.filter(function(b) { return b.verdict === 'noise'; }).length;

        if (noiseMarked === 0) {
            status.style.color = '#b45309';
            status.textContent = 'Mark at least one block as noise first.';
            return;
        }

        refineBtn.disabled = true;
        if (analyzeBtn) analyzeBtn.disabled = true;
        status.style.display = 'inline';
        status.style.color = '#333';
        status.textContent = 'Refining rules with Gemini (excluding ' + noiseMarked + ' noise block(s))...';

        var formData = new FormData();
        formData.append('id', splitSubscriptionId);
        formData.append('refine', '1');
        formData.append('digest_split_config', textarea.value);
        formData.append('feedback', JSON.stringify(feedback));
        formData.append('_csrf', '<?= CsrfToken::ensure() ?>');

        fetch('<?= e($basePath) ?>/index.php?action=<?= e($mailModule->analyzeSplittingAction) ?>', {
            method: 'POST',
            body: formData
        })
        .then(parseJsonFetchResponse)
        .then(function(data) {
            applySplitAnalysisResponse(data);
            refineBtn.disabled = false;
            if (analyzeBtn) analyzeBtn.disabled = false;
        })
        .catch(function(err) {
            status.style.color = 'red';
            status.textContent = 'Error: ' + err.message;
            refineBtn.disabled = false;
            if (analyzeBtn) analyzeBtn.disabled = false;
            updateSplitRefineButton();
        });
    }
    </script>
</body>
</html>

