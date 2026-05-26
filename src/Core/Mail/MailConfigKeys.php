<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * {@see SystemConfigRepository} keys for mail ingest (Slice 11 — Gmail-first).
 */
final class MailConfigKeys
{
    public const TRANSPORT_GMAIL_API   = 'gmail_api';
    public const TRANSPORT_IMAP_LEGACY = 'imap_legacy';

    public const TRANSPORT              = 'mail_transport';
    public const GOOGLE_CLIENT_ID       = 'mail_google_client_id';
    public const GOOGLE_CLIENT_SECRET   = 'mail_google_client_secret';
    public const GOOGLE_REFRESH_TOKEN   = 'mail_google_refresh_token';
    public const GOOGLE_EMAIL           = 'mail_google_email';
    public const GMAIL_HISTORY_ID       = 'mail_gmail_history_id';
    public const GMAIL_LAST_SYNC_AT     = 'mail_gmail_last_sync_at';
    public const GMAIL_CATCHUP_DAYS     = 'mail_gmail_catchup_days';
    public const MAX_MESSAGES           = 'mail_max_messages';

    /** When `1`, strip listing boilerplate for all mail (Settings → Mail). */
    public const STRIP_LISTING_BOILERPLATE = 'mail:strip_listing_boilerplate';

    /** @return list<string> */
    public static function gmailSettingsKeys(): array
    {
        return [
            self::TRANSPORT,
            self::GOOGLE_CLIENT_ID,
            self::GOOGLE_CLIENT_SECRET,
            self::GOOGLE_REFRESH_TOKEN,
            self::GOOGLE_EMAIL,
            self::GMAIL_HISTORY_ID,
            self::GMAIL_LAST_SYNC_AT,
            self::GMAIL_CATCHUP_DAYS,
            self::MAX_MESSAGES,
        ];
    }
}
