<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use Seismo\Core\Fetcher\ArticleLinkNormalizer;

/**
 * Newsletter tracking redirects vs benign analytics query params (Slice 11c).
 */
final class EmailTrackingUrl
{
    /**
     * True for redirect/CDN hosts that should not be kept as clickable article links.
     * UTM and similar params on normal article URLs are handled via {@see cleanNewsletterHref()}.
     */
    public static function isRedirectTrackingUrl(string $url): bool
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
            || str_contains($lower, 'mailchi.mp')
            || str_contains($lower, 'sendgrid.net')
            || str_contains($lower, 'mandrillapp.com')
            || str_contains($lower, 'campaign-archive.com');
    }

    /**
     * @deprecated Use {@see isRedirectTrackingUrl()} or {@see cleanNewsletterHref()}.
     */
    public static function isTrackingOrAsset(string $url): bool
    {
        return self::isRedirectTrackingUrl($url);
    }

    /**
     * Strip newsletter analytics params; leave redirect-tracker URLs unchanged
     * (callers unwrap those separately).
     */
    public static function cleanNewsletterHref(string $url): string
    {
        $url = trim($url);
        if ($url === '' || self::isRedirectTrackingUrl($url)) {
            return $url;
        }

        return ArticleLinkNormalizer::normalize($url);
    }
}
