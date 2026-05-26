<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Seismo\Repository\SystemConfigRepository;

/**
 * Incremental Gmail ingest via API history cursor (Slice 11).
 *
 * No SQL; returns normalised rows for {@see \Seismo\Repository\EmailIngestRepository::upsertGmailBatch()}.
 */
final class GmailApiInboxClient
{
    private const DEFAULT_MAX = 50;
    private const MAX_CAP     = 500;
    private const DEFAULT_CATCHUP_DAYS = 7;

    /** Pause between per-message `users.messages.get` calls (Gmail user quota). */
    private const MESSAGE_FETCH_DELAY_US = 100_000;

    public function __construct(
        private GmailOAuthService $oauth,
        private SystemConfigRepository $config,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetch(bool $catchUp = false): array
    {
        $gmail = $this->oauth->createAuthorizedGmailService();
        $max   = $this->maxMessages();

        if ($catchUp) {
            $this->config->delete(MailConfigKeys::GMAIL_HISTORY_ID);
        }

        $storedHistory = trim((string)($this->config->get(MailConfigKeys::GMAIL_HISTORY_ID) ?? ''));
        $profile       = $gmail->users->getProfile('me');
        $currentHistory = (string)($profile->getHistoryId() ?? '');

        $messageIds        = [];
        $historyAdvanceId  = $currentHistory;
        if ($storedHistory === '') {
            $messageIds = $this->bootstrapMessageIds($gmail, $max);
        } else {
            $batch        = $this->historyMessageBatch($gmail, $storedHistory, $max);
            $messageIds   = $batch['message_ids'];
            if ($batch['truncated']) {
                $historyAdvanceId = $batch['checkpoint_history_id'] ?? $storedHistory;
            }
        }

        $messageIds = array_values(array_unique(array_filter($messageIds, static fn (string $id) => $id !== '')));
        if (count($messageIds) > $max) {
            $dropped = count($messageIds) - $max;
            error_log(
                '[seismo] Gmail fetch: safety cap ' . $max . ' — ' . $dropped
                . ' message id(s) remain for a later run (history cursor not advanced past backlog).'
            );
            $messageIds = array_slice($messageIds, 0, $max);
        }

        $rows = [];
        foreach ($messageIds as $i => $id) {
            if ($i > 0) {
                usleep(self::MESSAGE_FETCH_DELAY_US);
            }
            try {
                $msg = $gmail->users_messages->get('me', $id, ['format' => 'full']);
                $rows[] = GmailMessageParser::toIngestRow($msg);
            } catch (GoogleServiceException $e) {
                if ($this->isRateLimitError($e)) {
                    throw $e;
                }
                error_log('Seismo Gmail message ' . $id . ': ' . $e->getMessage());
            } catch (\Throwable $e) {
                error_log('Seismo Gmail message ' . $id . ': ' . $e->getMessage());
            }
        }

        if ($historyAdvanceId !== '') {
            $this->config->set(MailConfigKeys::GMAIL_HISTORY_ID, $historyAdvanceId);
        }
        $this->config->set(MailConfigKeys::GMAIL_LAST_SYNC_AT, gmdate('Y-m-d H:i:s'));

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function bootstrapMessageIds(Gmail $gmail, int $max): array
    {
        $days = (int)($this->config->get(MailConfigKeys::GMAIL_CATCHUP_DAYS) ?? '');
        if ($days < 1) {
            $days = self::DEFAULT_CATCHUP_DAYS;
        }
        if ($days > 30) {
            $days = 30;
        }
        $after = gmdate('Y/m/d', strtotime('-' . $days . ' days'));
        $q     = 'in:inbox after:' . $after;

        return $this->listMessageIds($gmail, $q, $max);
    }

    /**
     * @return array{
     *   message_ids: list<string>,
     *   checkpoint_history_id: ?string,
     *   truncated: bool
     * }
     */
    private function historyMessageBatch(Gmail $gmail, string $startHistoryId, int $max): array
    {
        $recordHistoryIds    = [];
        $messageIdsPerRecord = [];
        $pageToken           = null;

        try {
            do {
                $params = [
                    'startHistoryId' => $startHistoryId,
                    'historyTypes'   => ['messageAdded'],
                    'maxResults'     => 500,
                ];
                if ($pageToken !== null) {
                    $params['pageToken'] = $pageToken;
                }
                $resp = $gmail->users_history->listUsersHistory('me', $params);
                foreach ($resp->getHistory() ?? [] as $record) {
                    $recordHistoryIds[] = (string)($record->getId() ?? '');
                    $recordMsgs         = [];
                    foreach ($record->getMessagesAdded() ?? [] as $added) {
                        $msg = $added->getMessage();
                        if ($msg !== null) {
                            $id = (string)($msg->getId() ?? '');
                            if ($id !== '') {
                                $recordMsgs[] = $id;
                            }
                        }
                    }
                    $messageIdsPerRecord[] = $recordMsgs;
                }
                $pageToken = $resp->getNextPageToken();
                $batch     = GmailHistoryIngestCap::collect($recordHistoryIds, $messageIdsPerRecord, $max);
                if ($batch['truncated'] || ($pageToken !== null && count($batch['message_ids']) >= $max)) {
                    return [
                        'message_ids'           => $batch['message_ids'],
                        'checkpoint_history_id' => $batch['checkpoint_history_id'],
                        'truncated'             => true,
                    ];
                }
            } while ($pageToken !== null);
        } catch (GoogleServiceException $e) {
            // Expired or invalid startHistoryId (Gmail retains history for a limited time).
            error_log(
                'Seismo Gmail history expired or invalid: ' . $e->getMessage() . ' — falling back to bootstrap.'
            );

            return [
                'message_ids'           => $this->bootstrapMessageIds($gmail, $max),
                'checkpoint_history_id' => null,
                'truncated'             => false,
            ];
        }

        if ($messageIdsPerRecord === []) {
            return [
                'message_ids'           => $this->bootstrapMessageIds($gmail, $max),
                'checkpoint_history_id' => null,
                'truncated'             => false,
            ];
        }

        $batch = GmailHistoryIngestCap::collect($recordHistoryIds, $messageIdsPerRecord, $max);

        return [
            'message_ids'           => $batch['message_ids'],
            'checkpoint_history_id' => $batch['checkpoint_history_id'],
            'truncated'             => $batch['truncated'],
        ];
    }

    /**
     * @return list<string>
     */
    private function listMessageIds(Gmail $gmail, string $query, int $max): array
    {
        $ids       = [];
        $pageToken = null;
        while (count($ids) < $max) {
            $params = [
                'q'          => $query,
                'maxResults' => min(100, $max - count($ids)),
            ];
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }
            $list = $gmail->users_messages->listUsersMessages('me', $params);
            foreach ($list->getMessages() ?? [] as $ref) {
                $id = (string)($ref->getId() ?? '');
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
            $pageToken = $list->getNextPageToken();
            if ($pageToken === null) {
                break;
            }
        }

        return $ids;
    }

    private function maxMessages(): int
    {
        $max = (int)($this->config->get(MailConfigKeys::MAX_MESSAGES) ?? self::DEFAULT_MAX);
        if ($max < 1) {
            $max = self::DEFAULT_MAX;
        }

        return min($max, self::MAX_CAP);
    }

    private function isRateLimitError(GoogleServiceException $e): bool
    {
        if ($e->getCode() === 429) {
            return true;
        }
        $errors = $e->getErrors();
        if (!is_array($errors)) {
            return false;
        }
        foreach ($errors as $err) {
            if (!is_array($err)) {
                continue;
            }
            if (($err['reason'] ?? '') === 'rateLimitExceeded') {
                return true;
            }
        }

        return false;
    }
}
