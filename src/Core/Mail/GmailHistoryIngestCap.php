<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Caps Gmail history ingest to a batch size without skipping history records.
 *
 * Messages are taken oldest-first (history record order). When the cap bites,
 * {@see checkpoint_history_id} is the last fully consumed history record id so
 * the stored cursor can advance without dropping unprocessed messageAdded events.
 */
final class GmailHistoryIngestCap
{
    /**
     * @param list<string>          $recordHistoryIds     History record id per batch slice (same order as API).
     * @param list<list<string>>    $messageIdsPerRecord  Gmail message ids added in each record.
     * @return array{
     *   message_ids: list<string>,
     *   checkpoint_history_id: ?string,
     *   truncated: bool
     * }
     */
    public static function collect(array $recordHistoryIds, array $messageIdsPerRecord, int $max): array
    {
        if ($max < 1) {
            return ['message_ids' => [], 'checkpoint_history_id' => null, 'truncated' => false];
        }

        $ids        = [];
        $checkpoint = null;
        $truncated  = false;
        $nRecords   = count($messageIdsPerRecord);

        for ($i = 0; $i < $nRecords; $i++) {
            $recordMsgs = $messageIdsPerRecord[$i];
            $recordId   = $recordHistoryIds[$i] ?? '';
            $remaining  = $max - count($ids);

            if ($remaining <= 0) {
                $truncated = true;
                break;
            }

            if ($recordMsgs === []) {
                if ($recordId !== '') {
                    $checkpoint = $recordId;
                }
                continue;
            }

            if (count($recordMsgs) <= $remaining) {
                foreach ($recordMsgs as $mid) {
                    if ($mid !== '') {
                        $ids[] = $mid;
                    }
                }
                if ($recordId !== '') {
                    $checkpoint = $recordId;
                }
                continue;
            }

            foreach (array_slice($recordMsgs, 0, $remaining) as $mid) {
                if ($mid !== '') {
                    $ids[] = $mid;
                }
            }
            $truncated = true;
            break;
        }

        return [
            'message_ids'           => $ids,
            'checkpoint_history_id' => $checkpoint,
            'truncated'             => $truncated,
        ];
    }
}
