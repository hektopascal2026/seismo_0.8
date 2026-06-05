<?php

declare(strict_types=1);

namespace Seismo\Mail;

/**
 * Admin module scope for {@see \Seismo\Controller\MailModuleHandler}.
 *
 * Rows stay in `email_subscriptions` / `emails`; `module_scope` partitions Mail vs Newsletter UI.
 */
final class MailModule
{
    public const SCOPE_MAIL = 'mail';

    public const SCOPE_NEWSLETTER = 'newsletter';

    private function __construct(
        public readonly string $action,
        public readonly string $navKey,
        public readonly string $pageTitle,
        public readonly string $subtitle,
        public readonly string $scope,
        public readonly string $saveAction,
        public readonly string $deleteAction,
        public readonly string $disableAction,
        public readonly string $reprocessAction,
        public readonly string $analyzeAction,
        public readonly ?string $moveAction,
        public readonly string $moveTargetLabel,
        public readonly string $refreshAction,
        public readonly string $refreshLabel,
        public readonly string $sourcesHeading,
        public readonly string $sourcesIntroHtml,
        public readonly string $emptyItemsHtml,
        public readonly bool $showsPendingSenders,
    ) {
    }

    public static function mail(): self
    {
        $base = getBasePath();

        return new self(
            action: 'mail',
            navKey: 'mail',
            pageTitle: 'Mail',
            subtitle: 'IMAP / transactional',
            scope: self::SCOPE_MAIL,
            saveAction: 'mail_subscription_save',
            deleteAction: 'mail_subscription_delete',
            disableAction: 'mail_subscription_disable',
            reprocessAction: 'mail_subscription_reprocess',
            analyzeAction: 'mail_subscription_analyze',
            moveAction: 'mail_subscription_move_newsletter',
            moveTargetLabel: 'Move to Newsletter',
            refreshAction: 'refresh_mail_ingest',
            refreshLabel: 'Refresh Mail',
            sourcesHeading: 'Mail sources',
            sourcesIntroHtml: '<p class="admin-intro">Domain-first matching (e.g. <code>example.com</code> covers '
                . '<code>alice@example.com</code>). Per-address overrides use match type <em>email</em>. '
                . 'When Gmail ingests mail from an unknown domain, it is queued under <strong>New senders</strong> '
                . 'for review before it appears in the table below. Newsletter digests belong on '
                . '<a href="' . htmlspecialchars($base . '/index.php?action=newsletter', ENT_QUOTES, 'UTF-8') . '">Newsletter</a>.</p>',
            emptyItemsHtml: 'No mail rows yet. Configure IMAP fetch separately; subscription rules live under <a href="{sources_href}">Sources</a>.',
            showsPendingSenders: true,
        );
    }

    public static function newsletter(): self
    {
        $base = getBasePath();

        return new self(
            action: 'newsletter',
            navKey: 'newsletter',
            pageTitle: 'Newsletter',
            subtitle: 'IMAP / digests',
            scope: self::SCOPE_NEWSLETTER,
            saveAction: 'newsletter_subscription_save',
            deleteAction: 'newsletter_subscription_delete',
            disableAction: 'newsletter_subscription_disable',
            reprocessAction: 'newsletter_subscription_reprocess',
            analyzeAction: 'newsletter_subscription_analyze',
            moveAction: 'newsletter_subscription_move_mail',
            moveTargetLabel: 'Move to Mail',
            refreshAction: 'refresh_mail_ingest',
            refreshLabel: 'Refresh Mail',
            sourcesHeading: 'Newsletter sources',
            sourcesIntroHtml: '<p class="admin-intro">Newsletter subscriptions use the same Gmail/IMAP ingest as '
                . '<a href="' . htmlspecialchars($base . '/index.php?action=mail', ENT_QUOTES, 'UTF-8') . '">Mail</a>. '
                . 'Move sources here from Mail → Sources when you want digest-style cards on the timeline.</p>',
            emptyItemsHtml: 'No newsletter items yet. Move a source from <a href="' . htmlspecialchars($base . '/index.php?action=mail&view=sources', ENT_QUOTES, 'UTF-8') . '">Mail → Sources</a> or add one under <a href="{sources_href}">Sources</a>.',
            showsPendingSenders: false,
        );
    }

    public function isNewsletter(): bool
    {
        return $this->scope === self::SCOPE_NEWSLETTER;
    }
}
