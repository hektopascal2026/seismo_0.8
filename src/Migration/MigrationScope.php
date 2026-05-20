<?php

declare(strict_types=1);

namespace Seismo\Migration;

/**
 * Which database(s) a migration may touch.
 *
 * Mothership-only migrations change entry sources, cron logs, etc.
 * Scores-only migrations exist only for satellite score DB bootstrap (rare).
 * Both run on the mothership DB and on each satellite scores DB (e.g. config rename).
 */
enum MigrationScope: string
{
    case MothershipOnly = 'mothership';
    case ScoresOnly     = 'scores';
    case Both           = 'both';
}
