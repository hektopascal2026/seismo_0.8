<?php

declare(strict_types=1);

namespace Seismo\Core\Magnitu;

/**
 * Frozen Magnitu v3 field normalisation shared by export shaping and recipe scoring.
 *
 * Keeps {@see \Seismo\Controller\MagnituController::shape*()} and
 * {@see \Seismo\Core\Scoring\ScoringService} aligned on `source_type` keys.
 */
final class MagnituEntryContract
{
    /**
     * Leg / calendar_event `source_type` in magnitu_entries (e.g. leg_parliament_ch).
     */
    public static function legSourceType(string $source): string
    {
        $source = trim($source);

        return 'leg_' . ($source !== '' ? preg_replace('/[^a-z0-9_]+/i', '_', $source) : 'parliament');
    }

    /**
     * Feed `source_type` for recipe scoring — mirrors raw feeds.source_type in export,
     * with unknown values folded to rss (legacy 0.4 behaviour).
     */
    public static function feedSourceType(string $sourceType): string
    {
        $st = trim($sourceType);

        return in_array($st, ['substack', 'scraper', 'parl_press'], true)
            ? $st
            : 'rss';
    }
}
