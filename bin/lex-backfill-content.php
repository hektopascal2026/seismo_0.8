<?php
/**
 * Backfill `lex_items.content` for rows ingested before full-text corpus storage.
 *
 * Usage:
 *   php bin/lex-backfill-content.php              # Jus HTML corpus (default batch 50)
 *   php bin/lex-backfill-content.php --de         # fetch BGBl PDF corpus (requires pdftotext)
 *   php bin/lex-backfill-content.php --ch         # Fedlex OC acts: Akoma XML (empty + synopsis-only content)
 *   php bin/lex-backfill-content.php --ch-promote # Vernehmlassungen: description → content
 *   php bin/lex-backfill-content.php --stats      # includes Fedlex OC corpus breakdown
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
$chPromoteOnly = in_array('--ch-promote', $argv, true);
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
    echo "\nFedlex CH (eli/oc official compilation) corpus:\n";
    $fx = $service->fedlexCorpusBreakdown();
    echo sprintf("  total_ch=%d oc_acts=%d consultations=%d\n", $fx['total_ch'], $fx['oc_acts'], $fx['consultations']);
    echo sprintf(
        "  oc_empty_content=%d oc_stale_short=%d oc_synopsis_prefix_match=%d oc_has_corpus=%d oc_unavailable=%d\n",
        $fx['oc_empty_content'],
        $fx['oc_stale_short'],
        $fx['oc_synopsis_prefix_match'],
        $fx['oc_has_corpus'],
        $fx['oc_unavailable'],
    );
    echo sprintf("  oc_needs_backfill (next --ch run, up to limit)=%d\n", $fx['oc_needs_backfill']);
    if (!$deOnly && !$chOnly && !$chPromoteOnly && !$euOnly && !$frOnly && !in_array('--stats-only', $argv, true)) {
        echo "\n";
    } else {
        exit(0);
    }
}

if ($chPromoteOnly) {
    $n = $service->backfillChFromDescription($limit);
    echo "CH description → content (consultations): {$n} row(s) updated.\n";
    exit(0);
}

if ($chOnly) {
    $result = $service->backfillChDetailed($limit, $verbose);
    echo sprintf(
        "CH Fedlex XML corpus backfill: %d updated, %d skipped, %d failed (batch limit %d).\n",
        $result['updated'],
        $result['skipped'],
        $result['failed'],
        $limit,
    );
    exit($result['failed'] > 0 && $result['updated'] === 0 ? 1 : 0);
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
