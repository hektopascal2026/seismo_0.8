<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Result of {@see GmailApiInboxClient::fetch()} — rows plus fetch/cursor metadata for CoreRunner.
 */
final class GmailFetchOutcome
{
    /**
     * @param list<array<string, mixed>> $rows Normalised rows for {@see \Seismo\Repository\EmailIngestRepository::upsertGmailBatch()}.
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $fetchFailures,
        public readonly bool $historyCursorAdvanced,
    ) {
    }
}
