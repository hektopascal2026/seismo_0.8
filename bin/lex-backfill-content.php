<?php
/**
 * Backfill `lex_items.content` for rows ingested before full-text corpus storage.
 *
 * Usage:
 *   php bin/lex-backfill-content.php              # Jus HTML corpus (default batch 50)
 *   php bin/lex-backfill-content.php --de         # fetch BGBl PDF corpus (requires pdftotext)
 *   php bin/lex-backfill-content.php --ch         # promote Fedlex CH description → content
 *   php bin/lex-backfill-content.php --eu         # fetch EUR-Lex HTML corpus
 *   php bin/lex-backfill-content.php --fr         # fetch Légifrance JORF corpus via PISTE API
 *   php bin/lex-backfill-content.php --limit=100
 *   php bin/lex-backfill-content.php --stats      # show per-source missing counts
 *   php bin/lex-backfill-content.php --verbose    # print skip/fail reason breakdown
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

use Seismo\Service\LexContentBackfillService;

$limit = LexContentBackfillService::DEFAULT_BATCH;
$deOnly = in_array('--de', $argv, true);
$chOnly = in_array('--ch', $argv, true);
$euOnly = in_array('--eu', $argv, true);
$frOnly = in_array('--fr', $argv, true);
$stats  = in_array('--stats', $argv, true);
$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int)substr($arg, strlen('--limit=')));
    }
}

if (isSatellite()) {
    fwrite(STDERR, "Satellite mode — run on the mothership.\n");
    exit(0);
}

$service = LexContentBackfillService::boot(getDbConnection());

if ($stats) {
    echo "lex_items missing content by source:\n";
    foreach ($service->contentBackfillStats() as $row) {
        echo sprintf(
            "  %-10s missing=%d no_work_uri=%d has_description=%d\n",
            (string)($row['source'] ?? '?'),
            (int)($row['missing'] ?? 0),
            (int)($row['no_work_uri'] ?? 0),
            (int)($row['has_description'] ?? 0),
        );
    }
    if (!$deOnly && !$chOnly && !$euOnly && !$frOnly && !in_array('--stats-only', $argv, true)) {
        echo "\n";
    } else {
        exit(0);
    }
}

if ($chOnly) {
    $n = $service->backfillChFromDescription($limit);
    echo "CH description → content: {$n} row(s) updated.\n";
    exit(0);
}

if ($deOnly) {
    try {
        $result = $service->backfillDeDetailed($limit, $verbose);
    } catch (\RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    echo sprintf(
        "DE recht.bund PDF corpus backfill: %d updated, %d skipped, %d failed (batch limit %d).\n",
        $result['updated'],
        $result['skipped'],
        $result['failed'],
        $limit,
    );
    exit($result['failed'] > 0 && $result['updated'] === 0 ? 1 : 0);
}

if ($frOnly) {
    $result = $service->backfillFrDetailed($limit, $verbose);
    echo sprintf(
        "FR Légifrance corpus backfill: %d updated, %d skipped, %d failed (batch limit %d).\n",
        $result['updated'],
        $result['skipped'],
        $result['failed'],
        $limit,
    );
    exit($result['failed'] > 0 && $result['updated'] === 0 ? 1 : 0);
}

if ($euOnly) {
    $result = $service->backfillEuDetailed($limit, $verbose);
    echo sprintf(
        "EU EUR-Lex corpus backfill: %d updated, %d skipped, %d failed (batch limit %d).\n",
        $result['updated'],
        $result['skipped'],
        $result['failed'],
        $limit,
    );
    exit($result['failed'] > 0 && $result['updated'] === 0 ? 1 : 0);
}

$result = $service->backfillJusDetailed($limit, $verbose);
echo sprintf(
    "Jus corpus backfill: %d updated, %d skipped, %d failed (batch limit %d).\n",
    $result['updated'],
    $result['skipped'],
    $result['failed'],
    $limit,
);

exit($result['failed'] > 0 && $result['updated'] === 0 ? 1 : 0);
