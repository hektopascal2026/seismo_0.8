<?php
/**
 * Settings → Mail tab (Gmail API primary, legacy IMAP fallback).
 *
 * @var string $csrfField
 * @var string $basePath
 * @var array<string, string|null> $mailConfig
 * @var bool $mailPasswordOnFile
 * @var bool $mailGoogleSecretOnFile
 * @var bool $mailGmailConnected
 * @var string $mailOAuthRedirectUri
 * @var bool $mailStripListingBoilerplate
 */

declare(strict_types=1);

use Seismo\Core\Mail\MailConfigKeys;

$googleClientId = trim((string)($mailConfig[MailConfigKeys::GOOGLE_CLIENT_ID] ?? ''));
$gmailEmail     = trim((string)($mailConfig[MailConfigKeys::GOOGLE_EMAIL] ?? ''));
$lastSync       = trim((string)($mailConfig[MailConfigKeys::GMAIL_LAST_SYNC_AT] ?? ''));

$maxMsg = (int)($mailConfig[MailConfigKeys::MAX_MESSAGES] ?? 50);
if ($maxMsg < 1) {
    $maxMsg = 50;
}
$catchupDays = (int)($mailConfig[MailConfigKeys::GMAIL_CATCHUP_DAYS] ?? 7);
if ($catchupDays < 1) {
    $catchupDays = 7;
}

$mbVal   = trim((string)($mailConfig['mail_imap_mailbox'] ?? ''));
$hostVal = trim((string)($mailConfig['mail_imap_host'] ?? ''));
$portVal = trim((string)($mailConfig['mail_imap_port'] ?? ''));
$flagsVal = trim((string)($mailConfig['mail_imap_flags'] ?? ''));
$folderVal = trim((string)($mailConfig['mail_imap_folder'] ?? ''));
$advancedOpen = $mbVal === '' && ($hostVal !== '' || $portVal !== '' || $flagsVal !== '' || $folderVal !== '');

$criteriaVal = trim((string)($mailConfig['mail_search_criteria'] ?? ''));
if ($criteriaVal === '') {
    $criteriaVal = 'UNSEEN';
}
$markSeen = ($mailConfig['mail_mark_seen'] ?? '0') === '1' || ($mailConfig['mail_mark_seen'] ?? '') === 'true';

$imapExt = extension_loaded('imap');
$mailStripListingBoilerplate = !empty($mailStripListingBoilerplate);
?>
        <div class="latest-entries-section">
            <h2 class="section-title">Mail processing</h2>
            <p class="admin-intro">
                Applies to all subscriptions unless a sender already has its own
                <strong>Strip typical boilerplate</strong> option on the
                <a href="<?= e($basePath) ?>/index.php?action=mail">Mail</a> page.
            </p>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=settings_save_mail" class="admin-form-card">
                <?= $csrfField ?>
                <input type="hidden" name="mail_settings_form" value="processing">
                <div class="admin-form-field">
                    <label>
                        <input type="checkbox" name="mail_strip_listing_boilerplate" value="1"<?= $mailStripListingBoilerplate ? ' checked' : '' ?>>
                        Strip typical boilerplate for all mail
                    </label>
                    <div class="magnitu-field-hint">
                        Removes leading lines such as &ldquo;view in browser&rdquo;, image-display warnings,
                        repeated subject, and Admin.ch-style datelines from inbox bodies at ingest, on dashboard
                        cards, in recipe scoring, and in Magnitu export. Reprocess stored mail on a subscription
                        to refresh bodies already in the database.
                    </div>
                </div>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success">Save processing settings</button>
                </div>
            </form>
        </div>

        <div class="latest-entries-section">
            <h2 class="section-title">Gmail (recommended)</h2>
            <p class="admin-intro">
                Connect your Google inbox with OAuth. Seismo uses the Gmail API and a <strong>history cursor</strong>
                (not “unread only”), so opening mail in Gmail does not block ingest. Runs on
                <strong>Refresh all</strong> and <code>refresh_cron.php</code> (throttled to every 15 minutes on cron).
            </p>

            <?php if ($mailGmailConnected): ?>
                <p class="message message-success">
                    Connected<?= $gmailEmail !== '' ? ' as <strong>' . e($gmailEmail) . '</strong>' : '' ?>.
                    <?php if ($lastSync !== ''): ?>
                        Last sync (UTC): <?= e($lastSync) ?>.
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <p class="message message-warning">Gmail is not connected yet.</p>
            <?php endif; ?>

            <form method="post" action="<?= e($basePath) ?>/index.php?action=settings_save_mail" class="admin-form-card">
                <?= $csrfField ?>
                <input type="hidden" name="mail_settings_form" value="gmail">

                <div class="admin-form-field">
                    <label for="mail_google_client_id">Google OAuth Client ID</label>
                    <input type="text" id="mail_google_client_id" name="mail_google_client_id" class="search-input" style="width:100%;"
                           value="<?= e($googleClientId) ?>" autocomplete="off"
                           placeholder="….apps.googleusercontent.com">
                </div>
                <div class="admin-form-field">
                    <label for="mail_google_client_secret">Google OAuth Client secret</label>
                    <input type="password" id="mail_google_client_secret" name="mail_google_client_secret" class="search-input" style="width:100%;"
                           value="" placeholder="Leave blank to keep current secret" autocomplete="new-password">
                    <?php if ($mailGoogleSecretOnFile): ?>
                        <div class="magnitu-field-hint">A client secret is already stored.</div>
                    <?php endif; ?>
                </div>
                <div class="admin-form-field">
                    <label>OAuth redirect URI (paste into Google Cloud console)</label>
                    <input type="text" class="search-input" style="width:100%;" readonly value="<?= e($mailOAuthRedirectUri) ?>">
                </div>
                <div class="admin-form-field">
                    <label for="mail_max_messages">Max messages per run</label>
                    <input type="number" id="mail_max_messages" name="mail_max_messages" class="search-input" style="width:7rem;"
                           value="<?= (int)$maxMsg ?>" min="1" max="500">
                    <div class="magnitu-field-hint">Default 50. Cron runs at most every 15 minutes; raising this increases Gmail API calls per tick.</div>
                </div>
                <div class="admin-form-field">
                    <label for="mail_gmail_catchup_days">Catch-up window (days, first sync / recovery)</label>
                    <input type="number" id="mail_gmail_catchup_days" name="mail_gmail_catchup_days" class="search-input" style="width:7rem;"
                           value="<?= (int)$catchupDays ?>" min="1" max="30">
                </div>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success">Save Gmail settings</button>
                </div>
            </form>

            <div class="admin-form-actions" style="margin-top:1rem;">
                <?php if ($mailGmailConnected): ?>
                    <form method="post" action="<?= e($basePath) ?>/index.php?action=mail_gmail_catchup" style="display:inline;">
                        <?= $csrfField ?>
                        <button type="submit" class="btn btn-secondary">Catch up inbox</button>
                    </form>
                    <form method="post" action="<?= e($basePath) ?>/index.php?action=mail_google_reconnect" style="display:inline;">
                        <?= $csrfField ?>
                        <button type="submit" class="btn btn-secondary">Reconnect Google</button>
                    </form>
                    <form method="post" action="<?= e($basePath) ?>/index.php?action=mail_google_disconnect" style="display:inline;">
                        <?= $csrfField ?>
                        <button type="submit" class="btn btn-secondary">Disconnect Google</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?= e($basePath) ?>/index.php?action=mail_google_oauth_start" style="display:inline;">
                        <?= $csrfField ?>
                        <button type="submit" class="btn btn-success">Connect Google account</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="latest-entries-section">
            <h2 class="section-title">Legacy IMAP (non-Google providers)</h2>
            <p class="admin-intro">
                Optional fallback for Fastmail, Outlook, etc. Not recommended for Gmail — use OAuth above.
                Subscriptions and rules: <a href="<?= e($basePath) ?>/index.php?action=mail">Mail</a> page.
            </p>
            <?php if (!$imapExt): ?>
                <p class="message message-warning">
                    PHP <code>imap</code> extension is not enabled — legacy IMAP cannot run until <code>ext-imap</code> is installed.
                </p>
            <?php endif; ?>

            <form method="post" action="<?= e($basePath) ?>/index.php?action=settings_save_mail" class="admin-form-card">
                <?= $csrfField ?>
                <input type="hidden" name="mail_settings_form" value="imap_legacy">

                <div class="admin-form-field">
                    <label for="mail_imap_mailbox_legacy">Mailbox (IMAP string)</label>
                    <input type="text" id="mail_imap_mailbox_legacy" name="mail_imap_mailbox" class="search-input" style="width:100%;"
                           value="<?= e($mbVal) ?>" placeholder="{imap.example.com:993/imap/ssl}INBOX" autocomplete="off">
                </div>

                <details class="settings-mail-advanced"<?= $advancedOpen ? ' open' : '' ?>>
                    <summary>Compose from host, port, and folder</summary>
                    <div class="admin-form-field">
                        <label for="mail_imap_host_legacy">Host</label>
                        <input type="text" id="mail_imap_host_legacy" name="mail_imap_host" class="search-input" style="width:100%;"
                               value="<?= e($hostVal) ?>" autocomplete="off">
                    </div>
                    <div class="admin-form-field">
                        <label for="mail_imap_port_legacy">Port</label>
                        <input type="number" id="mail_imap_port_legacy" name="mail_imap_port" class="search-input" style="width:7rem;"
                               value="<?= e($portVal) ?>" min="1" max="65535">
                    </div>
                    <div class="admin-form-field">
                        <label for="mail_imap_flags_legacy">Flags</label>
                        <input type="text" id="mail_imap_flags_legacy" name="mail_imap_flags" class="search-input" style="width:100%;"
                               value="<?= e($flagsVal) ?>" placeholder="/imap/ssl" autocomplete="off">
                    </div>
                    <div class="admin-form-field">
                        <label for="mail_imap_folder_legacy">Folder</label>
                        <input type="text" id="mail_imap_folder_legacy" name="mail_imap_folder" class="search-input" style="width:100%;"
                               value="<?= e($folderVal) ?>" placeholder="INBOX" autocomplete="off">
                    </div>
                </details>

                <div class="admin-form-field">
                    <label for="mail_imap_username_legacy">Username</label>
                    <input type="text" id="mail_imap_username_legacy" name="mail_imap_username" class="search-input" style="width:100%;"
                           value="<?= e(trim((string)($mailConfig['mail_imap_username'] ?? ''))) ?>" autocomplete="username">
                </div>
                <div class="admin-form-field">
                    <label for="mail_imap_password_legacy">Password</label>
                    <input type="password" id="mail_imap_password_legacy" name="mail_imap_password" class="search-input" style="width:100%;"
                           value="" placeholder="Leave blank to keep current password" autocomplete="new-password">
                    <?php if ($mailPasswordOnFile): ?>
                        <div class="magnitu-field-hint">A password is already stored.</div>
                    <?php endif; ?>
                </div>
                <div class="admin-form-field">
                    <label for="mail_search_criteria_legacy">Search criteria (legacy)</label>
                    <input type="text" id="mail_search_criteria_legacy" name="mail_search_criteria" class="search-input" style="width:100%;"
                           value="<?= e($criteriaVal) ?>" autocomplete="off">
                    <div class="magnitu-field-hint">IMAP only. Prefer Gmail OAuth instead of <code>UNSEEN</code>.</div>
                </div>
                <div class="admin-form-field">
                    <label><input type="checkbox" name="mail_mark_seen" value="1"<?= $markSeen ? ' checked' : '' ?>> Mark fetched messages as read (\Seen)</label>
                </div>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-secondary">Save legacy IMAP</button>
                </div>
            </form>
        </div>
