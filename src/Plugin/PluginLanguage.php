<?php

declare(strict_types=1);

namespace Seismo\Plugin;

/**
 * Centralised language whitelists for plugin settings (defence-in-depth).
 * Controllers use this instead of reaching into fetcher classes for normalisation.
 */
final class PluginLanguage
{
    /** OData $filter language codes for Parlament CH. */
    public const PARL_CH = ['DE', 'FR', 'IT', 'EN', 'RM'];

    public static function parlCh(string $raw): string
    {
        $u = strtoupper(trim($raw));

        return in_array($u, self::PARL_CH, true) ? $u : 'DE';
    }
}
