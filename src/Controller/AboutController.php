<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Http\CsrfToken;
use Seismo\Repository\AboutStatsRepository;
use Seismo\Repository\EntryScoreRepository;

/**
 * In-app product overview: features, export API, optional live counts, history.
 */
final class AboutController
{
    public function show(): void
    {
        $csrfField = CsrfToken::field();
        $basePath  = getBasePath();
        $accent    = seismoBrandAccent();

        require_once SEISMO_ROOT . '/views/helpers.php';

        $headerTitle    = seismoBrandTitle();
        $headerSubtitle = !isSatellite() ? 'What this app does' : null;
        $activeNav      = 'about';

        $aboutStats  = null;
        $scoreCounts = null;
        try {
            $pdo         = getDbConnection();
            $aboutStats  = (new AboutStatsRepository($pdo))->entrySnapshot();
            $scoreCounts = (new EntryScoreRepository($pdo))->getScoreCounts();
        } catch (\Throwable $e) {
            error_log('Seismo about: ' . $e->getMessage());
        }

        $seismoVersion = SEISMO_VERSION;
        $satellite     = isSatellite();

        require SEISMO_ROOT . '/views/about.php';
    }
}
