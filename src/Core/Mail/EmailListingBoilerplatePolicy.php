<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use Seismo\Repository\SystemConfigRepository;

/**
 * When to strip newsletter listing boilerplate (per-subscription and/or global default).
 */
final class EmailListingBoilerplatePolicy
{
    /**
     * @param array{display_name?: ?string, strip_listing_boilerplate?: bool} $subscriptionUi
     */
    public static function shouldStrip(array $subscriptionUi, ?bool $globalEnabled = null): bool
    {
        if (!empty($subscriptionUi['strip_listing_boilerplate'])) {
            return true;
        }

        return $globalEnabled ?? self::readGlobalDefault();
    }

    public static function readGlobalDefault(): bool
    {
        if (!function_exists('getDbConnection')) {
            return false;
        }

        try {
            $repo = new SystemConfigRepository(getDbConnection());
            $raw  = $repo->get(MailConfigKeys::STRIP_LISTING_BOILERPLATE);

            return $raw === '1' || $raw === 'true';
        } catch (\Throwable) {
            return false;
        }
    }
}
