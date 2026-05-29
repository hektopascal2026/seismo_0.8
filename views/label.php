<?php
/**
 * Magnitu training labels — in-browser queue (ex–magnitu-mini).
 *
 * @var string $csrfField
 * @var string $queueJson JSON array of magnitu_entries-shaped rows
 * @var ?string $pageError
 * @var string $filter all|lex_item|feed_item
 * @var int $offset     Current per-family OFFSET into the export queue
 * @var int $nextOffset $offset + PER_FAMILY — used by the "fetch older" links
 * @var int $labelSessionCount labels saved in this browser session
 * @var int $labelTotalCount   rows in magnitu_labels on this instance
 */

declare(strict_types=1);

$basePath = getBasePath();
$accent    = seismoBrandAccent();

$headerTitle    = 'Label';
$headerSubtitle = 'Magnitu training data';
$activeNav      = 'label';

$bp = $basePath . '/index.php';
$filterQs = static function (string $t) use ($bp): string {
    return $bp . '?' . http_build_query(['action' => 'label', 'type' => $t]);
};
$pageUrl = static function (string $t, int $off) use ($bp): string {
    $qs = ['action' => 'label', 'type' => $t];
    if ($off > 0) {
        $qs['offset'] = $off;
    }
    return $bp . '?' . http_build_query($qs);
};
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
    <style>
        .label-page-toolbar { display:flex; flex-wrap:wrap; gap:0; margin:0 0 1rem; border:2px solid #111; }
        .label-page-toolbar a {
            flex:1; min-width:5rem; text-align:center; padding:0.5rem 0.35rem; font-size:0.8rem; font-weight:700;
            text-decoration:none; color:#111; border-right:2px solid #111; background:#fff;
        }
        .label-page-toolbar a:last-child { border-right:0; }
        .label-page-toolbar a.active { background: var(--seismo-accent, #FF6B6B); color:#111; }
        .label-card-area { display:flex; flex-direction:column; gap:0.75rem; }
        .label-entry-card {
            background:#fff; border:2px solid #111; padding:0.85rem 1rem;
        }
        .label-entry-card.labeled { opacity:0.28; pointer-events:none; transition:opacity 0.35s; }
        .label-entry-meta { display:flex; flex-wrap:wrap; gap:0.35rem; align-items:center; margin-bottom:0.35rem; font-size:0.72rem; }
        .label-source-tag {
            padding:0.15rem 0.45rem; font-size:0.65rem; font-weight:700; border:2px solid #111; text-transform:uppercase;
            background:#eee;
        }
        .label-entry-title { font-size:0.95rem; font-weight:700; margin:0.25rem 0; line-height:1.35; }
        .label-entry-title a { color:inherit; }
        .label-entry-desc { font-size:0.82rem; color:#333; line-height:1.45; margin:0.25rem 0; }
        .label-entry-source { font-size:0.72rem; color:#666; }
        .label-reasoning { width:100%; margin-top:0.5rem; padding:0.45rem 0.5rem; border:1.5px solid #ccc; font-size:0.85rem; font-family:inherit; }
        .label-reasoning:focus { outline:none; border-color:#111; }
        .label-btn-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.35rem; margin-top:0.5rem; }
        .label-hidden-csrf { display:none; }
        .label-progress {
            margin: 0 0 1rem;
            padding: 0.55rem 0.75rem;
            border: 2px dashed #111;
            background: #f8f8f8;
            font-size: 0.82rem;
            line-height: 1.4;
        }
        .label-progress strong { font-weight: 800; }
        .label-progress.label-progress--bump strong {
            animation: label-progress-bump 0.45s ease;
        }
        @keyframes label-progress-bump {
            0% { transform: scale(1); }
            40% { transform: scale(1.12); color: var(--seismo-accent, #FF6B6B); }
            100% { transform: scale(1); }
        }
    </style>
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

        <p class="label-progress" id="label-progress" aria-live="polite">
            <strong id="label-session-count"><?= (int)($labelSessionCount ?? 0) ?></strong> labeled this session
            &middot; <strong id="label-total-count"><?= (int)($labelTotalCount ?? 0) ?></strong> training labels total
        </p>

        <nav class="label-page-toolbar" aria-label="Entry family filter">
            <a href="<?= e($filterQs('all')) ?>" class="<?= $filter === 'all' ? 'active' : '' ?>">All</a>
            <a href="<?= e($filterQs('lex_item')) ?>" class="<?= $filter === 'lex_item' ? 'active' : '' ?>">Legislation</a>
            <a href="<?= e($filterQs('feed_item')) ?>" class="<?= $filter === 'feed_item' ? 'active' : '' ?>">News</a>
        </nav>

        <?php if ($offset > 0): ?>
            <p class="admin-intro" style="font-size:0.75rem; color:#666; margin-top:-0.5rem;">
                Showing items from offset <?= e((string)$offset) ?> per family.
                <a href="<?= e($filterQs($filter)) ?>">Back to newest</a>
            </p>
        <?php endif; ?>

        <?php if ($pageError !== null): ?>
            <div class="message message-error"><?= e($pageError) ?></div>
        <?php endif; ?>

        <div id="label-app-mount">
            <p class="admin-intro" id="label-loading-msg">Loading queue…</p>
        </div>

        <div class="label-hidden-csrf" aria-hidden="true"><?= $csrfField ?></div>
        <script type="application/json" id="label-queue-data"><?= $queueJson ?></script>

        <script>
        (function() {
            var mount = document.getElementById('label-app-mount');
            var dataEl = document.getElementById('label-queue-data');
            var csrfWrap = document.querySelector('.label-hidden-csrf');
            var saveUrl = <?= json_encode($bp . '?action=label_save', JSON_UNESCAPED_SLASHES) ?>;
            var progressEl = document.getElementById('label-progress');
            var sessionCountEl = document.getElementById('label-session-count');
            var totalCountEl = document.getElementById('label-total-count');

            function updateLabelProgress(sessionCount, total) {
                if (sessionCountEl && typeof sessionCount === 'number') {
                    sessionCountEl.textContent = String(sessionCount);
                }
                if (totalCountEl && typeof total === 'number') {
                    totalCountEl.textContent = String(total);
                }
                if (!progressEl) return;
                progressEl.classList.remove('label-progress--bump');
                void progressEl.offsetWidth;
                progressEl.classList.add('label-progress--bump');
                window.setTimeout(function() {
                    progressEl.classList.remove('label-progress--bump');
                }, 500);
            }

            function getCsrfToken() {
                var input = csrfWrap ? csrfWrap.querySelector('input[name="_csrf"]') : null;
                return input ? input.value : '';
            }
            function setCsrfToken(t) {
                var input = csrfWrap ? csrfWrap.querySelector('input[name="_csrf"]') : null;
                if (input) input.value = t;
            }

            var entries;
            try { entries = JSON.parse(dataEl.textContent || '[]'); } catch (e) { entries = []; }

            var nextPageUrl = <?= json_encode($pageUrl($filter, $nextOffset), JSON_UNESCAPED_SLASHES) ?>;

            if (!entries.length) {
                mount.innerHTML =
                    '<div class="empty-state">' +
                        '<p><strong>All caught up</strong> — nothing unlabeled in this slice. ' +
                            'Try another filter, <a href="' + nextPageUrl + '">fetch older items</a>, ' +
                            'or check back after new items arrive.</p>' +
                    '</div>';
                return;
            }

            mount.innerHTML = '';
            var area = document.createElement('div');
            area.className = 'label-card-area';

            function safeHref(u) {
                if (!u || typeof u !== 'string') return '#';
                try { return new URL(u).href; } catch (e) { return '#'; }
            }
            function escAttr(u) {
                return String(u).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
            }

            function showBatch() {
                area.innerHTML = '';
                var batch = entries.splice(0, 20);
                if (!batch.length) {
                    mount.innerHTML =
                        '<div class="empty-state">' +
                            '<p><strong>Done with this batch.</strong> ' +
                                '<a href="' + nextPageUrl + '">Fetch older items</a>' +
                            '</p>' +
                        '</div>';
                    return;
                }
                batch.forEach(function(entry) {
                    var desc = entry.description || '';
                    if (desc.length > 220) desc = desc.substring(0, 220) + '…';
                    var pub = entry.published_date ? String(entry.published_date).substring(0, 10) : '';
                    var card = document.createElement('div');
                    card.className = 'label-entry-card';
                    card.dataset.entryType = entry.entry_type;
                    card.dataset.entryId = String(entry.entry_id);
                    var titleHtml = entry.link
                        ? '<a href="' + escAttr(safeHref(entry.link)) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(entry.title || '') + '</a>'
                        : escapeHtml(entry.title || '');
                    card.innerHTML =
                        '<div class="label-entry-meta">' +
                            '<span class="label-source-tag">' + escapeHtml(entry.source_type || 'unknown') + '</span>' +
                            (entry.source_category ? '<span>' + escapeHtml(entry.source_category) + '</span>' : '') +
                            (pub ? '<span style="font-style:italic;color:#555">' + escapeHtml(pub) + '</span>' : '') +
                        '</div>' +
                        '<div class="label-entry-title">' + titleHtml + '</div>' +
                        (desc ? '<div class="label-entry-desc">' + escapeHtml(desc) + '</div>' : '') +
                        (entry.source_name ? '<div class="label-entry-source">' + escapeHtml(entry.source_name) + '</div>' : '') +
                        '<input type="text" class="label-reasoning" placeholder="Why this label? (optional)" autocomplete="off">' +
                        '<div class="label-btn-grid">' +
                            '<button type="button" class="label-btn label-btn--inv" data-label="investigation_lead">Investigation</button>' +
                            '<button type="button" class="label-btn label-btn--imp" data-label="important">Important</button>' +
                            '<button type="button" class="label-btn label-btn--bg" data-label="background">Background</button>' +
                            '<button type="button" class="label-btn label-btn--noise" data-label="noise">Noise</button>' +
                        '</div>';
                    area.appendChild(card);
                });
                mount.appendChild(area);
            }

            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, function(c) {
                    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
                });
            }

            area.addEventListener('click', function(ev) {
                var btn = ev.target.closest('.label-btn');
                if (!btn) return;
                var card = btn.closest('.label-entry-card');
                if (!card || card.classList.contains('labeled')) return;
                var entryType = card.dataset.entryType;
                var entryId = parseInt(card.dataset.entryId, 10);
                var label = btn.getAttribute('data-label');
                var reasoningEl = card.querySelector('.label-reasoning');
                var reasoning = reasoningEl ? reasoningEl.value.trim() : '';

                card.classList.add('labeled');
                btn.classList.add('active');

                var fd = new FormData();
                fd.append('_csrf', getCsrfToken());
                fd.append('entry_type', entryType);
                fd.append('entry_id', String(entryId));
                fd.append('label', label);
                fd.append('reasoning', reasoning);

                fetch(saveUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, j: j }; }); })
                    .then(function(pack) {
                        if (!pack.ok || !pack.j || !pack.j.ok) {
                            card.classList.remove('labeled');
                            btn.classList.remove('active');
                            var msg = (pack.j && pack.j.error) ? pack.j.error : 'Save failed';
                            alert(msg);
                            return;
                        }
                        if (pack.j.csrf) setCsrfToken(pack.j.csrf);
                        if (typeof pack.j.session_count === 'number' || typeof pack.j.total === 'number') {
                            updateLabelProgress(
                                typeof pack.j.session_count === 'number' ? pack.j.session_count : null,
                                typeof pack.j.total === 'number' ? pack.j.total : null
                            );
                        }
                        var next = card.nextElementSibling;
                        if (next && !next.classList.contains('labeled')) {
                            next.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                        var left = area.querySelectorAll('.label-entry-card:not(.labeled)');
                        if (!left.length) setTimeout(function() { showBatch(); }, 400);
                    })
                    .catch(function() {
                        card.classList.remove('labeled');
                        btn.classList.remove('active');
                        alert('Network error while saving.');
                    });
            });

            showBatch();
        })();
        </script>
    </div>
</body>
</html>
