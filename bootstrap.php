<?php
/**
 * Seismo bootstrap.
 *
 * Responsibilities (kept intentionally small):
 *   1. Load local credentials from config.local.php.
 *   2. Define SEISMO_* constants (satellite/brand knobs) with safe defaults.
 *   3. Register a minimal PSR-4 autoloader for Seismo\* classes under src/.
 *   4. Provide a handful of global helpers that every layer depends on:
 *      getDbConnection(), hasDbConnection(), getBasePath(), isSatellite(), entryTable(),
 *      entryDbSchemaExpr(), seismoBrandBase(), seismoBrandSuffix(),
 *      seismoBrandVersionLabel(), seismoBrandTitle(), seismoBrandAccent(),
 *      seismoSatelliteBrandSplit(), seismoBrandDisplaySplit().
 *
 * Anything larger (DDL, scoring, feature config) lives in its own module or
 * migration file. See docs/consolidation-plan.md.
 */

declare(strict_types=1);

define('SEISMO_VERSION', '0.6.1');
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
    'SEISMO_MOTHERSHIP_DB'     => '',
    'SEISMO_SATELLITE_MODE'    => false,
    'SEISMO_BRAND_TITLE'       => '',
    'SEISMO_BRAND_ACCENT'      => '',
    'SEISMO_MOTHERSHIP_URL'    => '',
    'SEISMO_REMOTE_REFRESH_KEY' => '',
    'FEED_DIAGNOSTIC_KEY'      => '',
    'SEISMO_MIGRATE_KEY'       => '',
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
    $dsn = 'mysql:host=' . $host . ';dbname=' . DB_NAME . ';charset=utf8mb4';
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
 * True when config.local.php exists, credentials are set, and PDO connects.
 * Used to hide the first-run configuration helper once the app can reach the DB.
 */
function hasDbConnection(): bool
{
    if (!is_file(SEISMO_ROOT . '/config.local.php')) {
        return false;
    }
    if (!defined('DB_NAME') || DB_NAME === '' || !defined('DB_USER') || DB_USER === '') {
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
 * True when this instance is a lightweight satellite.
 *
 * Satellites read entries cross-DB from a mothership and only keep scoring
 * tables locally. Used to hide admin/fetcher UI and short-circuit write routes.
 */
function isSatellite(): bool
{
    // Accept only boolean true/false in config.local.php; cast so `1`/`0` from
    // older copies do not silently fail the === true check.
    return (bool)SEISMO_SATELLITE_MODE;
}

/**
 * SQL reference for an entry-source table.
 *
 * Local mode  → bare table name.
 * Satellite   → `mothership_db`.table  for cross-DB reads.
 *
 * Use for entry-source tables only: feed_items, feeds, lex_items,
 * calendar_events (Leg), sender_tags, email_subscriptions, and the email
 * table. NEVER use for entry_scores, magnitu_config, magnitu_labels — those
 * are always local to each instance.
 */
function entryTable(string $table): string
{
    $quoted = '`' . str_replace('`', '``', $table) . '`';
    if (SEISMO_MOTHERSHIP_DB !== '') {
        return '`' . str_replace('`', '``', SEISMO_MOTHERSHIP_DB) . '`.' . $quoted;
    }
    return $quoted;
}

/**
 * SQL expression for the schema that holds entry tables.
 * Used inline in INFORMATION_SCHEMA queries.
 */
function entryDbSchemaExpr(): string
{
    if (SEISMO_MOTHERSHIP_DB !== '') {
        return "'" . addslashes(SEISMO_MOTHERSHIP_DB) . "'";
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
        if (seismoSatelliteBrandSplit()) {
            return 'Seismo ' . seismoBrandSuffix();
        }
        $t = trim(SEISMO_BRAND_TITLE !== '' ? (string)SEISMO_BRAND_TITLE : '');

        return $t !== '' ? $t : 'Seismo';
    }

    return trim(seismoBrandBase() . ' ' . seismoBrandVersionLabel());
}

/**
 * Optional per-satellite accent colour (hex). Null when not configured.
 */
function seismoBrandAccent(): ?string
{
    return SEISMO_BRAND_ACCENT !== '' ? (string)SEISMO_BRAND_ACCENT : null;
}

/**
 * Timezone for view-layer date labels and formatted clocks (dashboard day
 * separators, Lex/Leg/Diagnostics timestamps). Repository timestamps remain UTC.
 */
if (!function_exists('seismo_view_timezone')) {
    function seismo_view_timezone(): \DateTimeZone
    {
        static $cached = null;
        if ($cached instanceof \DateTimeZone) {
            return $cached;
        }
        $name = defined('SEISMO_VIEW_TIMEZONE') ? (string)SEISMO_VIEW_TIMEZONE : 'Europe/Zurich';
        if ($name === '') {
            $name = 'Europe/Zurich';
        }
        try {
            $cached = new \DateTimeZone($name);
        } catch (\Exception $e) {
            $cached = new \DateTimeZone('Europe/Zurich');
        }

        return $cached;
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
