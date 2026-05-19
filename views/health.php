<?php
/** @var array<string, mixed> $data Provided by HealthController::show(). */
$basePath = isset($data['basePath']) ? (string)$data['basePath'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seismo health</title>
    <?php if ($basePath !== ''): ?>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
    <?php endif; ?>
</head>
<body>
    <div class="health-page">
    <h1>Seismo health</h1>

<?php if (!empty($data['degraded'])): ?>
    <dl class="health-dl">
        <dt>Status</dt>
        <dd class="<?= $data['dbStatus'] === 'ok' ? 'health-status-ok' : 'health-status-err' ?>"><?= e((string)$data['dbStatus']) ?></dd>
    </dl>
    <p class="health-note">Sign in to see full diagnostics.</p>
<?php else: ?>
    <dl class="health-dl">
        <dt>Seismo</dt>
        <dd><?= e((string)$data['seismoVersion']) ?></dd>

        <dt>PHP</dt>
        <dd><?= e((string)$data['phpVersion']) ?></dd>

        <dt>Database</dt>
        <dd class="<?= $data['dbStatus'] === 'ok' ? 'health-status-ok' : 'health-status-err' ?>">
            <?= e((string)$data['dbStatus']) ?>
            <?php if (!empty($data['dbVersion'])): ?>
                (MySQL <?= e((string)$data['dbVersion']) ?>)
            <?php endif; ?>
        </dd>

        <dt>Schema version</dt>
        <dd>
            <?php if ($data['schemaVersion'] === null): ?>
                <span class="health-status-err">not initialised</span> — run <span class="health-code">php migrate.php</span>
            <?php else: ?>
                <?= (int)$data['schemaVersion'] ?>
            <?php endif; ?>
        </dd>

        <dt>Mode</dt>
        <dd>
            <?php if ($data['satellite']): ?>
                satellite (reads from <span class="health-code"><?= e((string)$data['mothershipDb']) ?></span>)
            <?php else: ?>
                mothership
            <?php endif; ?>
        </dd>

        <dt>Brand title</dt>
        <dd><?= e((string)$data['brandTitle']) ?></dd>

        <dt>Base path</dt>
        <dd><span class="health-code"><?= e($data['basePath'] === '' ? '/' : (string)$data['basePath']) ?></span></dd>
    </dl>

    <p class="health-note">
        Default page for fresh 0.5 installs. Once the dashboard is ported,
        <span class="health-code">?action=index</span> becomes the default and this page stays
        available at <span class="health-code">?action=health</span> for uptime checks.
    </p>
<?php endif; ?>
    </div>
</body>
</html>
