<?php
/**
 * Email timeline — Items | Subscriptions (Slice 8).
 *
 * @var array<int, array<string, mixed>> $allItems
 * @var list<array<string, mixed>> $subscriptions
 * @var array<int, ?array{email_id: int, subject: ?string}> $subscriptionLatest
 * @var ?array<string, mixed> $subscriptionFilter
 * @var ?array<string, mixed> $editRow
 * @var ?string $pageError
 * @var string $csrfField
 * @var float $alertThreshold
 * @var string $view 'items'|'subscriptions'
 * @var bool $satellite
 * @var ?string $dashboardError
 */

declare(strict_types=1);

$basePath = getBasePath();
$accent     = seismoBrandAccent();

$headerTitle    = 'Mail';
$headerSubtitle = 'IMAP / newsletter';
$activeNav      = 'mail';

$itemsQs          = 'action=mail';
$subscriptionsQs = 'action=mail&view=subscriptions';
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
            <a href="<?= e($basePath) ?>/index.php?<?= e($subscriptionsQs) ?>" class="btn <?= $view === 'subscriptions' ? 'btn-primary' : 'btn-secondary' ?>">Subscriptions</a>
        </div>

        <?php if ($satellite && $view === 'subscriptions'): ?>
            <p class="message message-info">Satellite mode: subscriptions are read-only here. Manage them on the mothership.</p>
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
                </p>
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
                    <p>No email rows yet. Configure IMAP fetch separately; subscription rules live under <a href="<?= e($basePath) ?>/index.php?<?= e($subscriptionsQs) ?>">Subscriptions</a>.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="latest-entries-section">
            <h2 class="section-title">Email subscriptions</h2>
            <p class="admin-intro">Domain-first matching (e.g. <code>example.com</code> covers <code>alice@example.com</code>). Per-address overrides use match type <em>email</em>. <code>show_in_magnitu</code> is stored for future pipeline use — the Magnitu export API does not filter on it yet.</p>

            <?php if (!$satellite): ?>
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
                <div class="admin-form-field">
                    <label>Category <input type="text" name="category" class="search-input" style="width:100%; max-width:24rem;" value="<?= e((string)($editRow['category'] ?? '')) ?>"></label>
                </div>
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
                    <label><input type="checkbox" name="strip_listing_boilerplate" value="1" <?= !empty($editRow['strip_listing_boilerplate']) ? 'checked' : '' ?>> Strip typical boilerplate (example: email subject repeated in body, &ldquo;Medienmitteilung&rdquo;, &ldquo;view in browser&rdquo; and image display lines, etc., including EN/DE; applies to new mail in the DB, recipe scoring, Magnitu sync, and dashboard cards)</label>
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
                    <button type="submit" class="btn btn-success"><?= $editRow ? 'Save' : 'Add subscription' ?></button>
                    <?php if ($editRow): ?>
                        <a href="<?= e($basePath) ?>/index.php?<?= e($subscriptionsQs) ?>" class="btn btn-secondary">Cancel edit</a>
                    <?php endif; ?>
                </div>
            </form>
            <?php endif; ?>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Match</th>
                        <th>Name</th>
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
                                <a href="<?= e($basePath) ?>/index.php?action=mail&amp;view=subscriptions&amp;edit=<?= (int)$row['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
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
                    <tr class="data-table-empty"><td colspan="8">No subscriptions.</td></tr>
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
    </script>
</body>
</html>
