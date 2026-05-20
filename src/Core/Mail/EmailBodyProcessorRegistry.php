<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use Seismo\Core\Mail\Processor\EuroparlPressProcessor;
use Seismo\Repository\EmailSubscriptionRepository;

final class EmailBodyProcessorRegistry
{
    /** @var array<string, EmailBodyProcessorInterface>|null */
    private static ?array $processors = null;

    /**
     * @return list<string>
     */
    public static function knownKeys(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return array<string, string> key => human label
     */
    public static function choicesForAdmin(): array
    {
        return [
            ''                      => '(none — subject + generic extract only)',
            EuroparlPressProcessor::KEY => 'EP TODAY digest (Europarl press)',
        ];
    }

    public static function get(string $key): ?EmailBodyProcessorInterface
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        return self::all()[$key] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $subscriptionRows
     */
    public static function resolveKeyForFromEmail(string $fromEmail, array $subscriptionRows): ?string
    {
        $from = trim($fromEmail);
        if ($from === '') {
            return null;
        }
        $bestRank = 0;
        $bestKey  = null;
        foreach ($subscriptionRows as $row) {
            if (!empty($row['disabled']) || !empty($row['auto_detected'])) {
                continue;
            }
            $key = trim((string)($row['body_processor'] ?? ''));
            if ($key === '') {
                continue;
            }
            $mt = (string)($row['match_type'] ?? '');
            $mv = (string)($row['match_value'] ?? '');
            if (!EmailSubscriptionRepository::matchesAddress($from, $mt, $mv)) {
                continue;
            }
            $rank = $mt === 'email' ? 2 : 1;
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $bestKey  = $key;
            }
        }

        return $bestKey;
    }

    /**
     * @return array<string, EmailBodyProcessorInterface>
     */
    private static function all(): array
    {
        if (self::$processors !== null) {
            return self::$processors;
        }
        self::$processors = [
            EuroparlPressProcessor::KEY => new EuroparlPressProcessor(),
        ];

        return self::$processors;
    }
}
