<?php

declare(strict_types=1);

namespace Seismo\Core;

/**
 * Magnitu relevance bands — shared by timeline badges and Briefing Builder gather.
 *
 * Badge colours use fixed score quartiles; Highlights and Briefing use the
 * configurable alert threshold from Settings → Magnitu.
 */
final class MagnituScoreBands
{
    /** Exclusive lower bound for the Important badge band (UI: score &gt; 50%). */
    public const IMPORTANT_MIN_EXCLUSIVE = 0.50;

    /** Exclusive lower bound for the Investigation badge band (UI: score &gt; 75%). */
    public const INVESTIGATION_MIN_EXCLUSIVE = 0.75;

    public static function passesHighlightsTier(float $score, float $alertThreshold): bool
    {
        return $score >= self::clampThreshold($alertThreshold);
    }

    /**
     * Important band below the Highlights bar (51%–threshold, exclusive of threshold).
     */
    public static function passesImportantBelowThreshold(float $score, float $alertThreshold): bool
    {
        $t = self::clampThreshold($alertThreshold);

        return $score > self::IMPORTANT_MIN_EXCLUSIVE && $score < $t;
    }

    public static function passesBriefingPool(
        float $score,
        float $alertThreshold,
        bool $includeImportantBelowThreshold,
    ): bool {
        if (self::passesHighlightsTier($score, $alertThreshold)) {
            return true;
        }

        if (!$includeImportantBelowThreshold) {
            return false;
        }

        return self::passesImportantBelowThreshold($score, $alertThreshold);
    }

    /**
     * CSS class for the timeline Magnitu badge (matches dashboard_entry_loop.php).
     */
    public static function badgeCssClass(float $relevanceScore): string
    {
        $pct = (int)round(max(0.0, min(1.0, $relevanceScore)) * 100);
        if ($pct <= 25) {
            return 'magnitu-badge-noise';
        }
        if ($pct <= 50) {
            return 'magnitu-badge-background';
        }
        if ($pct <= 75) {
            return 'magnitu-badge-important';
        }

        return 'magnitu-badge-investigation';
    }

    /**
     * Human-readable summary for briefing UI / export meta.
     */
    public static function describeBriefingPool(float $alertThreshold, bool $includeImportantBelow): string
    {
        $pct = (int)round(self::clampThreshold($alertThreshold) * 100);
        $base = 'relevance ≥ ' . $pct . '% (Highlights tier, Settings → Magnitu alert threshold)';
        if (!$includeImportantBelow) {
            return $base;
        }

        return $base . '; plus important band below threshold (score &gt; 50% and &lt; ' . $pct . '%)';
    }

    private static function clampThreshold(float $alertThreshold): float
    {
        return max(0.0, min(1.0, $alertThreshold));
    }
}
