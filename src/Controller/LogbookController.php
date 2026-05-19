<?php
/**
 * Mothership — read-only audit of new Feeds / Scraper / Mail sources ({@see SourceLogRepository}).
 */

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Http\CsrfToken;
use Seismo\Repository\SourceLogRepository;

final class LogbookController
{
    public function show(): void
    {
        if (isSatellite()) {
            header('Location: ' . getBasePath() . '/index.php?action=index', true, 303);
            exit;
        }

        $csrfField = CsrfToken::field();
        $basePath  = getBasePath();
        $pageError = null;
        $entries   = [];

        try {
            $entries = (new SourceLogRepository(getDbConnection()))->listRecent(1500);
        } catch (\Throwable $e) {
            error_log('Seismo logbook: ' . $e->getMessage());
            $pageError = 'Could not load logbook. Run migrations if `source_log` is missing.';
        }

        $headerTitle    = 'Logbook';
        $headerSubtitle = 'New sources (Feeds, Scraper, Mail)';
        $activeNav      = 'logbook';

        require_once SEISMO_ROOT . '/views/helpers.php';
        require SEISMO_ROOT . '/views/logbook.php';
    }

    /**
     * Human prefix for a log line, e.g. "RSS Feed", "Mail subscription".
     */
    public static function kindPrefix(string $kind): string
    {
        return match ($kind) {
            SourceLogRepository::KIND_RSS        => 'RSS Feed',
            SourceLogRepository::KIND_SUBSTACK   => 'Substack Feed',
            SourceLogRepository::KIND_PARL_PRESS => 'Parl. Press feed',
            SourceLogRepository::KIND_SCRAPER    => 'Scraper source',
            SourceLogRepository::KIND_MAIL      => 'Mail subscription',
            default                              => 'Source',
        };
    }
}
