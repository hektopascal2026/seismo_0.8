<?php
/**
 * Mothership — source add audit log.
 *
 * @var string $csrfField
 * @var string $basePath
 * @var ?string $pageError
 * @var list<array{id:int|string, occurred_at:string, kind:string, ref_id:int, label_snapshot:string}> $entries
 * @var string $headerTitle
 * @var string $headerSubtitle
 * @var string $activeNav
 */

declare(strict_types=1);

$accent = seismoBrandAccent();
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

        <p class="admin-intro">
            Chronological record of new <strong>RSS</strong>, <strong>Substack</strong>, and <strong>Parl. press</strong> feeds,
            <strong>scraper</strong> sources, and <strong>mail</strong> subscriptions. Renames elsewhere do not change past lines.
        </p>

        <?php if ($pageError !== null): ?>
            <div class="message message-error"><?= e($pageError) ?></div>
        <?php elseif ($entries === []): ?>
            <div class="empty-state"><p>No entries yet. Add a source under Feeds, Scraper, or Mail.</p></div>
        <?php else: ?>
            <ul class="logbook-list">
                <?php foreach ($entries as $row): ?>
                    <?php
                    $ts   = strtotime((string)($row['occurred_at'] ?? ''));
                    $when = $ts > 0 ? date('d.m.Y H:i', $ts) : (string)($row['occurred_at'] ?? '');
                    $kind = (string)($row['kind'] ?? '');
                    $snap = (string)($row['label_snapshot'] ?? '');
                    $pfx  = \Seismo\Controller\LogbookController::kindPrefix($kind);
                    ?>
                    <li class="logbook-line">
                        <span class="logbook-when"><?= e($when) ?></span>:
                        <?= e($pfx) ?> &quot;<?= e($snap) ?>&quot; added.
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</body>
</html>
