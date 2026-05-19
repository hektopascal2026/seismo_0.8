<?php
/**
 * JSON formatter for the public export surface.
 *
 * Consumes raw rows from {@see \Seismo\Repository\MagnituExportRepository}
 * (shaped into the Magnitu contract by
 * {@see \Seismo\Controller\MagnituController::shapeFeedItem()} and friends)
 * and merges the associated `entry_scores` row when present.
 *
 * No SQL. No HTML escaping. Output is JSON bytes ready for the HTTP body.
 *
 * The external consumer tracks its own "last seen" state via `since_id` /
 * `since` — see the stateless-export decision in
 * `docs/consolidation-plan.md`.
 */

declare(strict_types=1);

namespace Seismo\Formatter;

final class JsonExportFormatter
{
    public const CONTENT_TYPE = 'application/json; charset=utf-8';

    /**
     * @param array<int, array<string, mixed>> $entries  Already-shaped entry rows.
     * @param array<string, array<string, mixed>> $scoresByKey Map of "entry_type:entry_id" → score row.
     * @param array<string, mixed> $meta               Echoed under "meta" for client debugging.
     * @return string JSON body.
     */
    public static function format(array $entries, array $scoresByKey, array $meta): string
    {
        $out = [];
        foreach ($entries as $e) {
            $key   = ($e['entry_type'] ?? '') . ':' . ($e['entry_id'] ?? '');
            $score = $scoresByKey[$key] ?? null;

            $row = $e;
            if ($score !== null) {
                $row['score'] = [
                    'relevance_score' => (float)($score['relevance_score'] ?? 0),
                    'predicted_label' => $score['predicted_label'] ?? null,
                    'score_source'    => $score['score_source']    ?? null,
                    'model_version'   => (int)($score['model_version'] ?? 0),
                ];
            } else {
                $row['score'] = null;
            }
            $out[] = $row;
        }

        $payload = [
            'entries' => $out,
            'total'   => count($out),
            'meta'    => $meta,
        ];

        return (string)json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
