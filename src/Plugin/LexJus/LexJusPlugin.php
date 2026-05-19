<?php

declare(strict_types=1);

namespace Seismo\Plugin\LexJus;

use Seismo\Service\Http\BaseClient;
use Seismo\Service\Http\HttpClientException;
use Seismo\Service\SourceFetcherInterface;

/**
 * Swiss case law (Jus) via entscheidsuche.ch JSON index + decision documents.
 *
 * Ported from 0.4 `controllers/lex_jus.php::refreshJusItems()` into a plugin:
 * - Uses the incremental index manifest at `/docs/Index/<Spider>/last`.
 * - If the manifest is empty, bootstraps from a recent complete Index_*.json
 *   discovered via the directory listing.
 * - Fetches decision JSONs under `/docs/<filePath>` and normalises to `lex_items`.
 */
final class LexJusPlugin implements SourceFetcherInterface
{
    private const DEFAULT_BASE_URL = 'https://entscheidsuche.ch';
    private const MAX_LIMIT = 500;
    private const FETCH_BATCH_SIZE = 10;

    /**
     * @param non-empty-string $identifier Plugin id (runner/diagnostics), e.g. "jus_bger".
     * @param non-empty-string $label      Human label, e.g. "Jus: BGer".
     * @param non-empty-string $configKey  LexConfigStore key, e.g. "ch_bger".
     * @param non-empty-string $spider     entscheidsuche spider, e.g. "CH_BGer".
     */
    public function __construct(
        private readonly string $identifier,
        private readonly string $label,
        private readonly string $configKey,
        private readonly string $spider,
        private readonly BaseClient $http = new BaseClient(),
    ) {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getEntryType(): string
    {
        return 'lex_item';
    }

    public function getConfigKey(): string
    {
        return $this->configKey;
    }

    /**
     * 6 hours. Case law updates are not minute-level; keep upstream load low.
     */
    public function getMinIntervalSeconds(): int
    {
        return 6 * 60 * 60;
    }

    public function fetch(array $config): array
    {
        $baseUrl = rtrim((string)($config['base_url'] ?? self::DEFAULT_BASE_URL), '/');
        if ($baseUrl === '') {
            $baseUrl = self::DEFAULT_BASE_URL;
        }
        $lookback = max(1, min(365, (int)($config['lookback_days'] ?? 90)));
        $limit = max(1, min(self::MAX_LIMIT, (int)($config['limit'] ?? 100)));
        $cutoffDate = gmdate('Y-m-d', strtotime('-' . $lookback . ' days'));

        $banned = [];
        if (isset($config['jus_banned_words']) && is_array($config['jus_banned_words'])) {
            foreach ($config['jus_banned_words'] as $w) {
                if (is_string($w)) {
                    $w = mb_strtolower(trim($w));
                    if ($w !== '') {
                        $banned[] = $w;
                    }
                }
            }
        }

        $actions = $this->fetchActions($baseUrl);
        if ($actions === []) {
            return [];
        }

        $files = $this->selectFilesToFetch($actions, $cutoffDate, $limit);
        if ($files === []) {
            // Fallback: if cutoff filters everything (common on first sync),
            // take the newest items regardless of date.
            $files = $this->selectFilesToFetch($actions, null, $limit);
        }
        if ($files === []) {
            return [];
        }

        $rows = [];
        foreach (array_chunk($files, self::FETCH_BATCH_SIZE) as $batch) {
            foreach ($batch as $filePath) {
                $decision = $this->fetchDecision($baseUrl, $filePath);
                if (!is_array($decision)) {
                    continue;
                }

                $slug = pathinfo(basename($filePath), PATHINFO_FILENAME);
                if (!is_string($slug) || $slug === '') {
                    continue;
                }

                $datum = $decision['Datum'] ?? null;
                $docDate = is_string($datum) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) ? $datum : null;
                if ($docDate !== null && $docDate < $cutoffDate) {
                    // Accurate cutoff (JSON field beats filename parsing).
                    continue;
                }

                $title = $this->decisionTitle($decision, $slug);
                if ($title === '') {
                    continue;
                }
                if ($this->isBannedTitle($title, $banned)) {
                    continue;
                }

                $signatur = is_string($decision['Signatur'] ?? null) ? (string)$decision['Signatur'] : '';
                $docType  = self::jusChamberLabel($signatur);

                $eurlexUrl = '';
                $htmlUrl = $decision['HTML']['URL'] ?? null;
                if (is_string($htmlUrl) && trim($htmlUrl) !== '') {
                    $eurlexUrl = trim($htmlUrl);
                } else {
                    $eurlexUrl = $baseUrl . '/view/' . rawurlencode($slug);
                }

                $workUri = $baseUrl . '/docs/' . ltrim($filePath, '/');

                $rows[] = [
                    'celex'         => $slug,
                    'title'         => mb_substr($title, 0, 65535),
                    'description'   => null,
                    'document_date' => $docDate,
                    'document_type' => mb_substr($docType, 0, 100),
                    'eurlex_url'    => mb_substr($eurlexUrl, 0, 500),
                    'work_uri'      => mb_substr($workUri, 0, 500),
                    'source'        => $this->configKey,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, string> filePath => action
     */
    private function fetchActions(string $baseUrl): array
    {
        $indexUrl = $baseUrl . '/docs/Index/' . rawurlencode($this->spider) . '/last';
        $index = $this->getJson($indexUrl, 30);
        if (!is_array($index)) {
            throw new \RuntimeException('Failed to fetch index manifest: ' . $indexUrl);
        }

        $actions = $index['actions'] ?? null;
        if (is_array($actions) && $actions !== []) {
            /** @var array<string, string> */
            return array_map('strval', $actions);
        }

        // Bootstrap from a recent complete manifest discovered via directory listing.
        return $this->fetchBootstrapActions($baseUrl);
    }

    /**
     * @return array<string, string>
     */
    private function fetchBootstrapActions(string $baseUrl): array
    {
        $dirUrl = $baseUrl . '/docs/Index/' . rawurlencode($this->spider) . '/';
        $resp = $this->http->getWebPage($dirUrl);
        if (!$resp->isOk() || $resp->body === '') {
            return [];
        }
        $html = $resp->body;

        // Prefer a recent "komplett" manifest if present; otherwise use newest non-empty.
        $pattern = '#/docs/Index/' . preg_quote($this->spider, '#') . '/(Index_[^"\\s]+\\.json)#';
        if (!preg_match_all($pattern, $html, $m) || empty($m[1])) {
            return [];
        }
        $files = array_values(array_unique(array_map('rawurldecode', $m[1])));
        if ($files === []) {
            return [];
        }

        $fallback = [];
        $checked = 0;
        for ($i = count($files) - 1; $i >= 0 && $checked < 30; $i--, $checked++) {
            $file = $files[$i];
            $manifest = $this->getJson($dirUrl . rawurlencode($file), 30);
            if (!is_array($manifest) || empty($manifest['actions']) || !is_array($manifest['actions'])) {
                continue;
            }
            if ($fallback === []) {
                /** @var array<string, string> */
                $fallback = array_map('strval', $manifest['actions']);
            }
            $jobtyp = strtolower((string)($manifest['jobtyp'] ?? ''));
            if ($jobtyp === 'komplett') {
                /** @var array<string, string> */
                return array_map('strval', $manifest['actions']);
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, string> $actions
     * @return list<string>
     */
    private function selectFilesToFetch(array $actions, ?string $cutoffDate, int $limit): array
    {
        $out = [];
        foreach ($actions as $filePath => $action) {
            if ($action === 'delete') {
                continue;
            }
            if (!is_string($filePath) || $filePath === '') {
                continue;
            }

            if ($cutoffDate !== null) {
                if (preg_match('/_(\d{4}-\d{2}-\d{2})\.json$/', $filePath, $m)) {
                    if ($m[1] < $cutoffDate) {
                        continue;
                    }
                }
            }

            $out[] = $filePath;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchDecision(string $baseUrl, string $filePath): ?array
    {
        $url = $baseUrl . '/docs/' . ltrim($filePath, '/');
        $data = $this->getJson($url, 15);
        return is_array($data) ? $data : null;
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function getJson(string $url, int $timeout): ?array
    {
        try {
            $resp = new BaseClient($timeout);
            $r = $resp->get($url, ['Accept' => 'application/json']);
        } catch (HttpClientException) {
            return null;
        }
        if (!$r->isOk() || $r->body === '') {
            return null;
        }
        try {
            return $r->json();
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $decision
     */
    private function decisionTitle(array $decision, string $fallbackSlug): string
    {
        $abstract = '';
        $abs0 = $decision['Abstract'][0]['Text'] ?? null;
        if (is_string($abs0)) {
            $abstract = trim($abs0);
        }

        $caseNum = '';
        $nums = $decision['Num'] ?? null;
        if (is_array($nums)) {
            $n0 = $nums[0] ?? null;
            if (is_string($n0)) {
                $caseNum = trim($n0);
            }
        } elseif (is_string($nums)) {
            $caseNum = trim($nums);
        }

        $kopf = '';
        $k0 = $decision['Kopfzeile'][0]['Text'] ?? null;
        if (is_string($k0)) {
            $kopf = trim($k0);
        }

        if ($abstract !== '' && $caseNum !== '') {
            return $caseNum . ' — ' . $abstract;
        }
        if ($abstract !== '') {
            return $abstract;
        }
        if ($caseNum !== '') {
            return $caseNum;
        }
        if ($kopf !== '') {
            return $kopf;
        }

        return $fallbackSlug;
    }

    /**
     * @param list<string> $bannedLower
     */
    private function isBannedTitle(string $title, array $bannedLower): bool
    {
        if ($bannedLower === []) {
            return false;
        }
        $t = mb_strtolower($title);
        foreach ($bannedLower as $w) {
            if ($w !== '' && mb_strpos($t, $w) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function jusChamberLabel(string $signatur): string
    {
        $signatur = trim($signatur);
        if ($signatur === '') {
            return '';
        }
        static $map = [
            'CH_BGer_001' => 'I. öffentlich-rechtliche Abteilung',
            'CH_BGer_002' => 'II. öffentlich-rechtliche Abteilung',
            'CH_BGer_004' => 'I. zivilrechtliche Abteilung',
            'CH_BGer_005' => 'II. zivilrechtliche Abteilung',
            'CH_BGer_006' => 'Strafrechtliche Abteilung',
            'CH_BGer_007' => 'Beschwerdekammer Strafrecht',
            'CH_BGer_008' => 'I. sozialrechtliche Abteilung',
            'CH_BGer_009' => 'II. sozialrechtliche Abteilung',
            'CH_BGer_012' => 'Vereinigte Abteilungen',
            'CH_BGE_001'  => 'I. öffentlich-rechtliche Abteilung',
            'CH_BGE_002'  => 'II. öffentlich-rechtliche Abteilung',
            'CH_BGE_004'  => 'I. zivilrechtliche Abteilung',
            'CH_BGE_005'  => 'II. zivilrechtliche Abteilung',
            'CH_BGE_006'  => 'Strafrechtliche Abteilung',
            'CH_BGE_007'  => 'Beschwerdekammer Strafrecht',
            'CH_BGE_008'  => 'I. sozialrechtliche Abteilung',
            'CH_BGE_009'  => 'II. sozialrechtliche Abteilung',
            'CH_BGE_012'  => 'Vereinigte Abteilungen',
            'CH_BGE_999'  => 'Nicht publiziert',
            'CH_BVGE_001' => 'Bundesverwaltungsgericht',
        ];
        return $map[$signatur] ?? $signatur;
    }
}

