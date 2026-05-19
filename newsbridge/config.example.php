<?php

/**
 * Copy to `config.local.php` in this directory (gitignored).
 * Same shape as historical gaia/v0.4 staging newsbridge.
 */
return [
    'newsapi_key'   => 'YOUR_NEWSAPI_ORG_KEY',
    /** Plesk “URL cron” must append ?token=... — use a long random string */
    'cron_token'     => 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET',
    /**
     * Public base URL of this newsbridge folder (no trailing slash), e.g.
     * https://www.example.org/seismo/newsbridge
     * Used for RSS &lt;link&gt; and atom:self.
     */
    'site_base_url'  => 'https://www.example.org/seismo/newsbridge',
    'newsapi_base'   => 'https://newsapi.org/v2',
    'http_timeout'   => 25,
    'page_size'      => 100,
    'retention_days' => 14,
    'rss_max_items'  => 200,
];
