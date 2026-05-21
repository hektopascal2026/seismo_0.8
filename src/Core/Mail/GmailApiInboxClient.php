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

        $messageIds = [];
        if ($storedHistory === '') {
            $messageIds = $this->bootstrapMessageIds($gmail, $max);
        } else {
            $messageIds = $this->historyMessageIds($gmail, $storedHistory, $max);
        }

        $messageIds = array_values(array_unique(array_filter($messageIds, static fn (string $id) => $id !== '')));
        if (count($messageIds) > $max) {
            $dropped = count($messageIds) - $max;
            error_log('[seismo] Gmail fetch: cap ' . $max . ' — ' . $dropped . ' message id(s) deferred to next run.');
            $messageIds = array_slice($messageIds, -$max);
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

        if ($currentHistory !== '') {
            $this->config->set(MailConfigKeys::GMAIL_HISTORY_ID, $currentHistory);
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
     * @return list<string>
     */
    private function historyMessageIds(Gmail $gmail, string $startHistoryId, int $max): array
    {
        $ids       = [];
        $pageToken = null;

        try {
            do {
                $params = [
                    'startHistoryId' => $startHistoryId,
                    'historyTypes'   => ['messageAdded'],
                    'maxResults'     => min(500, $max),
                ];
                if ($pageToken !== null) {
                    $params['pageToken'] = $pageToken;
                }
                $resp = $gmail->users_history->listUsersHistory('me', $params);
                foreach ($resp->getHistory() ?? [] as $record) {
                    foreach ($record->getMessagesAdded() ?? [] as $added) {
                        $msg = $added->getMessage();
                        if ($msg !== null) {
                            $id = (string)($msg->getId() ?? '');
                            if ($id !== '') {
                                $ids[] = $id;
                            }
                        }
                    }
                }
                $pageToken = $resp->getNextPageToken();
            } while ($pageToken !== null && count($ids) < $max);
        } catch (GoogleServiceException $e) {
            // Expired or invalid startHistoryId (Gmail retains history for a limited time).
            error_log(
                'Seismo Gmail history expired or invalid: ' . $e->getMessage() . ' — falling back to bootstrap.'
            );

            return $this->bootstrapMessageIds($gmail, $max);
        }

        if ($ids === []) {
            return $this->bootstrapMessageIds($gmail, $max);
        }

        return $ids;
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
