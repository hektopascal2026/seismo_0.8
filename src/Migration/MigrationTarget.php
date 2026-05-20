<?php

declare(strict_types=1);

namespace Seismo\Migration;

/**
 * Selects which migrations {@see MigrationRunner} applies to the connected database.
 */
enum MigrationTarget: string
{
    /** Full Seismo schema on the entries database (`seismo`). */
    case Mothership = 'mothership';

    /** Satellite scores database (`seismo_<slug>`) — local tables only. */
    case Scores = 'scores';

    public function accepts(MigrationScope $scope): bool
    {
        return match ($this) {
            self::Mothership => $scope !== MigrationScope::ScoresOnly,
            self::Scores     => $scope !== MigrationScope::MothershipOnly,
        };
    }
}
