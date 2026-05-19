<?php

declare(strict_types=1);

namespace Seismo\Plugin\ParlCh;

use Seismo\Plugin\PluginLanguage;
use Seismo\Service\Http\BaseClient;
use Seismo\Service\Http\HttpClientException;
use Seismo\Service\Http\Response;
use Seismo\Service\SourceFetcherInterface;

/**
 * Swiss Parliament OData (ws.parlament.ch). Ports
 * 0.4 `refreshParliamentChEvents` + `refreshParliamentChSessions`.
 *
 * Fetches two families and returns a single flat list of normalised rows
 * (the runner batches them into calendar_events via CalendarEventRepository):
 *   1. Business items (motions, interpellations, postulates, initiatives)
 *   2. Session periods (upcoming parliamentary sessions)
 *
 * Rows without a stable external id or a non-empty title are dropped
 * (normalisation contract).
 */
final class ParlChPlugin implements SourceFetcherInterface
{
    /**
     * OData-supported language codes. Used to reject arbitrary user input
     * before it lands in the `$filter=Language eq '…'` clause — OData 2.0
     * lacks parameterised queries, so the whitelist IS the defence.
     *
     * @deprecated Use {@see PluginLanguage::PARL_CH} — kept for grep/docs parity.
     */
    public const LANGUAGE_CODES = PluginLanguage::PARL_CH;

    private const DEFAULT_API_BASE       = 'https://ws.parlament.ch/odata.svc';
    private const DEFAULT_LANGUAGE       = 'DE';
    private const DEFAULT_LOOKFORWARD    = 90;
    private const DEFAULT_LOOKBACK       = 28;
    private const DEFAULT_BUSINESS_LIMIT = 200;
    private const MAX_BUSINESS_LIMIT     = 500;
    private const SESSION_LIMIT          = 20;

    public function __construct(private readonly BaseClient $http = new BaseClient())
    {
    }

    public function getIdentifier(): string
    {
        return 'parl_ch';
    }

    public function getLabel(): string
    {
        return 'Parlament CH';
    }

    public function getEntryType(): string
    {
        return 'calendar_event';
    }

    public function getConfigKey(): string
    {
        return 'parliament_ch';
    }

    /**
     * 4 hours. Swiss Parliament OData refreshes nightly during sessions and
     * once a day otherwise; 4h matches Fedlex without hammering the API when
     * the master cron runs every 5 minutes.
     */
    public function getMinIntervalSeconds(): int
    {
        return 4 * 60 * 60;
    }

    public static function normalizeLanguage(string $raw): string
    {
        return PluginLanguage::parlCh($raw);
    }

    public function fetch(array $config): array
    {
        $apiBase = rtrim((string)($config['api_base'] ?? self::DEFAULT_API_BASE), '/');
        $lang = self::normalizeLanguage((string)($config['language'] ?? self::DEFAULT_LANGUAGE));
        $lookforward = max(1, min(365, (int)($config['lookforward_days'] ?? self::DEFAULT_LOOKFORWARD)));
        $lookback = max(1, min(90, (int)($config['lookback_days'] ?? self::DEFAULT_LOOKBACK)));
        $limit = max(1, min((int)($config['limit'] ?? self::DEFAULT_BUSINESS_LIMIT), self::MAX_BUSINESS_LIMIT));

        $businessTypes = is_array($config['business_types'] ?? null) ? $config['business_types'] : [];

        $rows = $this->fetchBusiness($apiBase, $lang, $lookback, $limit, $businessTypes);
        $rows = array_merge($rows, $this->fetchSessions($apiBase, $lang, $lookforward));

        return $rows;
    }

    /**
     * @param array<int|string, string> $businessTypes
     * @return list<array<string, mixed>>
     */
    private function fetchBusiness(string $apiBase, string $lang, int $lookback, int $limit, array $businessTypes): array
    {
        $sinceDate = gmdate('Y-m-d', strtotime('-' . $lookback . ' days'));
        $filter = "Language eq '{$lang}' and Modified ge datetime'{$sinceDate}T00:00:00'";
        // Curia Vista highlights “new” dossiers by catalogue updates; those often
        // surface as Modified more reliably than SubmissionDate ordering. Using
        // Modified desc keeps recently touched business in the bounded $top window.
        $url = $apiBase . '/Business'
            . '?$filter=' . rawurlencode($filter)
            . '&$orderby=' . rawurlencode('Modified desc')
            . '&$top=' . $limit
            . '&$format=json';

        $response = $this->get($url);
        if ($response->status >= 400) {
            throw new \RuntimeException('parlament.ch /Business returned HTTP ' . $response->status);
        }

        $results = $this->decodeODataResults($response);

        $out = [];
        foreach ($results as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = $item['ID'] ?? $item['Id'] ?? null;
            if ($id === null || $id === '') {
                continue;
            }
            $externalId = (string)$id;

            $title = trim((string)($item['Title'] ?? $item['BusinessShortNumber'] ?? ''));
            if ($title === '') {
                continue;
            }

            $rawDesc = (string)($item['InitialSituation'] ?? $item['Description'] ?? '');
            $description = trim(strip_tags($rawDesc));
            $rawContent = (string)($item['SubmittedText'] ?? $item['MotionText'] ?? $item['ReasonText'] ?? $rawDesc);
            $content = trim(strip_tags($rawContent));

            $eventDate = $this->parseODataDate($item['SubmissionDate'] ?? null);
            $businessTypeId = $item['BusinessType'] ?? $item['BusinessTypeId'] ?? null;
            $eventType = (string)($businessTypes[$businessTypeId] ?? ($item['BusinessTypeName'] ?? 'Geschaeft'));

            $statusId = $item['BusinessStatus'] ?? $item['BusinessStatusId'] ?? null;
            $statusText = (string)($item['BusinessStatusText'] ?? '');
            $status = $this->mapStatus($statusText);

            $councilId = $item['SubmissionCouncil'] ?? $item['SubmissionCouncilId'] ?? $item['FirstCouncil1'] ?? null;
            $council = $this->councilCode($councilId);

            $itemUrl = 'https://www.parlament.ch/de/ratsbetrieb/suche-curia-vista/geschaeft?AffairId=' . rawurlencode($externalId);

            $out[] = [
                'source'         => 'parliament_ch',
                'external_id'    => $externalId,
                'title'          => mb_substr($title, 0, 65535),
                'description'    => mb_substr($description, 0, 65535),
                'content'        => mb_substr($content, 0, 65535),
                'event_date'     => $eventDate,
                'event_end_date' => null,
                'event_type'     => $eventType,
                'status'         => $status,
                'council'        => $council,
                'url'            => $itemUrl,
                'metadata'       => [
                    'business_number'        => $item['BusinessShortNumber'] ?? null,
                    'business_type_id'       => $businessTypeId,
                    'status_id'              => $statusId,
                    'status_text'            => $statusText,
                    'submission_council_id'  => $councilId,
                    'author'                 => $item['SubmittedBy'] ?? null,
                    'responsible_department' => $item['TagNames'] ?? null,
                ],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchSessions(string $apiBase, string $lang, int $lookforward): array
    {
        unset($lookforward); // 0.4 parity: /Session only filters on EndDate >= today.
        $today = gmdate('Y-m-d');
        $filter = "Language eq '{$lang}' and EndDate ge datetime'{$today}T00:00:00'";
        $url = $apiBase . '/Session'
            . '?$filter=' . rawurlencode($filter)
            . '&$orderby=' . rawurlencode('StartDate asc')
            . '&$top=' . self::SESSION_LIMIT
            . '&$format=json';

        try {
            $response = $this->get($url);
        } catch (HttpClientException $e) {
            return [];
        }
        if ($response->status >= 400) {
            return [];
        }

        $results = $this->decodeODataResults($response);

        $out = [];
        foreach ($results as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = $item['ID'] ?? $item['Id'] ?? null;
            if ($id === null || $id === '') {
                continue;
            }
            $externalId = 'session_' . (string)$id;

            $title = trim((string)($item['SessionName'] ?? $item['Title'] ?? 'Session'));
            if ($title === '') {
                continue;
            }
            $description = trim((string)($item['Description'] ?? ''));
            $startDate = $this->parseODataDate($item['StartDate'] ?? null);
            $endDate = $this->parseODataDate($item['EndDate'] ?? null);

            $councilId = $item['Council'] ?? $item['CouncilId'] ?? null;
            $council = $this->councilCode($councilId);

            $out[] = [
                'source'         => 'parliament_ch',
                'external_id'    => $externalId,
                'title'          => $title,
                'description'    => $description,
                'content'        => $title,
                'event_date'     => $startDate,
                'event_end_date' => $endDate,
                'event_type'     => 'session',
                'status'         => 'scheduled',
                'council'        => $council,
                'url'            => 'https://www.parlament.ch/de/ratsbetrieb/sessionen',
                'metadata'       => [
                    'council_id'   => $councilId,
                    'session_type' => $item['Type'] ?? null,
                ],
            ];
        }

        return $out;
    }

    private function get(string $url): Response
    {
        return $this->http->get($url, ['Accept' => 'application/json']);
    }

    /**
     * @return list<mixed>
     */
    private function decodeODataResults(Response $response): array
    {
        try {
            /** @var array<string, mixed> $data */
            $data = $response->json();
        } catch (\JsonException $e) {
            return [];
        }

        /** @var mixed $inner */
        $inner = $data['d'] ?? $data['value'] ?? [];
        if (is_array($inner) && array_key_exists('results', $inner) && is_array($inner['results'])) {
            /** @var list<mixed> */
            return array_values($inner['results']);
        }
        if (is_array($inner)) {
            /** @var list<mixed> */
            return array_values($inner);
        }

        return [];
    }

    private function parseODataDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && preg_match('#/Date\((\d+)(?:[+-]\d+)?\)/#', $value, $m)) {
            return gmdate('Y-m-d', (int)round((int)$m[1] / 1000));
        }
        if (is_string($value)) {
            $ts = strtotime($value);
            if ($ts !== false) {
                return gmdate('Y-m-d', $ts);
            }
        }

        return null;
    }

    private function mapStatus(string $statusText): string
    {
        $lower = mb_strtolower($statusText);
        if (str_contains($lower, 'erledigt') || str_contains($lower, 'abgeschlossen')) {
            return 'completed';
        }
        if (str_contains($lower, 'zurückgezogen')) {
            return 'cancelled';
        }
        if (str_contains($lower, 'hängig') || str_contains($lower, 'im rat')) {
            return 'scheduled';
        }
        if (str_contains($lower, 'verschoben')) {
            return 'postponed';
        }

        return 'scheduled';
    }

    private function councilCode(mixed $councilId): ?string
    {
        return match ((int)($councilId ?? 0)) {
            1       => 'NR',
            2       => 'SR',
            default => null,
        };
    }
}
