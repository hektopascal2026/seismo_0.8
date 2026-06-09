<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use Google\Service\Gmail\Message;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Header\DateHeader;

/**
 * Normalise a Gmail API {@see Message} into the ingest row shape.
 */
final class GmailMessageParser
{
    /**
     * @return array<string, mixed>
     */
    public static function toIngestRow(Message $message): array
    {
        $gmailId = (string)($message->getId() ?? '');

        $rawBase64Url = $message->getRaw();
        if ($rawBase64Url === null || $rawBase64Url === '') {
            throw new \RuntimeException("Gmail message $gmailId did not contain raw payload. Ensure 'format' => 'raw' is used.");
        }

        // Apply base64url decoding with proper padding calculation
        $data = strtr($rawBase64Url, '-_', '+/');
        $pad  = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $rawMime = base64_decode($data, true);
        if ($rawMime === false) {
            $rawMime = '';
        }

        $parser = new MailMimeParser();
        $mimeMessage = $parser->parse($rawMime, true);

        $subject = $mimeMessage->getHeaderValue('Subject') ?? '';
        $subject = self::truncate($subject, 500);

        $fromEmail = null;
        $fromName = null;
        $fromHeader = $mimeMessage->getHeader('From');
        if ($fromHeader !== null) {
            $addresses = $fromHeader->getAddresses();
            if (!empty($addresses)) {
                $fromEmail = $addresses[0]->getEmail();
                $fromName = $addresses[0]->getName();
            }
        }
        $fromRaw = $mimeMessage->getHeaderValue('From') ?? '';

        $to = $mimeMessage->getHeaderValue('To');
        $cc = $mimeMessage->getHeaderValue('Cc');

        $dateUtc = null;
        $dateHeader = $mimeMessage->getHeader('Date');
        if ($dateHeader instanceof DateHeader) {
            $dt = $dateHeader->getDateTime();
            if ($dt instanceof \DateTimeInterface) {
                $dateUtc = \DateTimeImmutable::createFromInterface($dt)->setTimezone(new \DateTimeZone('UTC'));
            }
        }
        if ($dateUtc === null) {
            $dateUtc = self::parseDate($mimeMessage->getHeaderValue('Date'), $message->getInternalDate());
        }

        $plain = $mimeMessage->getTextContent() ?? '';
        $html = $mimeMessage->getHtmlContent() ?? '';

        $headerParts = explode("\r\n\r\n", $rawMime, 2);
        if (count($headerParts) < 2) {
            $headerParts = explode("\n\n", $rawMime, 2);
        }
        $rawHeaders = $headerParts[0] ?? null;

        $metadata = array_filter([
            'list_id'           => self::trimHeader($mimeMessage->getHeaderValue('List-ID')),
            'list_unsubscribe'  => self::trimHeader($mimeMessage->getHeaderValue('List-Unsubscribe')),
            'precedence'        => self::trimHeader($mimeMessage->getHeaderValue('Precedence')),
            'sender'            => self::trimHeader($mimeMessage->getHeaderValue('Sender')),
            'gmail_label_ids'   => $message->getLabelIds(),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);

        return [
            'gmail_message_id' => $gmailId !== '' ? $gmailId : null,
            'imap_uid'         => null,
            'message_id'       => self::trimHeader($mimeMessage->getHeaderValue('Message-ID')),
            'from_addr'        => $fromRaw !== '' ? $fromRaw : null,
            'to_addr'          => $to ?: null,
            'cc_addr'          => $cc ?: null,
            'subject'          => $subject !== '' ? $subject : null,
            'from_email'       => $fromEmail,
            'from_name'        => $fromName,
            'date_utc'         => $dateUtc->format('Y-m-d H:i:s'),
            'date_received'    => $dateUtc->format('Y-m-d H:i:s'),
            'date_sent'        => null,
            'body_text'        => $plain !== '' ? $plain : null,
            'body_html'        => $html !== '' ? $html : null,
            'raw_headers'      => $rawHeaders !== '' ? $rawHeaders : null,
            'text_body'        => $plain !== '' ? $plain : null,
            'html_body'        => $html !== '' ? $html : null,
            'metadata'         => $metadata !== [] ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
        ];
    }

    private static function parseDate(?string $dateHeader, ?string $internalMs): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        if ($dateHeader !== null && trim($dateHeader) !== '') {
            $ts = strtotime($dateHeader);
            if ($ts !== false) {
                return (new \DateTimeImmutable('@' . $ts))->setTimezone($utc);
            }
        }
        if ($internalMs !== null && ctype_digit((string)$internalMs)) {
            $sec = (int)floor(((int)$internalMs) / 1000);

            return (new \DateTimeImmutable('@' . $sec))->setTimezone($utc);
        }

        return new \DateTimeImmutable('now', $utc);
    }

    private static function trimHeader(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string)$v);

        return $s === '' ? null : $s;
    }

    private static function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }

        return mb_strcut($s, 0, $max, 'UTF-8');
    }
}
