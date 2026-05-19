<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Known newsletter tracking / image-CDN hosts (Slice 11c).
 */
final class EmailTrackingUrl
{
    public static function isTrackingOrAsset(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return false;
        }

        $lower = mb_strtolower($url, 'UTF-8');

        return str_contains($lower, 'list-manage.com')
            || str_contains($lower, '/track/click')
            || str_contains($lower, 'mcusercontent.com')
            || str_contains($lower, 'click.email.')
            || str_contains($lower, 'clicks.')
            || str_contains($lower, 'utm_')
            || str_contains($lower, 'mailchi.mp')
            || str_contains($lower, 'sendgrid.net')
            || str_contains($lower, 'mandrillapp.com')
            || str_contains($lower, 'campaign-archive.com');
    }
}
