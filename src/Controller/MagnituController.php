<?php
/**
 * Magnitu HTTP API — the contract **Magnitu v3** relies on.
 *
 * Five actions, all Bearer-authenticated against `magnitu_config.api_key`:
 *
 *   GET  ?action=magnitu_entries  — export entries (since / type / limit)
 *   POST ?action=magnitu_scores   — batch score ingest
 *   GET  ?action=magnitu_recipe   — fetch current recipe JSON
 *   POST ?action=magnitu_recipe   — replace recipe + trigger rescore
 *   POST ?action=magnitu_labels   — batch label ingest
 *   GET  ?action=magnitu_labels   — labels dump (training data)
 *   GET  ?action=magnitu_status   — health / stats
 *
 * The response JSON shape is FROZEN — any change must be coordinated with
 * Magnitu v3 (`sync.py` / `main.py`). Field names and nesting follow the
 * 0.4-era contract, extended for Leg (`calendar_event`); see
 * `.cursor/rules/magnitu-integration.mdc`.
 *
 * Leg (`calendar_event`) is part of the same contract as feed / email / lex:
 * exported by `magnitu_entries`, scores and labels accepted, counted in
 * `magnitu_status`. See `.cursor/rules/magnitu-integration.mdc`.
 */

declare(strict_types=1);

namespace Seismo\Controller;

use PDOException;
use Seismo\Http\BearerAuth;
use Seismo\Core\Scoring\ScoringService;
use Seismo\Repository\EntryScoreRepository;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Repository\MagnituExportRepository;
use Seismo\Repository\MagnituLabelRepository;
use Seismo\Repository\EmailSubscriptionRepository;
use Seismo\Core\Mail\EmailListingBoilerplateStripper;

final class MagnituController
{
    /** @var list<array<string, mixed>>|null */
    private static ?array $emailSubscriptionListCache = null;
    private const LEX_SOURCE_LABELS = [
        'eu'       => 'EUR-Lex',
        'ch'       => 'Fedlex',
        'de'       => 'recht.bund.de',
        'fr'       => 'Légifrance',
        'ch_bger'  => 'Bundesgericht',
        'ch_bge'   => 'BGE Leitentscheide',
        'ch_bvger' => 'Bundesverwaltungsgericht',
    ];

    // ------------------------------------------------------------------
    // GET ?action=magnitu_entries
    //
    // Contract note: `limit` is applied **per family** when `type=all`, not
    // as a grand total. A request of `limit=500&type=all` can therefore
    // return up to 2,000 entries (500 × four families including Leg).
    // Coordinate with Magnitu v3 (`sync.py` / `main.py`). See
    // `.cursor/rules/magnitu-integration.mdc`.
    // ------------------------------------------------------------------

    public function entries(): void
    {
        $config = new SystemConfigRepository(getDbConnection());
        if (!BearerAuth::guardMagnitu($config)) {
            return;
        }

        $since = self::stringParam($_GET['since'] ?? null);
        $type  = self::stringParam($_GET['type'] ?? 'all') ?? 'all';
        $limit = self::clampInt($_GET['limit'] ?? 500, 1, MagnituExportRepository::MAX_LIMIT);

        $repo = new MagnituExportRepository(getDbConnection());
        $entries = [];

        if ($type === 'all' || $type === 'feed_item') {
            foreach ($repo->listFeedItemsSince($since, $limit) as $row) {
                $entries[] = self::shapeFeedItem($row);
            }
        }
        if ($type === 'all' || $type === 'email') {
            foreach ($repo->listEmailsSince($since, $limit) as $row) {
                $entries[] = self::shapeEmail($row);
            }
        }
        if ($type === 'all' || $type === 'lex_item') {
            foreach ($repo->listLexItemsSince($since, $limit) as $row) {
                $entries[] = self::shapeLexItem($row);
            }
        }
        if ($type === 'all' || $type === 'calendar_event') {
            foreach ($repo->listCalendarEventsSince($since, $limit) as $row) {
                $entries[] = self::shapeCalendarEvent($row);
            }
        }

        self::respondJson([
            'entries' => $entries,
            'total'   => count($entries),
            'since'   => $since,
            'type'    => $type,
        ]);
    }

    // ------------------------------------------------------------------
    // POST ?action=magnitu_scores
    // ------------------------------------------------------------------

    public function scores(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            self::respondJson(['error' => 'POST required'], 405);
            return;
        }
        $pdo = getDbConnection();
        $config = new SystemConfigRepository($pdo);
        if (!BearerAuth::guardMagnitu($config)) {
            return;
        }

        $input = self::decodeJsonBody();
        if (!is_array($input) || !isset($input['scores']) || !is_array($input['scores'])) {
            self::respondJson(['error' => 'Invalid JSON body, expected {scores: [...]}'], 400);
            return;
        }

        $modelVersion = (int)($input['model_version'] ?? 0);
        $scores       = $input['scores'];
        $inserted = 0;
        $updated  = 0;
        $errors   = 0;

        $repo = new EntryScoreRepository($pdo);
        foreach ($scores as $score) {
            if (!is_array($score)) {
                continue;
            }
            $entryType = (string)($score['entry_type'] ?? '');
            $entryId   = (int)($score['entry_id']   ?? 0);
            if (!in_array($entryType, EntryScoreRepository::MAGNITU_ENTRY_TYPES, true) || $entryId <= 0) {
                continue;
            }
            $explanation = null;
            if (isset($score['explanation']) && is_array($score['explanation'])) {
                $explanation = $score['explanation'];
            }
            try {
                $result = $repo->upsertMagnituScore(
                    $entryType,
                    $entryId,
                    (float)($score['relevance_score'] ?? 0),
                    isset($score['predicted_label']) ? (string)$score['predicted_label'] : null,
                    $explanation,
                    $modelVersion,
                );
                $result === 'inserted' ? $inserted++ : $updated++;
            } catch (PDOException $e) {
                $errors++;
            }
        }

        $config->set('last_sync_at', gmdate('Y-m-d H:i:s'));

        // Optional model metadata (Magnitu's `sync.py` may send it — preserved for
        // the Settings "current model" display; behaviour matches 0.4).
        $meta = $input['model_meta'] ?? null;
        if (is_array($meta)) {
            foreach (['model_name', 'model_description', 'model_version', 'model_trained_at'] as $k) {
                if (!empty($meta[$k])) {
                    $config->set($k, (string)$meta[$k]);
                }
            }
        }

        self::respondJson([
            'success'  => true,
            'inserted' => $inserted,
            'updated'  => $updated,
            'errors'   => $errors,
            'total'    => count($scores),
        ]);
    }

    // ------------------------------------------------------------------
    // GET + POST ?action=magnitu_recipe
    // ------------------------------------------------------------------

    public function recipe(): void
    {
        $pdo    = getDbConnection();
        $config = new SystemConfigRepository($pdo);
        if (!BearerAuth::guardMagnitu($config)) {
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $input = self::decodeJsonBody();
            if (!is_array($input) || !isset($input['keywords'])) {
                self::respondJson(['error' => 'Invalid recipe JSON'], 400);
                return;
            }
            $json = json_encode($input, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                self::respondJson(['error' => 'Recipe JSON could not be re-encoded'], 400);
                return;
            }
            $config->set('recipe_json', $json);
            $nextVersion = isset($input['version'])
                ? (string)(int)$input['version']
                : (string)(((int)$config->get('recipe_version')) + 1);
            $config->set('recipe_version', $nextVersion);
            $config->set('last_sync_at', gmdate('Y-m-d H:i:s'));

            $scorer = new ScoringService(new EntryScoreRepository($pdo));
            $counts = $scorer->rescoreAll($input);

            self::respondJson([
                'success'        => true,
                'recipe_version' => (int)$config->get('recipe_version'),
                'rescored'       => $counts,
            ]);
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        $recipe = $config->get('recipe_json');
        echo ($recipe !== null && $recipe !== '') ? $recipe : json_encode(null);
    }

    // ------------------------------------------------------------------
    // GET + POST ?action=magnitu_labels
    // ------------------------------------------------------------------

    public function labels(): void
    {
        $pdo = getDbConnection();
        $config = new SystemConfigRepository($pdo);
        if (!BearerAuth::guardMagnitu($config)) {
            return;
        }

        $repo = new MagnituLabelRepository($pdo);

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $input = self::decodeJsonBody();
            if (!is_array($input) || !isset($input['labels']) || !is_array($input['labels'])) {
                self::respondJson(['error' => 'Invalid JSON body, expected {labels: [...]}'], 400);
                return;
            }

            $inserted = 0;
            $updated  = 0;
            $errors   = 0;
            $now      = gmdate('Y-m-d H:i:s');

            foreach ($input['labels'] as $lbl) {
                if (!is_array($lbl)) {
                    continue;
                }
                $entryType = (string)($lbl['entry_type'] ?? '');
                $entryId   = (int)($lbl['entry_id']   ?? 0);
                $label     = (string)($lbl['label']      ?? '');
                if (
                    !in_array($entryType, MagnituLabelRepository::LABELED_ENTRY_TYPES, true)
                    || $entryId <= 0
                    || $label === ''
                ) {
                    continue;
                }
                try {
                    $result = $repo->upsert(
                        $entryType,
                        $entryId,
                        $label,
                        isset($lbl['reasoning']) ? (string)$lbl['reasoning'] : null,
                        (string)($lbl['labeled_at'] ?? $now),
                    );
                    $result === 'inserted' ? $inserted++ : $updated++;
                } catch (PDOException $e) {
                    $errors++;
                }
            }

            self::respondJson([
                'success'  => true,
                'inserted' => $inserted,
                'updated'  => $updated,
                'errors'   => $errors,
                'total'    => count($input['labels']),
            ]);
            return;
        }

        $labels = $repo->listAll();
        self::respondJson([
            'labels' => $labels,
            'total'  => count($labels),
        ]);
    }

    // ------------------------------------------------------------------
    // GET ?action=magnitu_status
    // ------------------------------------------------------------------

    public function status(): void
    {
        $pdo    = getDbConnection();
        $config = new SystemConfigRepository($pdo);
        if (!BearerAuth::guardMagnitu($config)) {
            return;
        }

        $export = new MagnituExportRepository($pdo);
        $scores = (new EntryScoreRepository($pdo))->getScoreCounts();
        $counts = $export->getEntryCounts();

        $body = [
            'status'  => 'ok',
            'version' => SEISMO_VERSION,
            'entries' => [
                'feed_items'       => $counts['feed_items'],
                'emails'           => $counts['emails'],
                'lex_items'        => $counts['lex_items'],
                'calendar_events'  => $counts['calendar_events'],
                'total'            => $counts['feed_items'] + $counts['emails'] + $counts['lex_items'] + $counts['calendar_events'],
            ],
            'scores' => [
                'total'   => $scores['total'],
                'magnitu' => $scores['magnitu'],
                'recipe'  => $scores['recipe'],
            ],
            'recipe_version'  => (int)$config->get('recipe_version'),
            'alert_threshold' => (float)$config->get('alert_threshold'),
            'last_sync_at'    => ($config->get('last_sync_at') !== null && $config->get('last_sync_at') !== '')
                ? $config->get('last_sync_at')
                : null,
        ];
        // Optional: satellite / branding (see bootstrap SEISMO_BRAND_ACCENT, seismo-generator).
        // Magnitu v3 reads this for profile tab colour — must match client contract in sync.py.
        $accent = seismoBrandAccent();
        if ($accent !== null && $accent !== '') {
            $body['accent_color'] = $accent;
        }

        self::respondJson($body);
    }

    // ------------------------------------------------------------------
    // Shaping helpers — produce the exact 0.4 JSON rows.
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function shapeFeedItem(array $row): array
    {
        return [
            'entry_type'      => 'feed_item',
            'entry_id'        => (int)$row['id'],
            'title'           => (string)($row['title'] ?? ''),
            'description'     => strip_tags((string)($row['description'] ?? '')),
            'content'         => strip_tags((string)($row['content'] ?? '')),
            'link'            => (string)($row['link'] ?? ''),
            'author'          => (string)($row['author'] ?? ''),
            'published_date'  => $row['published_date'] ?? null,
            'source_name'     => (string)($row['feed_title'] ?? ''),
            'source_category' => (string)($row['feed_category'] ?? ''),
            'source_type'     => (string)($row['source_type'] ?? 'rss'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function shapeEmail(array $row): array
    {
        $body = (string)($row['text_body'] ?? '');
        if ($body === '') {
            $body = strip_tags((string)($row['html_body'] ?? ''));
        }
        $subject = (string)($row['subject'] ?? '');
        if ($body !== '') {
            if (self::$emailSubscriptionListCache === null) {
                self::$emailSubscriptionListCache = (new EmailSubscriptionRepository(getDbConnection()))
                    ->listAll(EmailSubscriptionRepository::MAX_LIMIT, 0);
            }
            $ui = EmailSubscriptionRepository::resolveSubscriptionUiForFromEmail(
                (string)($row['from_email'] ?? ''),
                self::$emailSubscriptionListCache
            );
            if (!empty($ui['strip_listing_boilerplate'])) {
                $body = EmailListingBoilerplateStripper::strip(
                    $body,
                    $subject !== '' ? $subject : null
                );
            }
        }
        $fromName = (string)($row['from_name'] ?? '');
        $fromAddr = (string)($row['from_email'] ?? '');
        $display  = $fromName !== '' ? $fromName : $fromAddr;
        $description = mb_substr(trim((string)preg_replace('/\s+/', ' ', $body)), 0, 500);

        return [
            'entry_type'      => 'email',
            'entry_id'        => (int)$row['id'],
            'title'           => (string)($row['subject'] ?? '') !== ''
                                    ? (string)$row['subject']
                                    : '(No subject)',
            'description'     => $description,
            'content'         => $body,
            'link'            => '',
            'author'          => $display,
            'published_date'  => $row['entry_date'] ?? null,
            'source_name'     => $display,
            'source_category' => (string)($row['sender_tag'] ?? 'unclassified'),
            'source_type'     => 'email',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function shapeLexItem(array $row): array
    {
        $source = (string)($row['source'] ?? 'eu');
        $desc   = (string)($row['description'] ?? '');
        if ($desc === '') {
            $desc = trim((string)($row['document_type'] ?? '') . ' | ' . (string)($row['celex'] ?? ''), ' |');
        }
        return [
            'entry_type'      => 'lex_item',
            'entry_id'        => (int)$row['id'],
            'title'           => (string)($row['title'] ?? ''),
            'description'     => $desc,
            'content'         => (string)($row['description'] ?? '') !== ''
                                    ? (string)$row['description']
                                    : (string)($row['title'] ?? ''),
            'link'            => (string)($row['eurlex_url'] ?? ''),
            'author'          => '',
            'published_date'  => $row['document_date'] ?? null,
            'source_name'     => self::LEX_SOURCE_LABELS[$source] ?? 'EUR-Lex',
            'source_category' => (string)($row['document_type'] ?? 'Legislation'),
            'source_type'     => 'lex_' . $source,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function shapeCalendarEvent(array $row): array
    {
        $source = (string)($row['source'] ?? '');
        $desc   = trim((string)($row['description'] ?? ''));
        if ($desc === '') {
            $bits = array_filter([
                (string)($row['event_type'] ?? ''),
                (string)($row['status'] ?? ''),
                (string)($row['council'] ?? ''),
            ], static fn (string $s): bool => $s !== '');
            $desc = $bits !== [] ? implode(' · ', $bits) : '';
        }
        $body = trim((string)($row['content'] ?? ''));
        if ($body === '') {
            $body = strip_tags($desc !== '' ? $desc : (string)($row['title'] ?? ''));
        } else {
            $body = strip_tags($body);
        }

        $pub = $row['event_date'] ?? null;
        if ($pub !== null && $pub !== '') {
            $pub = (string)$pub;
        }

        $sourceName = $source === 'parliament_ch' ? 'Parliament CH' : ($source !== '' ? $source : 'Leg');

        return [
            'entry_type'      => 'calendar_event',
            'entry_id'        => (int)$row['id'],
            'title'           => (string)($row['title'] ?? ''),
            'description'     => $desc,
            'content'         => $body,
            'link'            => (string)($row['url'] ?? ''),
            'author'          => (string)($row['council'] ?? ''),
            'published_date'  => $pub,
            'source_name'     => $sourceName,
            'source_category' => (string)($row['event_type'] ?? 'Leg'),
            'source_type'     => 'leg_' . ($source !== '' ? preg_replace('/[^a-z0-9_]+/i', '_', $source) : 'parliament'),
        ];
    }

    // ------------------------------------------------------------------
    // Request / response helpers
    // ------------------------------------------------------------------

    private static function stringParam(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            return null;
        }
        $v = trim($value);
        return $v === '' ? null : $v;
    }

    private static function clampInt(mixed $value, int $min, int $max): int
    {
        $n = (int)$value;
        if ($n < $min) {
            return $min;
        }
        if ($n > $max) {
            return $max;
        }
        return $n;
    }

    /**
     * @return mixed
     */
    private static function decodeJsonBody(): mixed
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return null;
        }
        return json_decode($raw, true);
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $payload
     */
    private static function respondJson(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        // Bearer-authenticated responses must never be cached by shared
        // intermediaries; key rotation should invalidate immediately.
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
