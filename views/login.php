<?php
/**
 * Login form.
 *
 * @var string  $basePath
 * @var ?string $errorMessage Flash from AuthController::handleLogin().
 */

declare(strict_types=1);

use Seismo\Http\CsrfToken;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
    <?php if (function_exists('isSatellite') && isSatellite() && seismoBrandAccent()): ?>
    <style>:root { --seismo-accent: <?= e((string)seismoBrandAccent()) ?>; }</style>
    <?php endif; ?>
</head>
<body>
    <div class="container login-container">
        <div class="top-bar">
            <div class="top-bar-left">
                <span class="top-bar-title">
                    <img src="<?= e($basePath) ?>/assets/img/logo.png" alt="" class="logo-icon logo-icon-large" width="38" height="38" decoding="async">
                    <?php if (function_exists('isSatellite') && isSatellite()): ?>
                        <?php if (seismoSatelliteBrandSplit()): ?>
                            <strong class="top-bar-brand-name top-bar-brand-prefix"><?= e('Seismo') ?></strong><strong class="top-bar-brand-name top-bar-brand-suffix"> <?= e(seismoBrandSuffix()) ?></strong>
                        <?php else: ?>
                            <strong class="top-bar-brand-name"><?= e(seismoBrandSuffix()) ?></strong>
                        <?php endif; ?>
                    <?php else: ?>
                    <strong class="top-bar-brand-name"><?= e(seismoBrandBase()) ?></strong>
                    <span class="top-bar-brand-version"><?= e(seismoBrandVersionLabel()) ?></span>
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <?php if ($errorMessage !== null): ?>
            <div class="message message-error"><?= e($errorMessage) ?></div>
        <?php endif; ?>

        <div class="latest-entries-section login-form-card">
            <h2 class="section-title">Sign in</h2>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=login">
                <?= CsrfToken::field() ?>
                <div class="admin-form-field">
                    <label>Password<br>
                    <input type="password" name="password" autofocus autocomplete="current-password" class="search-input" style="width:100%;"></label>
                </div>
                <button type="submit" class="btn btn-success">Sign in</button>
            </form>
        </div>
    </div>
</body>
</html>
