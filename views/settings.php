<?php
/**
 * Settings — tabs: General, Magnitu, Mail (mothership), Retention, Satellites, Diagnostics.
 *
 * @var string $tab 'general'|'magnitu'|'mail'|'retention'|'satellite'|'diagnostics'
 * @var string $csrfField
 * @var string $basePath
 * @var int $dashboardLimitSaved
 * @var ?string $pageError
 * @var array<string, mixed> $rows
 * @var array<string, mixed> $defaults
 * @var list<string> $families
 * @var bool $satellite
 * @var array<string, string|null> $magnituConfig
 * @var array{total:int, magnitu:int, recipe:int} $magnituScoreStats
 * @var int $magnituTrainingLabelCount rows in magnitu_labels (Magnitu training data on this instance)
 * @var string $seismoApiUrl
 * @var list<array<string, mixed>> $satellitesRegistry
 * @var string $satellitesMothershipUrl
 * @var string $satellitesMothershipDb
 * @var bool $satellitesRemoteRefreshKeyConfigured
 * @var string $satellitesSuggestedRefreshKey
 * @var string $satellitesHighlightSlug
 * @var array<string, string|null> $mailConfig
 * @var bool $mailPasswordOnFile
 * @var array<string, array<string, mixed>> $diagStatus
 * @var array<string, array<string, mixed>> $diagCoreStatus
 * @var ?string $diagLoadError
 * @var ?array{id: string, count: int, error: ?string, items: list<array<string, mixed>>} $diagTestResult
 * @var array<string, list<array{run_at: \DateTimeImmutable, status: string, item_count: int, error_message: ?string, duration_ms: int}>> $diagRunHistory
 * @var list<array<string, mixed>> $diagSourceHealthFeeds
 * @var list<array<string, mixed>> $diagSourceHealthMail
 * @var int $diagSourceHealthStaleDays
 * @var ?string $diagSourceHealthError
 * @var bool $migrateKeyConfigured
 * @var bool $configLocalWritable
 * @var ?string $pendingMigrateKey
 * @var ?string $migrateKeyPasteBlock
 * @var ?string $adminPasswordPasteBlock
 * @var bool $sessionAuthEnabled
 * @var bool $navLeadingThrottleOn Settings → General: main nav / settings tab leading-edge click throttle
 */

declare(strict_types=1);

if (!function_exists('e')) {
    require_once __DIR__ . '/helpers.php';
}

$accent = seismoBrandAccent();
$headerTitle = 'Settings';
$headerSubtitle = $satellite
    ? 'General & Magnitu (satellite)'
    : 'Preferences, Magnitu, mail, retention, satellites & diagnostics';
$activeNav = 'settings';
$dashboardLimitMax = \Seismo\Repository\EntryRepository::MAX_LIMIT;

$tabQs = static function (string $t) use ($basePath): string {
    return $basePath . '/index.php?' . http_build_query(['action' => 'settings', 'tab' => $t]);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — <?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
    <?php if ($accent): ?>
    <style>:root { --seismo-accent: <?= e($accent) ?>; }</style>
    <?php endif; ?>
</head>
<body>
    <div class="container">
        <?php
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

        <nav class="settings-tabs" aria-label="Settings sections">
            <a href="<?= e($tabQs('general')) ?>" class="<?= $tab === 'general' ? 'active' : '' ?>">General</a>
            <a href="<?= e($tabQs('magnitu')) ?>" class="<?= $tab === 'magnitu' ? 'active' : '' ?>">Magnitu</a>
            <?php if (!$satellite): ?>
            <a href="<?= e($tabQs('mail')) ?>" class="<?= $tab === 'mail' ? 'active' : '' ?>">Mail</a>
            <a href="<?= e($tabQs('retention')) ?>" class="<?= $tab === 'retention' ? 'active' : '' ?>">Retention</a>
            <a href="<?= e($tabQs('satellite')) ?>" class="<?= $tab === 'satellite' ? 'active' : '' ?>">Satellites</a>
            <a href="<?= e($tabQs('diagnostics')) ?>" class="<?= $tab === 'diagnostics' ? 'active' : '' ?>">Diagnostics</a>
            <?php endif; ?>
        </nav>

        <?php if ($tab === 'general'): ?>
            <?php require __DIR__ . '/partials/settings_general.php'; ?>
        <?php elseif ($tab === 'magnitu'): ?>
            <?php if ($pageError !== null): ?>
                <div class="message message-error"><?= e($pageError) ?></div>
            <?php endif; ?>
            <?php require __DIR__ . '/partials/settings_magnitu.php'; ?>
        <?php elseif ($tab === 'mail'): ?>
            <?php if ($pageError !== null): ?>
                <div class="message message-error"><?= e($pageError) ?></div>
            <?php endif; ?>
            <?php require __DIR__ . '/partials/settings_mail.php'; ?>
        <?php elseif ($tab === 'satellite'): ?>
            <?php if ($pageError !== null): ?>
                <div class="message message-error"><?= e($pageError) ?></div>
            <?php endif; ?>
            <?php require __DIR__ . '/partials/settings_tab_satellites.php'; ?>
        <?php elseif ($tab === 'diagnostics'): ?>
            <?php if ($pageError !== null): ?>
                <div class="message message-error"><?= e($pageError) ?></div>
            <?php endif; ?>
            <?php require __DIR__ . '/partials/diagnostics_panel.php'; ?>
        <?php else: ?>
            <?php if ($pageError !== null): ?>
                <div class="message message-error"><?= e($pageError) ?></div>
            <?php endif; ?>
            <?php if ($satellite): ?>
                <p class="message message-info">Satellite mode — entry tables live on the mothership. Policies here are for reference; pruning runs on the mothership only.</p>
            <?php endif; ?>
            <?php require __DIR__ . '/partials/retention_panel.php'; ?>
        <?php endif; ?>
    </div>
</body>
</html>
