<?php
/**
 * Shared top bar + navigation drawer (Slice 6).
 *
 * @var string $basePath
 * @var string $headerTitle
 * @var string|null $headerSubtitle
 * @var string $activeNav index|filter|about|magnitu|label|feeds|scraper|mail|lex|leg|settings|configuration|styleguide|logbook
 * @var string $csrfField
 * @var bool $showModuleRefresh Optional — Feeds / Scraper / Mail top-bar module refresh (mothership).
 * @var string|null $moduleRefreshAction e.g. refresh_feed_sources
 * @var string|null $moduleRefreshLabel e.g. Refresh Feeds
 * @var string|null $moduleRefreshReturnView items|sources|subscriptions — POST back after refresh
 */

declare(strict_types=1);

use Seismo\Http\AuthGate;

if (!function_exists('seismo_ui_nav_leading_throttle_ms')) {
    require_once __DIR__ . '/../helpers.php';
}
$seismoNavLeadThrottleMs = seismo_ui_nav_leading_throttle_ms();

$activeNav = $activeNav ?? 'index';
$filterNavQs = $filterNavQs ?? 'action=filter';
?>
        <div class="top-bar">
            <div class="top-bar-left">
                <button type="button" id="seismo-nav-toggle" class="top-bar-btn nav-menu-toggle" aria-expanded="false" aria-controls="seismo-nav-drawer" title="Menu">☰</button>
                <span class="top-bar-title">
                    <a href="<?= e($basePath) ?>/index.php?action=index">
                        <img src="<?= e($basePath) ?>/assets/img/logo.png" alt="" class="logo-icon logo-icon-large" width="38" height="38" decoding="async">
                    </a>
                    <?php
                    $brandFull = seismoBrandTitle();
                    if (($headerTitle ?? '') === $brandFull) {
                        if (isSatellite()) {
                            if (seismoSatelliteBrandSplit()) {
                                echo '<strong class="top-bar-brand-name top-bar-brand-prefix">' . e('Seismo') . '</strong>'
                                    . '<strong class="top-bar-brand-name top-bar-brand-suffix"> ' . e(seismoBrandSuffix()) . '</strong>';
                            } else {
                                echo '<strong class="top-bar-brand-name">' . e(seismoBrandSuffix()) . '</strong>';
                            }
                        } else {
                            echo '<strong class="top-bar-brand-name">' . e(seismoBrandBase()) . '</strong>';
                            $ver = seismoBrandVersionLabel();
                            if ($ver !== '') {
                                echo ' <span class="top-bar-brand-version">' . e($ver) . '</span>';
                            }
                        }
                    } else {
                        echo '<strong class="top-bar-page-title">' . e((string)$headerTitle) . '</strong>';
                    }
                    ?>
                </span>
                <?php if (($headerSubtitle ?? '') !== ''): ?>
                <span class="top-bar-subtitle"><?= e((string)$headerSubtitle) ?></span>
                <?php endif; ?>
            </div>
            <div class="top-bar-actions">
                <?php
                    $timelineRefreshAct = $timelineRefreshAction ?? 'refresh_all';
                    $timelineRefreshRet = $timelineRefreshReturnAction ?? 'index';
                    ?>
                <?php if (!empty($showTimelineRefresh) && ($activeNav === 'index' || $activeNav === 'filter')): ?>
                    <form id="seismo-timeline-refresh-form" method="post" action="<?= e($basePath) ?>/index.php?action=<?= e($timelineRefreshAct) ?>" class="admin-inline-form top-bar-form-gap">
                        <?= $csrfField ?>
                        <input type="hidden" name="return_action" value="<?= e($timelineRefreshRet) ?>">
                        <button type="submit" class="top-bar-btn top-bar-btn--text top-bar-btn--timeline-refresh" data-refresh-label="Refresh" title="<?= isSatellite() ? 'Triggers mothership refresh (feeds, press, scrapers, mail, Leg — Lex omitted, same as mothership toolbar)' : 'Refresh feeds, press, scrapers, mail, and parliament calendar. Lex legislation uses Diagnostics or cron.' ?>">Refresh</button>
                    </form>
                <?php endif; ?>
                <?php
                $moduleAct = $moduleRefreshAction ?? '';
                $moduleLab = $moduleRefreshLabel ?? '';
                $moduleRv  = (string)($moduleRefreshReturnView ?? '');
                ?>
                <?php if (($showModuleRefresh ?? false) && $moduleAct !== '' && $moduleLab !== ''): ?>
                    <form method="post" action="<?= e($basePath) ?>/index.php?action=<?= e($moduleAct) ?>" class="admin-inline-form top-bar-form-gap">
                        <?= $csrfField ?>
                        <input type="hidden" name="return_view" value="<?= e($moduleRv) ?>">
                        <button type="submit" class="top-bar-btn top-bar-btn--text"><?= e($moduleLab) ?></button>
                    </form>
                <?php endif; ?>
                <?php if (AuthGate::isEnabled() && AuthGate::isLoggedIn()): ?>
                    <form method="post" action="<?= e($basePath) ?>/index.php?action=logout" class="admin-inline-form top-bar-form-flush">
                        <?= $csrfField ?>
                        <button type="submit" class="top-bar-btn top-bar-btn--text" title="Sign out">Logout</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($showTimelineRefresh) && ($activeNav === 'index' || $activeNav === 'filter')): ?>
        <script>
        (function() {
            var form = document.getElementById('seismo-timeline-refresh-form');
            if (!form) return;
            var button = form.querySelector('button[type=submit]');
            if (!button) return;
            var defaultLabel = (button.getAttribute('data-refresh-label') || 'Refresh');
            function setButtonLoading() {
                button.disabled = true;
                button.classList.add('is-refreshing');
                button.setAttribute('aria-busy', 'true');
                button.innerHTML = 'Refreshing<span class="loading-dots" aria-hidden="true">'
                    + '<span class="loading-dots-char">.</span><span class="loading-dots-char">.</span>'
                    + '<span class="loading-dots-char">.</span></span>';
            }
            function setButtonDefault() {
                button.disabled = false;
                button.classList.remove('is-refreshing');
                button.removeAttribute('aria-busy');
                button.textContent = defaultLabel;
            }
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                setButtonLoading();
                var fd = new FormData(form);
                fd.set('ajax', '1');
                fetch(form.getAttribute('action') || form.action, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json, text/plain, */*' }
                })
                    .then(function(r) { return r.text().then(function(t) { return { r: r, t: t }; }); })
                    .then(function(v) {
                        var data;
                        try {
                            data = v.t ? JSON.parse(v.t) : null;
                        } catch (e2) {
                            throw new Error('Server did not return JSON. If this persists, check Diagnostics or server logs (HTTP ' + v.r.status + ').');
                        }
                        if (!data) {
                            throw new Error('Empty response (HTTP ' + v.r.status + ').');
                        }
                        if (data.ok === true) {
                            window.location.reload();
                            return;
                        }
                        var err = (typeof data.error === 'string' && data.error !== '') ? data.error : 'Refresh could not be completed.';
                        throw new Error(err);
                    })
                    .catch(function(err) {
                        setButtonDefault();
                        var msg = (err && err.message) ? err.message : 'Refresh request failed.';
                        window.alert(msg);
                    });
            });
        })();
        </script>
        <?php endif; ?>

        <nav id="seismo-nav-drawer" class="nav-drawer" aria-label="Main navigation" aria-hidden="true">
            <a href="<?= e($basePath) ?>/index.php?action=index" class="nav-link<?= $activeNav === 'index' ? ' active' : '' ?>">Timeline</a>
            <?php if (!isSatellite()): ?>
            <a href="<?= e($basePath) ?>/index.php?<?= e($filterNavQs) ?>" class="nav-link<?= $activeNav === 'filter' ? ' active' : '' ?>">Filter</a>
            <?php endif; ?>
            <a href="<?= e($basePath) ?>/index.php?action=magnitu" class="nav-link<?= $activeNav === 'magnitu' ? ' active' : '' ?>">Highlights</a>
            <a href="<?= e($basePath) ?>/index.php?action=label" class="nav-link<?= $activeNav === 'label' ? ' active' : '' ?>">Label</a>
            <?php if (!isSatellite()): ?>
            <a href="<?= e($basePath) ?>/index.php?action=feeds" class="nav-link<?= $activeNav === 'feeds' ? ' active' : '' ?>">Feeds</a>
            <a href="<?= e($basePath) ?>/index.php?action=scraper" class="nav-link<?= $activeNav === 'scraper' ? ' active' : '' ?>">Scraper</a>
            <a href="<?= e($basePath) ?>/index.php?action=mail" class="nav-link<?= $activeNav === 'mail' ? ' active' : '' ?>">Mail</a>
            <a href="<?= e($basePath) ?>/index.php?action=lex" class="nav-link<?= $activeNav === 'lex' ? ' active' : '' ?>">Lex</a>
            <a href="<?= e($basePath) ?>/index.php?action=leg" class="nav-link<?= $activeNav === 'leg' ? ' active' : '' ?>">Leg</a>
            <a href="<?= e($basePath) ?>/index.php?action=styleguide" class="nav-link<?= $activeNav === 'styleguide' ? ' active' : '' ?>">Styleguide</a>
            <a href="<?= e($basePath) ?>/index.php?action=logbook" class="nav-link<?= $activeNav === 'logbook' ? ' active' : '' ?>">Logbook</a>
            <?php endif; ?>
            <a href="<?= e($basePath) ?>/index.php?action=settings" class="nav-link<?= $activeNav === 'settings' ? ' active' : '' ?>">Settings</a>
            <?php if (!isSatellite()): ?>
            <a href="<?= e($basePath) ?>/index.php?action=configuration" class="nav-link<?= $activeNav === 'configuration' ? ' active' : '' ?>">Configuration</a>
            <a href="<?= e($basePath) ?>/index.php?action=about" class="nav-link<?= $activeNav === 'about' ? ' active' : '' ?>">About</a>
            <?php endif; ?>
        </nav>
        <script>
        (function() {
            var btn = document.getElementById('seismo-nav-toggle');
            var nav = document.getElementById('seismo-nav-drawer');
            if (!btn || !nav) return;
            btn.addEventListener('click', function() {
                var open = nav.classList.toggle('open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                nav.setAttribute('aria-hidden', open ? 'false' : 'true');
            });
        })();
        </script>
        <?php if ($seismoNavLeadThrottleMs > 0): ?>
        <script>
        (function() {
            var lockUntil = 0;
            var ms = <?= (int) $seismoNavLeadThrottleMs ?>;
            document.addEventListener('click', function(e) {
                if (e.defaultPrevented) {
                    return;
                }
                if (e.button !== 0) {
                    return;
                }
                if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
                    return;
                }
                var t = e.target;
                if (!t || !t.closest) {
                    return;
                }
                var a = t.closest('a[href]');
                if (!a) {
                    return;
                }
                if (!a.matches('#seismo-nav-drawer a[href], .settings-tabs a[href]')) {
                    return;
                }
                var now = Date.now();
                if (now < lockUntil) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    return;
                }
                lockUntil = now + ms;
            }, true);
        })();
        </script>
        <?php endif; ?>
