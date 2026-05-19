<?php
/**
 * @var string $basePath
 * @var string $csrfField
 * @var string|null $accent
 * @var string $headerTitle
 * @var string|null $headerSubtitle
 * @var string $activeNav
 * @var ?string $formError
 * @var ?string $writeNote
 * @var ?string $copyPasteBody
 * @var ?bool $dbTestOk
 * @var ?string $dbTestMessage
 * @var array<string, string> $old
 */

declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration — <?= e($headerTitle) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
    <?php if (!empty($accent)): ?>
    <style>:root { --seismo-accent: <?= e((string)$accent) ?>; }</style>
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

        <div class="setup-content">
            <h1>Configuration helper</h1>
            <p class="admin-intro">
                Tests your database credentials and builds a starter <code>config.local.php</code>.
                If PHP cannot write to the install directory — common on shared hosting —
                you will get a <strong>copy-and-paste</strong> block instead; save it via File Manager or SFTP.
                Never use <code>chmod 0777</code> and never write secrets to <code>/tmp</code>.
            </p>
            <p class="admin-intro">
                After <code>config.local.php</code> is in place and the app loads, set the <strong>web migration key</strong>
                and optional <strong>session admin password</strong> under <strong>Settings → General</strong>.
            </p>

            <?php if ($formError !== null && $formError !== ''): ?>
                <div class="message message-error"><?= e($formError) ?></div>
            <?php endif; ?>

            <?php if ($dbTestMessage !== null && $dbTestMessage !== ''): ?>
                <div class="message <?= $dbTestOk ? 'message-success' : 'message-error' ?>"><?= e($dbTestMessage) ?></div>
            <?php endif; ?>

            <?php if ($writeNote !== null && $writeNote !== ''): ?>
                <div class="message message-info"><?= e($writeNote) ?></div>
            <?php endif; ?>

            <?php if ($copyPasteBody !== null && $copyPasteBody !== ''): ?>
                <h2>Save as <code>config.local.php</code></h2>
                <p>Paste into your Seismo install root next to <code>index.php</code>, then verify with
                    <a href="<?= e($basePath) ?>/index.php?action=health">Health</a>.</p>
                <?php
                $setupBlockJson = json_encode($copyPasteBody, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
                if ($setupBlockJson === false) {
                    $setupBlockJson = '""';
                }
                ?>
                <textarea id="seismo-setup-block" readonly rows="18" class="search-input setup-code-preview" style="width:100%; max-width:40rem;"></textarea>
                <p class="admin-form-actions">
                    <button type="button" class="btn btn-secondary" id="seismo-setup-copy">Copy to clipboard</button>
                </p>
                <script>
                (function() {
                    var raw = <?= $setupBlockJson ?>;
                    var ta = document.getElementById('seismo-setup-block');
                    var btn = document.getElementById('seismo-setup-copy');
                    if (ta && typeof raw === 'string') { ta.value = raw; }
                    if (!btn || !ta) return;
                    btn.addEventListener('click', function() {
                        var text = ta.value || '';
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(text).then(function() {
                                btn.textContent = 'Copied';
                                setTimeout(function() { btn.textContent = 'Copy to clipboard'; }, 2000);
                            });
                            return;
                        }
                        ta.select();
                        try { document.execCommand('copy'); btn.textContent = 'Copied'; } catch (e) {}
                        setTimeout(function() { btn.textContent = 'Copy to clipboard'; }, 2000);
                    });
                })();
                </script>
            <?php endif; ?>

            <h2>Database</h2>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=configuration" class="admin-form-card">
                <?= $csrfField ?>
                <div class="admin-form-field">
                    <label>Host<br><input type="text" name="db_host" value="<?= e($old['db_host']) ?>" class="search-input" style="width:100%; max-width:28rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Port (optional)<br><input type="text" name="db_port" value="<?= e($old['db_port']) ?>" class="search-input" style="width:100%; max-width:8rem;" placeholder="3306"></label>
                </div>
                <div class="admin-form-field">
                    <label>Database name<br><input type="text" name="db_name" value="<?= e($old['db_name']) ?>" required class="search-input" style="width:100%; max-width:28rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>User<br><input type="text" name="db_user" value="<?= e($old['db_user']) ?>" required autocomplete="username" class="search-input" style="width:100%; max-width:28rem;"></label>
                </div>
                <div class="admin-form-field">
                    <label>Password<br><input type="password" name="db_pass" value="<?= e($old['db_pass']) ?>" autocomplete="current-password" class="search-input" style="width:100%; max-width:28rem;"></label>
                </div>

                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success">Test connection and write config</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
