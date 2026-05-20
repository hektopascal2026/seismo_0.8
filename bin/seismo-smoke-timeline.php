<?php
/**
 * CLI smoke test for dashboard timeline queries (prints the real exception).
 *
 *   php bin/seismo-smoke-timeline.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

use Seismo\Repository\EntryRepository;

try {
    $pdo  = getDbConnection();
    $repo = new EntryRepository($pdo);
    echo "getFilterPillOptions …\n";
    $pills = $repo->getFilterPillOptions();
    echo '  ok (' . count($pills['feed_categories'] ?? []) . " feed pills)\n";
    echo "getLatestTimeline …\n";
    $items = $repo->getLatestTimeline(5, 0);
    echo '  ok (' . count($items) . " items)\n";
    echo "getScraperModuleTimeline …\n";
    $scraper = $repo->getScraperModuleTimeline(5, 0);
    echo '  ok (' . count($scraper) . " items)\n";
    echo "All timeline smoke checks passed.\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . "\n");
    if ($e->getPrevious() instanceof \Throwable) {
        fwrite(STDERR, 'Previous: ' . $e->getPrevious()->getMessage() . "\n");
    }
    exit(1);
}
