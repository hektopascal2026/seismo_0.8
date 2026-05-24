<?php
/**
 * Backfill `lex_items.content` for rows ingested before full-text corpus storage.
 *
 * Usage:
 *   php bin/lex-backfill-content.php              # Jus HTML corpus (default batch 50)
 *   php bin/lex-backfill-content.php --de         # promote DE RSS description → content
 *   php bin/lex-backfill-content.php --limit=100
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

if ($deOnly) {
    $n = $service->backfillDeFromDescription($limit);
    echo "DE description → content: {$n} row(s) updated.\n";
    exit(0);
}

$result = $service->backfillJus($limit);
echo sprintf(
    "Jus corpus backfill: %d updated, %d skipped, %d failed (batch limit %d).\n",
    $result['updated'],
    $result['skipped'],
    $result['failed'],
    $limit,
);

exit($result['failed'] > 0 && $result['updated'] === 0 ? 1 : 0);
