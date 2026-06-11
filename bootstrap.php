<?php
/**
 * Seismo bootstrap.
 *
 * Responsibilities (kept intentionally small):
 *   1. Load local credentials from config.local.php.
 *   2. Define SEISMO_* constants (satellite/brand knobs) with safe defaults.
 *   3. Register a minimal PSR-4 autoloader for Seismo\* classes under src/.
 *   4. Provide a handful of global helpers that every layer depends on:
 *      getDbConnection(), isConfigured(), hasDbConnection(), getBasePath(), isSatellite(), entryTable(),
 *      entryDbSchemaExpr(), seismoBrandBase(), seismoBrandSuffix(),
 *      seismoBrandVersionLabel(), seismoBrandTitle(), seismoBrandAccent(),
 *      seismoSatelliteBrandSplit(), seismoBrandDisplaySplit().
 *
 * Anything larger (DDL, scoring, feature config) lives in its own module or
 * migration file. See docs/consolidation-plan.md.
 */

declare(strict_types=1);

use Seismo\Util\TimelineEntryDatetime;

define('SEISMO_VERSION', '0.9.4');
define('SEISMO_ROOT', __DIR__);

// ---------------------------------------------------------------------------
// 0. Time is UTC everywhere.
// ---------------------------------------------------------------------------
// Shared hosting environments routinely disagree: PHP in Europe/Zurich, MySQL
// in UTC, or the other way around. Mixed time zones break the stateless
// ?since=<iso8601> export contract and the retention cutoff. We pin PHP to UTC
// here and the DB session to UTC in getDbConnection() below. Views/formatters
// convert to local time at render — never in the data layer.
date_default_timezone_set('UTC');

// ---------------------------------------------------------------------------
// 0b. Memory floor (VPS scraper, lex backfill, exports). Raises only when
//     php.ini / FPM is lower; never overrides a higher admin setting.
//     Timeline row cap stays EntryRepository::MAX_LIMIT (200).
// ---------------------------------------------------------------------------
if (!function_exists('seismoEnsureMinMemoryLimit')) {
    function seismoEnsureMinMemoryLimit(string $minimum = '512M'): void
    {
        if (!function_exists('ini_set')) {
            return;
        }
        $current = ini_get('memory_limit');
        if (!is_string($current) || $current === '' || $current === '-1') {
            return;
        }
        $toBytes = static function (string $val): int {
            $val = trim($val);
            if ($val === '') {
                return 0;
            }
            $last = strtolower($val[strlen($val) - 1]);
            $num  = (int)$val;
            return match ($last) {
                'g'     => $num * 1024 * 1024 * 1024,
                'm'     => $num * 1024 * 1024,
                'k'     => $num * 1024,
                default => (int)$val,
            };
        };
        if ($toBytes($current) < $toBytes($minimum)) {
            ini_set('memory_limit', $minimum);
        }
    }
}
seismoEnsureMinMemoryLimit();

// ---------------------------------------------------------------------------
// 1. Local credentials
// ---------------------------------------------------------------------------
$__seismoLocalConfig = __DIR__ . '/config.local.php';
$__webAction         = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '';
$__setupWithoutFile  = PHP_SAPI !== 'cli'
    && ($__webAction === 'configuration' || $__webAction === 'setup')
    && !is_file($__seismoLocalConfig);

if (!is_file($__seismoLocalConfig)) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Missing config.local.php — copy config.local.php.example and fill in your database credentials.\n");
        exit(1);
    }
    if (!$__setupWithoutFile) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        die(
            "Missing config.local.php — copy config.local.php.example and fill in your database credentials.\n\n"
            . "First-time install: open index.php?action=configuration in your browser to generate a starter file and test the database.\n"
        );
    }
    // Web `?action=configuration` (legacy: `setup`) with no local config yet — placeholders only until
    // SetupController writes a real file. Do not call getDbConnection() in this mode.
    define('DB_HOST', 'localhost');
    define('DB_NAME', '');
    define('DB_USER', '');
    define('DB_PASS', '');
} else {
    require $__seismoLocalConfig;
}
unset($__seismoLocalConfig, $__webAction, $__setupWithoutFile);

// Optional DB port — omit in config.local.php to use the driver default (3306).
if (!defined('DB_PORT')) {
    define('DB_PORT', '');
}

// ---------------------------------------------------------------------------
// 2. SEISMO_* defaults (satellite / branding / remote refresh)
// ---------------------------------------------------------------------------
$__seismoDefaults = [
    /** Shared entries database on the VPS (feeds, emails, lex, leg). */
    'SEISMO_ENTRIES_DB'        => 'seismo',
    /** Set by /<slug>/index.php stub before bootstrap; empty on mothership. */
    'SEISMO_SATELLITE_SLUG'    => '',
    'SEISMO_BRAND_TITLE'       => '',
    'SEISMO_BRAND_ACCENT'      => '',
    'SEISMO_MOTHERSHIP_URL'    => '',
    'SEISMO_REMOTE_REFRESH_KEY' => '',
    'SEISMO_ADMIN_PASSWORD_HASH' => '',
    /** Display timezone for day labels and clocks in the UI (data layer stays UTC). */
    'SEISMO_VIEW_TIMEZONE'     => 'Europe/Zurich',
];
foreach ($__seismoDefaults as $__c => $__v) {
    if (!defined($__c)) {
        define($__c, $__v);
    }
}
unset($__seismoDefaults, $__c, $__v);

if (!defined('SEISMO_ENTRIES_DB') || SEISMO_ENTRIES_DB === '') {
    define('SEISMO_ENTRIES_DB', defined('DB_NAME') && DB_NAME !== '' ? (string)DB_NAME : 'seismo');
}

// ---------------------------------------------------------------------------
// 3. Autoloaders — Composer first, then our own Seismo\* loader.
// ---------------------------------------------------------------------------
// Composer vendor libs (SimplePie, EasyRdf, etc.) must be available before any
// plugin that depends on them is instantiated. Safe to include now even though
// vendor/ doesn't exist yet — the file_exists() check short-circuits cleanly.
$__seismoVendorAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($__seismoVendorAutoload)) {
    require_once $__seismoVendorAutoload;
}
unset($__seismoVendorAutoload);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Seismo\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// ---------------------------------------------------------------------------
// 4. Global helpers
// ---------------------------------------------------------------------------

/**
 * PDO singleton. One connection per request.
 *
 * Default database is the scores/config catalog: `seismo` on the mothership,
 * `seismo_<slug>` on a path satellite. Entry-source tables are always read via
 * {@see entryTable()} against {@see SEISMO_ENTRIES_DB}.
 *
 * Throws PDOException on failure — callers decide how to present the error
 * (HTTP 503 vs CLI stderr). See `migrate.php` and `index.php` for both styles.
 */
function getDbConnection(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $host = DB_HOST;
    $port = null;
    if (preg_match('/^(.+):(\d+)$/', $host, $m)) {
        $host = $m[1];
        $port = (int)$m[2];
    }
    if (defined('DB_PORT') && DB_PORT !== '' && DB_PORT !== null) {
        $port = (int)DB_PORT;
    }
    $dsn = 'mysql:host=' . $host . ';dbname=' . seismoScoresDbName() . ';charset=utf8mb4';
    if ($port !== null) {
        $dsn .= ';port=' . $port;
    }
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // Pin the session to UTC regardless of how the host configured the server.
    // PHP is already UTC (see bootstrap section 0); this keeps MariaDB aligned
    // so NOW(), CURRENT_TIMESTAMP, and implicit TIMESTAMP conversions all
    // speak the same time zone as our PHP DateTimeImmutable values.
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

/**
 * PDO to a scores/config catalog (mothership `seismo` or desk `seismo_<slug>`).
 * Used by migrate, satellite rescore cron, and CLI tools — not request-scoped {@see getDbConnection()}.
 */
function seismoPdoForScoresCatalog(string $scoresDbName): PDO
{
    $scoresDbName = trim($scoresDbName);
    if ($scoresDbName === '') {
        throw new \InvalidArgumentException('Scores database name is empty.');
    }
    $host = DB_HOST;
    $port = null;
    if (preg_match('/^(.+):(\d+)$/', $host, $m)) {
        $host = $m[1];
        $port = (int)$m[2];
    }
    if (defined('DB_PORT') && DB_PORT !== '' && DB_PORT !== null) {
        $port = (int)DB_PORT;
    }
    $dsn = 'mysql:host=' . $host . ';dbname=' . $scoresDbName . ';charset=utf8mb4';
    if ($port !== null) {
        $dsn .= ';port=' . $port;
    }
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

/**
 * True when config.local.php exists and contains database credentials.
 * Once configured, the setup helper must be completely locked down.
 */
function isConfigured(): bool
{
    if (!is_file(SEISMO_ROOT . '/config.local.php')) {
        return false;
    }
    return defined('DB_NAME') && DB_NAME !== '' && defined('DB_USER') && DB_USER !== '';
}

/**
 * True when config.local.php exists, credentials are set, and PDO connects.
 * Use {@see isConfigured()} to gate the web installer; this function additionally
 * requires a live PDO connection.
 */
function hasDbConnection(): bool
{
    if (!isConfigured()) {
        return false;
    }
    try {
        getDbConnection();

        return true;
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Base URL path for web-relative links. Derived from PHP_SELF so Seismo can
 * live at `/` or inside a subfolder like `/seismo/` without code changes.
 *
 * Returns '' for root installs, otherwise the leading path (no trailing slash).
 * MUST be used for every internal href/redirect — never hardcode hostnames.
 */
function getBasePath(): string
{
    $phpSelf = $_SERVER['PHP_SELF'] ?? '';
    $path = dirname($phpSelf);
    if ($path === '/' || $path === '\\' || $path === '.' || $path === '') {
        return '';
    }
    return rtrim(str_replace('\\', '/', $path), '/');
}

/**
 * Contact URL embedded in outbound HTTP User-Agent strings (RFC 9309 style).
 * Some publishers (e.g. Ringier / Blick.ch) return 403 without a (+url) suffix.
 */
function seismoHttpContactUrl(): string
{
    if (defined('SEISMO_MOTHERSHIP_URL') && SEISMO_MOTHERSHIP_URL !== '') {
        return rtrim((string) SEISMO_MOTHERSHIP_URL, '/');
    }

    if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $scheme = $https ? 'https' : 'http';

        return $scheme . '://' . (string) $_SERVER['HTTP_HOST'] . getBasePath();
    }

    return 'https://hektopascal.org';
}

function seismoHttpUserAgent(): string
{
    $version = defined('SEISMO_VERSION') ? (string) SEISMO_VERSION : 'dev';

    return 'Seismo/' . $version . ' (+' . seismoHttpContactUrl() . ')';
}

/**
 * Normalised path slug (e.g. `security` for /security/). Empty on mothership.
 */
function seismoSatelliteSlug(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }
    if (defined('SEISMO_SATELLITE_SLUG') && SEISMO_SATELLITE_SLUG !== '') {
        $resolved = seismoNormaliseSatelliteSlug((string)SEISMO_SATELLITE_SLUG);

        return $resolved;
    }
    // Cron/migrate CLI: PHP_SELF is often a filesystem path (/var/www/seismo/…),
    // which would wrongly infer slug "var". Mothership CLI has no slug unless set above.
    if (PHP_SAPI === 'cli') {
        $resolved = '';

        return $resolved;
    }
    $base = getBasePath();
    if ($base === '') {
        $resolved = '';

        return $resolved;
    }
    $segment = trim($base, '/');
    if ($segment === '' || str_contains($segment, '/')) {
        $segment = explode('/', $segment, 2)[0] ?? '';
    }
    $slug = seismoNormaliseSatelliteSlug($segment);
    if ($slug === '' || in_array($slug, seismoReservedSatelliteSlugs(), true)) {
        $resolved = '';

        return $resolved;
    }
    // Path mounts are satellites only when registered (avoids /seismo/ subfolder false positives).
    if (!seismoSatelliteSlugInRegistry($slug)) {
        $resolved = '';

        return $resolved;
    }
    $resolved = $slug;

    return $resolved;
}

function seismoNormaliseSatelliteSlug(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = (string)preg_replace('/[^a-z0-9-]+/', '-', $slug);
    $slug = (string)preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');

    return substr($slug, 0, 40);
}

/** Slugs that must not be used as /{slug}/ mount paths. */
function seismoReservedSatelliteSlugs(): array
{
    return [
        'assets', 'vendor', 'src', 'config', 'docs', 'tests', 'storage',
        'logs', 'bin', 'views', 'migrate', 'index', 'health',
    ];
}

/**
 * True when this request is served from a path satellite (e.g. /security/).
 */
function isSatellite(): bool
{
    return seismoSatelliteSlug() !== '';
}

/**
 * MariaDB database that holds scores, labels, favourites, and system_config
 * for the current desk.
 */
function seismoScoresDbName(): string
{
    $slug = seismoSatelliteSlug();
    if ($slug === '') {
        return (string)SEISMO_ENTRIES_DB;
    }

    return 'seismo_' . $slug;
}

/**
 * Registry row for the active path satellite, or null on mothership / unknown slug.
 *
 * @return array<string, mixed>|null
 */
function seismoCurrentSatellite(): ?array
{
    static $cached = null;
    static $loaded = false;
    if ($loaded) {
        return $cached;
    }
    $loaded = true;
    $slug = seismoSatelliteSlug();
    if ($slug === '') {
        $cached = null;

        return null;
    }
    foreach (seismoSatellitesRegistry() as $row) {
        if (($row['slug'] ?? '') === $slug) {
            $cached = $row;

            return $cached;
        }
    }
    $cached = [
        'slug' => $slug,
        'db_name' => 'seismo_' . $slug,
        'mount_path' => '/' . $slug,
        'display_name' => 'Seismo ' . ucfirst($slug),
        'magnitu_profile' => $slug,
        'brand_accent' => '',
    ];

    return $cached;
}

/**
 * Raw satellite registry from mothership `system_config.satellites_registry`.
 * Uses the entries catalog only — safe before {@see getDbConnection()} on a desk.
 *
 * @return list<array<string, mixed>>
 */
function seismoFetchSatellitesRegistryRaw(): array
{
    static $registry = null;
    if ($registry !== null) {
        return $registry;
    }
    $registry = [];
    if (!isConfigured()) {
        return $registry;
    }
    try {
        $entriesDb = '`' . str_replace('`', '``', (string)SEISMO_ENTRIES_DB) . '`';
        $pdo = seismoPdoForScoresCatalog((string)SEISMO_ENTRIES_DB);
        $stmt = $pdo->prepare(
            "SELECT config_value FROM {$entriesDb}.system_config WHERE config_key = 'satellites_registry' LIMIT 1"
        );
        $stmt->execute();
        $raw = $stmt->fetchColumn();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $registry = is_array($decoded) ? array_values($decoded) : [];
        }
    } catch (\Throwable) {
        $registry = [];
    }

    return $registry;
}

function seismoSatelliteSlugInRegistry(string $slug): bool
{
    if ($slug === '') {
        return false;
    }
    foreach (seismoFetchSatellitesRegistryRaw() as $row) {
        if (($row['slug'] ?? '') === $slug) {
            return true;
        }
    }

    return false;
}

/**
 * Mothership satellite registry from `system_config.satellites_registry`.
 *
 * @return list<array<string, mixed>>
 */
function seismoSatellitesRegistry(): array
{
    return seismoFetchSatellitesRegistryRaw();
}

/**
 * SQL reference for an entry-source table in {@see SEISMO_ENTRIES_DB}.
 *
 * Use for entry-source tables only. NEVER use for entry_scores, system_config,
 * magnitu_labels, entry_favourites — those live in {@see seismoScoresDbName()}.
 */
function entryTable(string $table): string
{
    $quoted = '`' . str_replace('`', '``', $table) . '`';
    if (isSatellite()) {
        return '`' . str_replace('`', '``', (string)SEISMO_ENTRIES_DB) . '`.' . $quoted;
    }

    return $quoted;
}

/**
 * SQL expression for the schema that holds entry tables.
 * Used inline in INFORMATION_SCHEMA queries.
 */
function entryDbSchemaExpr(): string
{
    if (isSatellite()) {
        return "'" . addslashes((string)SEISMO_ENTRIES_DB) . "'";
    }

    return 'DATABASE()';
}

/**
 * Canonical email storage table after Slice 4 migration (unified `emails`).
 * CLI mail fetchers and repositories should target this name; use
 * {@see entryTable()} when building SQL.
 */
function getEmailTableName(): string
{
    return 'emails';
}

/**
 * Product name before the version segment (default "Seismo").
 * Satellites may set SEISMO_BRAND_TITLE to override the default prefix.
 */
function seismoBrandBase(): string
{
    return SEISMO_BRAND_TITLE !== '' ? (string)SEISMO_BRAND_TITLE : 'Seismo';
}

/**
 * If $storedTitle is canonical "Seismo {suffix}", returns ['Seismo', suffix]. Else null.
 *
 * @return array{0:string,1:string}|null
 */
function seismoBrandDisplaySplit(string $storedTitle): ?array
{
    $t = trim($storedTitle);
    if ($t === '') {
        return null;
    }
    /* Canonical mothership exports: "Seismo Sicherheit". */
    if (preg_match('/^Seismo\s+(.+)$/iu', $t, $m) === 1 && trim($m[1]) !== '') {
        return ['Seismo', trim($m[1])];
    }
    /*
     * Single-token suffix-only (e.g. "Sicherheit") — satellite.json sometimes set
     * brand.title without the "Seismo " prefix, so config.local.php had only the suffix.
     * Do not infer for strings that already start with "Seismo" (e.g. "SeismoLabs").
     */
    if (
        strpos($t, ' ') === false
        && $t !== 'Seismo'
        && !preg_match('/^Seismo\s+/iu', $t)
        && !preg_match('/^Seismo$/iu', $t)
        && stripos($t, 'Seismo') !== 0
    ) {
        return ['Seismo', $t];
    }

    return null;
}

/**
 * User-chosen satellite name without the fixed "Seismo " prefix (for UI on satellites).
 * Falls back to the full configured title when it does not start with "Seismo ".
 */
function seismoBrandSuffix(): string
{
    $t = trim(SEISMO_BRAND_TITLE !== '' ? (string)SEISMO_BRAND_TITLE : '');
    if ($t === '' && isSatellite()) {
        $sat = seismoCurrentSatellite();
        if ($sat !== null) {
            $t = trim((string)($sat['display_name'] ?? ''));
        }
    }
    if ($t === '') {
        return 'Seismo';
    }
    $split = seismoBrandDisplaySplit($t);
    if ($split !== null) {
        return $split[1];
    }

    return $t;
}

/** Display version label for the top bar, e.g. "v0.5.3". Empty on satellites. */
function seismoBrandVersionLabel(): string
{
    if (isSatellite()) {
        return '';
    }

    return 'v' . SEISMO_VERSION;
}

/**
 * True when the satellite brand is canonical "Seismo {suffix}" (split header styling).
 */
function seismoSatelliteBrandSplit(): bool
{
    if (!isSatellite()) {
        return false;
    }
    $t = trim(SEISMO_BRAND_TITLE !== '' ? (string)SEISMO_BRAND_TITLE : '');
    if ($t === '') {
        $sat = seismoCurrentSatellite();
        if ($sat !== null) {
            $t = trim((string)($sat['display_name'] ?? ''));
        }
    }

    return $t !== '' && seismoBrandDisplaySplit($t) !== null;
}

/**
 * Brand string for document titles and APIs.
 * Mothership: base + version (e.g. "Seismo v0.5.3").
 * Satellite: full title "Seismo {suffix}" when canonical; otherwise stored title / fallback.
 */
function seismoBrandTitle(): string
{
    if (isSatellite()) {
        $configured = trim(SEISMO_BRAND_TITLE !== '' ? (string)SEISMO_BRAND_TITLE : '');
        if ($configured === '') {
            $sat = seismoCurrentSatellite();
            if ($sat !== null && trim((string)($sat['display_name'] ?? '')) !== '') {
                $configured = trim((string)$sat['display_name']);
            }
        }
        if ($configured !== '' && seismoBrandDisplaySplit($configured) !== null) {
            return 'Seismo ' . seismoBrandSuffix();
        }

        return $configured !== '' ? $configured : 'Seismo';
    }

    return trim(seismoBrandBase() . ' ' . seismoBrandVersionLabel());
}

/**
 * Optional per-satellite accent colour (hex). Null when not configured.
 */
function seismoBrandAccent(): ?string
{
    if (SEISMO_BRAND_ACCENT !== '') {
        return (string)SEISMO_BRAND_ACCENT;
    }
    if (isSatellite()) {
        $sat = seismoCurrentSatellite();
        $accent = trim((string)($sat['brand_accent'] ?? ''));
        if ($accent !== '') {
            return $accent;
        }
    }

    return null;
}

/** `system_config` key for satellite → mothership refresh (Settings → Satellites). */
function seismoRemoteRefreshConfigKey(): string
{
    return 'remote_refresh_key';
}

/** Legacy key — read as fallback until all installs use {@see seismoRemoteRefreshConfigKey()}. */
function seismoRemoteRefreshLegacyConfigKey(): string
{
    return 'satellites_suggested_refresh_key';
}

/**
 * Shared secret for {@see refresh_all_remote} / satellite Refresh.
 * Optional override via `SEISMO_REMOTE_REFRESH_KEY` in config.local.php; otherwise
 * read from mothership `system_config`.
 */
function seismoRemoteRefreshKey(bool $forceReload = false): string
{
    static $cached = null;
    if (!$forceReload && $cached !== null) {
        return $cached;
    }
    $fromConst = defined('SEISMO_REMOTE_REFRESH_KEY') ? trim((string)SEISMO_REMOTE_REFRESH_KEY) : '';
    if ($fromConst !== '') {
        $cached = $fromConst;

        return $cached;
    }
    foreach ([seismoRemoteRefreshConfigKey(), seismoRemoteRefreshLegacyConfigKey()] as $configKey) {
        $fromDb = seismoMothershipConfigValue($configKey);
        if ($fromDb !== null && $fromDb !== '') {
            $cached = $fromDb;

            return $cached;
        }
    }
    $cached = '';

    return $cached;
}

function seismoRemoteRefreshKeyConfigured(): bool
{
    return seismoRemoteRefreshKey() !== '';
}

/**
 * Create a mothership refresh key in `system_config` when none exists (mothership only).
 */
function seismoEnsureRemoteRefreshKey(): string
{
    $existing = seismoRemoteRefreshKey();
    if ($existing !== '') {
        return $existing;
    }
    if (isSatellite() || !hasDbConnection()) {
        return '';
    }
    $key = bin2hex(random_bytes(32));
    $config = new \Seismo\Repository\SystemConfigRepository(getDbConnection());
    $config->set(seismoRemoteRefreshConfigKey(), $key);
    seismoRemoteRefreshKey(true);

    return $key;
}

/**
 * Read one row from mothership `system_config` (cross-DB on path satellites).
 */
function seismoMothershipConfigValue(string $configKey): ?string
{
    if (!hasDbConnection()) {
        return null;
    }
    try {
        if (isSatellite()) {
            $db = '`' . str_replace('`', '``', (string)SEISMO_ENTRIES_DB) . '`';
            $pdo = getDbConnection();
            $stmt = $pdo->prepare(
                "SELECT config_value FROM {$db}.system_config WHERE config_key = ? LIMIT 1"
            );
            $stmt->execute([$configKey]);
            $raw = $stmt->fetchColumn();
        } else {
            $raw = (new \Seismo\Repository\SystemConfigRepository(getDbConnection()))->get($configKey);
        }
        if ($raw === false || $raw === null || $raw === '') {
            return null;
        }

        return (string)$raw;
    } catch (\Throwable) {
        return null;
    }
}

/**
 * Absolute mothership base URL (no trailing slash) for satellite remote refresh.
 * Uses {@see SEISMO_MOTHERSHIP_URL} when set; otherwise derives from the current request
 * (path satellites strip their mount segment).
 */
function seismoMothershipBaseUrl(): string
{
    if (defined('SEISMO_MOTHERSHIP_URL') && SEISMO_MOTHERSHIP_URL !== '') {
        return rtrim((string)SEISMO_MOTHERSHIP_URL, '/');
    }
    $scheme = 'http';
    $httpsFlag = (string)($_SERVER['HTTPS'] ?? '');
    if ($httpsFlag !== '' && strtolower($httpsFlag) !== 'off') {
        $scheme = 'https';
    } elseif (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        $scheme = 'https';
    }
    $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    $bp = getBasePath();
    if (isSatellite() && $bp !== '') {
        $parent = dirname($bp);
        if ($parent === '/' || $parent === '\\' || $parent === '.') {
            $bp = '';
        } else {
            $bp = rtrim(str_replace('\\', '/', $parent), '/');
        }
    }

    return $scheme . '://' . $host . ($bp === '' ? '' : $bp);
}

/**
 * Timezone for view-layer date labels and formatted clocks (dashboard day
 * separators, Lex/Leg/Diagnostics timestamps). Repository timestamps remain UTC.
 */
if (!function_exists('seismo_view_timezone')) {
    function seismo_view_timezone(): \DateTimeZone
    {
        return TimelineEntryDatetime::viewTimezone();
    }
}

/**
 * Parse a DB datetime string written as UTC (Gmail/IMAP ingest) for sorting and labels.
 */
if (!function_exists('seismo_parse_stored_utc_datetime')) {
    function seismo_parse_stored_utc_datetime(?string $stored): ?\DateTimeImmutable
    {
        return TimelineEntryDatetime::parseStoredUtcDatetime($stored);
    }
}

if (!function_exists('seismo_stored_utc_to_unix')) {
    function seismo_stored_utc_to_unix(?string $stored): int
    {
        return TimelineEntryDatetime::storedUtcToUnix($stored);
    }
}

/**
 * Format a UTC-stored timestamp for the dashboard (Europe/Zurich by default).
 */
if (!function_exists('seismo_format_stored_utc_datetime')) {
    function seismo_format_stored_utc_datetime(?string $stored, string $format = 'd.m.Y H:i'): string
    {
        return TimelineEntryDatetime::formatStoredUtcDatetime($stored, $format);
    }
}

if (!function_exists('seismo_timeline_day_key_in_view_tz')) {
    function seismo_timeline_day_key_in_view_tz(int $unix): string
    {
        return TimelineEntryDatetime::timelineDayKeyInViewTz($unix);
    }
}

if (!function_exists('seismo_format_wrapper_card_clock')) {
    /** @param array<string, mixed> $wrapper */
    function seismo_format_wrapper_card_clock(array $wrapper): string
    {
        return TimelineEntryDatetime::formatWrapperCardClock($wrapper);
    }
}

if (!function_exists('seismo_feed_item_timeline_stored_datetime')) {
    /** @param array<string, mixed> $row */
    function seismo_feed_item_timeline_stored_datetime(array $row): ?string
    {
        return TimelineEntryDatetime::feedItemStoredDatetime($row);
    }
}

if (!function_exists('seismo_feed_item_timeline_unix')) {
    /** @param array<string, mixed> $row */
    function seismo_feed_item_timeline_unix(array $row): int
    {
        return TimelineEntryDatetime::feedItemUnix($row);
    }
}

if (!function_exists('seismo_format_feed_item_timeline_datetime')) {
    /** @param array<string, mixed> $row */
    function seismo_format_feed_item_timeline_datetime(array $row, string $format = 'd.m.Y H:i'): string
    {
        return TimelineEntryDatetime::formatFeedItemDatetime($row, $format);
    }
}

if (!function_exists('seismo_email_timeline_stored_datetime')) {
    /** @param array<string, mixed> $row */
    function seismo_email_timeline_stored_datetime(array $row): ?string
    {
        return TimelineEntryDatetime::emailStoredDatetime($row);
    }
}

if (!function_exists('seismo_email_timeline_unix')) {
    /** @param array<string, mixed> $row */
    function seismo_email_timeline_unix(array $row): int
    {
        return TimelineEntryDatetime::emailUnix($row);
    }
}

if (!function_exists('seismo_format_email_timeline_datetime')) {
    /** @param array<string, mixed> $row */
    function seismo_format_email_timeline_datetime(array $row, string $format = 'd.m.Y H:i'): string
    {
        return TimelineEntryDatetime::formatEmailDatetime($row, $format);
    }
}

if (!function_exists('seismo_lex_item_timeline_unix')) {
    /** @param array<string, mixed> $row */
    function seismo_lex_item_timeline_unix(array $row): int
    {
        return TimelineEntryDatetime::lexItemUnix($row);
    }
}

if (!function_exists('seismo_format_lex_item_timeline_datetime')) {
    /** @param array<string, mixed> $row */
    function seismo_format_lex_item_timeline_datetime(array $row): string
    {
        return TimelineEntryDatetime::formatLexItemDatetime($row);
    }
}

if (!function_exists('seismo_calendar_event_timeline_unix')) {
    /** @param array<string, mixed> $row */
    function seismo_calendar_event_timeline_unix(array $row): int
    {
        return TimelineEntryDatetime::calendarEventUnix($row);
    }
}

if (!function_exists('seismo_format_calendar_event_timeline_date')) {
    /** @param array<string, mixed> $row */
    function seismo_format_calendar_event_timeline_date(array $row): string
    {
        return TimelineEntryDatetime::formatCalendarEventDate($row);
    }
}

/**
 * HTML-escape helper for views.
 *
 * Always double-encodes, escapes both quote styles, and assumes UTF-8 input.
 * Keeping this in bootstrap (rather than a templating engine) lets views
 * stay plain PHP while still having a consistent, short escape idiom:
 *
 *   <?= e($user['name']) ?>
 */
if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
