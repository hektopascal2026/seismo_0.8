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
 */

declare(strict_types=1);

use Seismo\Core\Mail\EmailBodyProcessorRegistry;
use Seismo\Http\CsrfToken;

$basePath = getBasePath();
$processorChoices = EmailBodyProcessorRegistry::choicesForAdmin();
$accent     = seismoBrandAccent();

$headerTitle    = 'Mail';
$headerSubtitle = 'IMAP / newsletter';
$activeNav      = 'mail';

$itemsQs   = 'action=mail';
$sourcesQs = 'action=mail&view=sources';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($headerTitle) ?> — <?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
    <?php if ($accent): ?>
    <style>:root { --seismo-accent: <?= e($accent) ?>; }</style>
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
                    Mail items filtered to subscription #<?= (int)$subscriptionFilter['id'] ?> (<?= e($sfLabel) ?>).
                    <a href="<?= e($basePath) ?>/index.php?action=mail">Show all mail</a>
                    · <a href="<?= e($basePath) ?>/index.php?action=mail&amp;view=sources&amp;edit=<?= (int)$subscriptionFilter['id'] ?>">Edit source</a>
                </p>
                <?php if (!$satellite): ?>
                    <?php $subscriptionReprocessId = (int)$subscriptionFilter['id']; require __DIR__ . '/partials/mail_subscription_reprocess.php'; ?>
                <?php endif; ?>
            <?php endif; ?>
            <div class="section-title-row">
                <h2 class="section-title">
                    <?= count($allItems) ?> <?= count($allItems) === 1 ? 'entry' : 'entries' ?>
                </h2>
                <button class="btn btn-secondary entry-expand-all-btn">expand all &#9660;</button>
            </div>
            <?php if ($dashboardError !== null): ?>
            <?php elseif ($allItems !== []): ?>
                <?php include __DIR__ . '/partials/dashboard_entry_loop.php'; ?>
            <?php else: ?>
                <div class="empty-state">
                    <p>No email rows yet. Configure IMAP fetch separately; subscription rules live under <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>">Sources</a>.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="latest-entries-section">
            <h2 class="section-title">Mail sources</h2>
            <p class="admin-intro">Domain-first matching (e.g. <code>example.com</code> covers <code>alice@example.com</code>). Per-address overrides use match type <em>email</em>. When Gmail ingests mail from an unknown domain, it is queued under <strong>New senders</strong> for review before it appears in the table below.</p>

            <?php if ($pendingSenders !== []): ?>
            <section class="admin-new-senders-section" aria-labelledby="new-senders-heading">
                <h3 id="new-senders-heading" class="section-title section-title--compact">New senders <span class="admin-new-senders-badge"><?= count($pendingSenders) ?></span></h3>
                <p class="admin-hint">Detected from Gmail ingest — confirm display name and options, then save to activate.</p>
                <table class="data-table data-table--new-senders">
                    <thead>
                        <tr>
                            <th>Match</th>
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
                        $latestQs = 'action=mail&view=items&subscription=' . $sid;
                        ?>
                        <tr>
                            <td><?= e((string)$row['match_type']) ?>: <?= e((string)$row['match_value']) ?></td>
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
                                    <a href="<?= e($basePath) ?>/index.php?action=mail&amp;view=sources&amp;edit=<?= $sid ?>" class="btn btn-primary btn-sm">Review</a>
                                    <form method="post" action="<?= e($basePath) ?>/index.php?action=mail_subscription_delete" class="admin-inline-form" onsubmit="return confirm('Dismiss this proposed sender?');">
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
            <form method="post" action="<?= e($basePath) ?>/index.php?action=mail_subscription_save" class="admin-form-card">
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
                <div class="admin-form-field">
                    <input type="hidden" name="strip_listing_boilerplate" value="0">
                    <label><input type="checkbox" name="strip_listing_boilerplate" value="1" <?= !empty($editRow['strip_listing_boilerplate']) ? 'checked' : '' ?>> Strip typical boilerplate for this sender (also available globally under Settings → Mail; applies at ingest, on cards, recipe scoring, and Magnitu export)</label>
                </div>
                <div class="admin-form-field">
                    <?php $proc = (string)($editRow['body_processor'] ?? ''); ?>
                    <label>Body processor
                        <select name="body_processor" class="search-input" style="width:100%; max-width:28rem;">
                            <?php foreach ($processorChoices as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $proc === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <p class="admin-hint">Processors run at ingest and when you reprocess stored mail. Use for digests with generic subjects (e.g. EP TODAY).</p>
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
                <p class="admin-intro">Select 5 recent emails from this sender to analyze with Gemini and statically generate regular expressions and WebView keywords.</p>
                
                <div class="admin-form-field">
                    <button type="button" id="btn-ai-analyze" class="btn btn-secondary" onclick="runAiAnalysis(<?= (int)$editRow['id'] ?>)">
                        Analyze sample emails with Gemini
                    </button>
                    <span id="ai-analysis-status" style="margin-left: 10px; font-weight: bold; display: none;" class="type-sample-small"></span>
                </div>

                <div id="ai-results-panel" style="display: <?= $cleanupConfigRaw !== '' ? 'block' : 'none' ?>;">
                    <div class="admin-form-field">
                        <label>Generated JSON Config:
                            <textarea id="cleanup_config_json" class="search-input" style="width: 100%; height: 8rem; font-family: monospace; font-size: 0.85rem;" placeholder='{"strip_regexes": [], "webview_keywords": [], "title_extractor": null}'><?= e($cleanupConfigRaw) ?></textarea>
                        </label>
                    </div>

                    <div class="admin-form-field">
                        <p class="admin-hint">Make adjustments to the JSON above if needed, then save to apply locally.</p>
                        <form method="post" action="<?= e($basePath) ?>/index.php?action=mail_subscription_save">
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
                            
                            <input type="hidden" id="cleanup_config_hidden" name="cleanup_config" value="<?= e($cleanupConfigRaw) ?>">
                            
                            <button type="submit" class="btn btn-success" onclick="document.getElementById('cleanup_config_hidden').value = document.getElementById('cleanup_config_json').value;">
                                Save Config &amp; Apply
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($editRow): ?>
                <?php $subscriptionReprocessId = (int)$editRow['id']; require __DIR__ . '/partials/mail_subscription_reprocess.php'; ?>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (!$satellite && $reviewingPending && $editRow !== null): ?>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=mail_subscription_save" class="admin-form-card admin-form-card--review">
                <?= $csrfField ?>
                <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
                <h3>Review sender</h3>
                <p class="admin-hint">From Gmail: <?= e((string)$editRow['match_type']) ?>: <?= e((string)$editRow['match_value']) ?></p>
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
                <div class="admin-form-field">
                    <input type="hidden" name="strip_listing_boilerplate" value="0">
                    <label><input type="checkbox" name="strip_listing_boilerplate" value="1" <?= !empty($editRow['strip_listing_boilerplate']) ? 'checked' : '' ?>> Strip typical boilerplate for this sender</label>
                </div>
                <div class="admin-form-field">
                    <?php $proc = (string)($editRow['body_processor'] ?? ''); ?>
                    <label>Body processor
                        <select name="body_processor" class="search-input" style="width:100%; max-width:28rem;">
                            <?php foreach ($processorChoices as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $proc === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
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
                    $latestQs = 'action=mail&view=items&subscription=' . $sid;
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
                                <a href="<?= e($basePath) ?>/index.php?action=mail&amp;view=sources&amp;edit=<?= (int)$row['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=mail_subscription_disable" class="admin-inline-form">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="btn btn-warning btn-sm" title="Disable">Unsubscribe</button>
                                </form>
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=mail_subscription_delete" class="admin-inline-form" onsubmit="return confirm('Remove this subscription row?');">
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

    <script>
    (function() {
        function collapse(card, btn) {
            var preview = card.querySelector('.entry-preview');
            var full    = card.querySelector('.entry-full-content');
            if (!preview || !full) return;
            full.style.display = 'none';
            preview.style.display = '';
            if (btn) btn.textContent = 'expand \u25BC';
        }
        function expand(card, btn) {
            var preview = card.querySelector('.entry-preview');
            var full    = card.querySelector('.entry-full-content');
            if (!preview || !full) return;
            preview.style.display = 'none';
            full.style.display    = 'block';
            if (btn) btn.textContent = 'collapse \u25B2';
        }
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.entry-expand-btn');
            if (!btn) return;
            var card = btn.closest('.entry-card');
            var full = card.querySelector('.entry-full-content');
            if (!full) return;
            full.style.display === 'block' ? collapse(card, btn) : expand(card, btn);
        });
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.entry-expand-all-btn');
            if (!btn) return;
            var isExpanded = btn.dataset.expanded === 'true';
            document.querySelectorAll('.entry-card').forEach(function(card) {
                var cardBtn = card.querySelector('.entry-expand-btn');
                isExpanded ? collapse(card, cardBtn) : expand(card, cardBtn);
            });
            btn.dataset.expanded = !isExpanded;
            btn.textContent = !isExpanded ? 'collapse all \u25B2' : 'expand all \u25BC';
        });
    })();

    function runAiAnalysis(id) {
        var btn = document.getElementById('btn-ai-analyze');
        var status = document.getElementById('ai-analysis-status');
        var resultsPanel = document.getElementById('ai-results-panel');
        var textarea = document.getElementById('cleanup_config_json');

        btn.disabled = true;
        status.style.display = 'inline';
        status.style.color = '#333';
        status.textContent = 'Contacting Gemini...';

        var formData = new FormData();
        formData.append('id', id);
        formData.append('_csrf', '<?= CsrfToken::ensure() ?>');

        fetch('<?= e($basePath) ?>/index.php?action=mail_subscription_analyze', {
            method: 'POST',
            body: formData
        })
        .then(function(res) {
            return res.json().then(function(data) {
                if (!res.ok) {
                    throw new Error(data.error || 'Request failed');
                }
                return data;
            });
        })
        .then(function(data) {
            status.style.color = 'green';
            status.textContent = 'Analysis complete!';
            textarea.value = JSON.stringify(data.config, null, 2);
            resultsPanel.style.display = 'block';
            btn.disabled = false;
        })
        .catch(function(err) {
            status.style.color = 'red';
            status.textContent = 'Error: ' + err.message;
            btn.disabled = false;
        });
    }
    </script>
</body>
</html>
