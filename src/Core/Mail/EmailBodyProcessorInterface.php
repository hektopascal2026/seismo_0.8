<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Per-subscription ingest helper: normalize plain body and optional derived title.
 *
 * @phpstan-type EmailRow array<string, mixed>
 */
interface EmailBodyProcessorInterface
{
    /**
     * @param EmailRow $row Ingest row with subject, text_body/body_text, optional html_body.
     * @return EmailRow
     */
    public function process(array $row): array;
}
