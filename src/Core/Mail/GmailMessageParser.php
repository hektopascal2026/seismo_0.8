<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use Google\Service\Gmail\Message;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\MessagePartHeader;

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
        $headers = self::headersMap($message);
        $parts   = self::collectBodies($message->getPayload());

        $subject = self::decodeHeader($headers['subject'] ?? '');
        $fromRaw = self::decodeHeader($headers['from'] ?? '');
        [$fromName, $fromEmail] = self::parseFrom($fromRaw);
        $dateUtc = self::parseDate($headers['date'] ?? null, $message->getInternalDate());

        $metadata = array_filter([
            'list_id'           => self::trimHeader($headers['list-id'] ?? null),
            'list_unsubscribe'  => self::trimHeader($headers['list-unsubscribe'] ?? null),
            'precedence'        => self::trimHeader($headers['precedence'] ?? null),
            'sender'            => self::trimHeader($headers['sender'] ?? null),
            'gmail_label_ids'   => $message->getLabelIds(),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);

        return [
            'gmail_message_id' => $gmailId !== '' ? $gmailId : null,
            'imap_uid'         => null,
            'message_id'       => self::trimHeader($headers['message-id'] ?? null),
            'from_addr'        => $fromRaw !== '' ? $fromRaw : null,
            'to_addr'          => self::decodeHeader($headers['to'] ?? '') ?: null,
            'cc_addr'          => self::decodeHeader($headers['cc'] ?? '') ?: null,
            'subject'          => $subject !== '' ? $subject : null,
            'from_email'       => $fromEmail,
            'from_name'        => $fromName,
            'date_utc'         => $dateUtc->format('Y-m-d H:i:s'),
            'date_received'    => $dateUtc->format('Y-m-d H:i:s'),
            'date_sent'        => null,
            'body_text'        => $parts['plain'] !== '' ? $parts['plain'] : null,
            'body_html'        => $parts['html'] !== '' ? $parts['html'] : null,
            'raw_headers'      => self::serializeHeaders($message),
            'text_body'        => $parts['plain'] !== '' ? $parts['plain'] : null,
            'html_body'        => $parts['html'] !== '' ? $parts['html'] : null,
            'metadata'         => $metadata !== [] ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function headersMap(Message $message): array
    {
        $out = [];
        $payload = $message->getPayload();
        if ($payload === null) {
            return $out;
        }
        foreach ($payload->getHeaders() ?? [] as $header) {
            if (!$header instanceof MessagePartHeader) {
                continue;
            }
            $name = strtolower(trim((string)$header->getName()));
            if ($name === '') {
                continue;
            }
            $out[$name] = (string)$header->getValue();
        }

        return $out;
    }

    /**
     * @return array{plain: string, html: string}
     */
    private static function collectBodies(?MessagePart $part): array
    {
        $plain = '';
        $html  = '';
        if ($part === null) {
            return ['plain' => '', 'html' => ''];
        }
        self::walkPart($part, $plain, $html);

        return ['plain' => $plain, 'html' => $html];
    }

    private static function walkPart(MessagePart $part, string &$plain, string &$html): void
    {
        $mime = strtolower((string)$part->getMimeType());
        $body = $part->getBody();
        $data = $body !== null ? (string)($body->getData() ?? '') : '';
        if ($data !== '') {
            $decoded = self::decodeBody($data);
            if ($mime === 'text/plain' && $plain === '') {
                $plain = $decoded;
            } elseif ($mime === 'text/html' && $html === '') {
                $html = $decoded;
            }
        }
        foreach ($part->getParts() ?? [] as $sub) {
            if ($sub instanceof MessagePart) {
                self::walkPart($sub, $plain, $html);
            }
        }
    }

    private static function decodeBody(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad  = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode($data, true);

        return $raw === false ? '' : $raw;
    }

    private static function serializeHeaders(Message $message): ?string
    {
        $payload = $message->getPayload();
        if ($payload === null) {
            return null;
        }
        $lines = [];
        foreach ($payload->getHeaders() ?? [] as $header) {
            if (!$header instanceof MessagePartHeader) {
                continue;
            }
            $lines[] = $header->getName() . ': ' . $header->getValue();
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    private static function decodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (function_exists('imap_mime_header_decode')) {
            $decoded = '';
            foreach (imap_mime_header_decode($value) as $part) {
                $decoded .= $part->text;
            }
            if ($decoded !== '') {
                return $decoded;
            }
        }

        return $value;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function parseFrom(string $from): array
    {
        $from = trim($from);
        if ($from === '') {
            return [null, null];
        }
        if (preg_match('/<([^>]+@[^>]+)>/', $from, $m)) {
            $email = strtolower(trim($m[1]));
            $name  = trim(preg_replace('/<[^>]+>/', '', $from) ?? '');
            $name  = trim($name, " \t\"'");

            return [$name !== '' ? $name : null, $email];
        }
        if (preg_match('/^\S+@\S+$/', $from)) {
            return [null, strtolower($from)];
        }

        return [null, null];
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
}
