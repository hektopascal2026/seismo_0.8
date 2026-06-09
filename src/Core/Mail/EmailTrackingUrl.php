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
            || str_contains($lower, 'campaign-archive.com')
            || str_contains($lower, 'activehosted.com')
            || str_contains($lower, 'klaviyomail.com')
            || str_contains($lower, 'convertkit-mail.com')
            || str_contains($lower, 'ccsend.com')
            || str_contains($lower, 'gr8.com')
            || str_contains($lower, 'sib.com')
            || str_contains($lower, 'sendinblue.com')
            || str_contains($lower, 'brevo.com')
            || str_contains($lower, 'klaviyo.com')
            || str_contains($lower, 'mlsend.com')
            || str_contains($lower, 'hubspotemail.net')
            || str_contains($lower, 'mjt.lu')
            || str_contains($lower, 'e2ma.net')
            || str_contains($lower, 'ghost.io')
            || str_contains($lower, 'beehiiv.com')
            || str_contains($lower, 'aweber.com')
            || str_contains($lower, 'customeriomail.com')
            || str_contains($lower, 'dotmailer.com')
            || preg_match('#\bcmail\d+\.com\b#', $lower) === 1;
    }

    /**
     * Whitelisted tracking domains that actually host or redirect directly to the newsletter webview.
     */
    public static function isAllowedWebviewRedirectUrl(string $url): bool
    {
        $lower = mb_strtolower(trim($url), 'UTF-8');

        return str_contains($lower, 'mailchi.mp')
            || str_contains($lower, 'campaign-archive.com')
            || str_contains($lower, 'list-manage.com')
            || str_contains($lower, 'awstrack.me')
            || str_contains($lower, 'sendgrid.net')
            || str_contains($lower, 'mandrillapp.com')
            || str_contains($lower, '/track/click')
            || str_contains($lower, 'click.email.')
            || str_contains($lower, 'clicks.')
            || str_contains($lower, 'activehosted.com')
            || str_contains($lower, 'klaviyomail.com')
            || str_contains($lower, 'convertkit-mail.com')
            || str_contains($lower, 'ccsend.com')
            || str_contains($lower, 'gr8.com')
            || str_contains($lower, 'sib.com')
            || str_contains($lower, 'sendinblue.com')
            || str_contains($lower, 'brevo.com')
            || str_contains($lower, 'klaviyo.com')
            || str_contains($lower, 'mlsend.com')
            || str_contains($lower, 'hubspotemail.net')
            || str_contains($lower, 'mjt.lu')
            || str_contains($lower, 'e2ma.net')
            || str_contains($lower, 'ghost.io')
            || str_contains($lower, 'beehiiv.com')
            || str_contains($lower, 'aweber.com')
            || str_contains($lower, 'customeriomail.com')
            || str_contains($lower, 'dotmailer.com')
            || preg_match('#\bcmail\d+\.com\b#', $lower) === 1;
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
