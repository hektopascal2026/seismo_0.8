<?php
/**
 * Full mothership route table — included from index.php when !isSatellite().
 *
 * @var \Seismo\Http\Router $router
 */

declare(strict_types=1);

$router->register(
    'index',
    \Seismo\Controller\DashboardController::class . '::show',
    true
);
$router->register(
    'filter',
    \Seismo\Controller\DashboardController::class . '::showFilter',
    true
);
$router->register(
    'health',
    \Seismo\Controller\HealthController::class . '::show',
    true
);
$router->register(
    'about',
    \Seismo\Controller\AboutController::class . '::show',
    true
);
$router->register(
    'configuration',
    \Seismo\Controller\SetupController::class . '::show',
    true
);
$router->register(
    'setup',
    \Seismo\Controller\SetupController::class . '::redirectLegacySetup',
    true
);
$router->register(
    'toggle_favourite',
    \Seismo\Controller\FavouriteController::class . '::toggle',
    false
);
$router->register(
    'hide_entry',
    \Seismo\Controller\HideEntryController::class . '::hide',
    false
);
$router->register(
    'lex',
    \Seismo\Controller\LexController::class . '::show',
    true
);
$router->register(
    'refresh_fedlex',
    \Seismo\Controller\LexController::class . '::refreshFedlex',
    false
);
$router->register(
    'save_lex_ch',
    \Seismo\Controller\LexController::class . '::saveLexCh',
    false
);
$router->register(
    'refresh_lex_eu',
    \Seismo\Controller\LexController::class . '::refreshLexEu',
    false
);
$router->register(
    'save_lex_eu',
    \Seismo\Controller\LexController::class . '::saveLexEu',
    false
);
$router->register(
    'refresh_recht_bund',
    \Seismo\Controller\LexController::class . '::refreshRechtBund',
    false
);
$router->register(
    'save_lex_de',
    \Seismo\Controller\LexController::class . '::saveLexDe',
    false
);
$router->register(
    'refresh_legifrance',
    \Seismo\Controller\LexController::class . '::refreshLegifrance',
    false
);
$router->register(
    'refresh_lex_all',
    \Seismo\Controller\LexController::class . '::refreshAllLex',
    false
);
$router->register(
    'save_lex_fr',
    \Seismo\Controller\LexController::class . '::saveLexFr',
    false
);
$router->register(
    'save_lex_jus',
    \Seismo\Controller\LexController::class . '::saveLexJus',
    false
);
$router->register(
    'refresh_jus_bger',
    \Seismo\Controller\LexController::class . '::refreshJusBger',
    false
);
$router->register(
    'refresh_jus_bge',
    \Seismo\Controller\LexController::class . '::refreshJusBge',
    false
);
$router->register(
    'refresh_jus_bvger',
    \Seismo\Controller\LexController::class . '::refreshJusBvger',
    false
);
$router->register(
    'leg',
    \Seismo\Controller\LegController::class . '::show',
    true
);
$router->register(
    'calendar',
    \Seismo\Controller\LegController::class . '::show',
    true
);
$router->register(
    'refresh_parl_ch',
    \Seismo\Controller\LegController::class . '::refreshParlCh',
    false
);
$router->register(
    'save_leg_parl_ch',
    \Seismo\Controller\LegController::class . '::saveLegParlCh',
    false
);
$router->register(
    'refresh_all',
    \Seismo\Controller\DiagnosticsController::class . '::refreshAll',
    false
);
$router->register(
    'refresh_all_remote',
    \Seismo\Controller\DiagnosticsController::class . '::refreshAllRemote',
    true
);
$router->register(
    'refresh_plugin',
    \Seismo\Controller\DiagnosticsController::class . '::refreshPlugin',
    false
);
$router->register(
    'plugin_test',
    \Seismo\Controller\DiagnosticsController::class . '::test',
    false
);
$router->register(
    'diagnostics',
    \Seismo\Controller\DiagnosticsController::class . '::show',
    true
);
$router->register(
    'login',
    \Seismo\Controller\AuthController::class . '::showLogin',
    false
);
$router->register(
    'logout',
    \Seismo\Controller\AuthController::class . '::logout',
    false
);
$router->register(
    'settings',
    \Seismo\Controller\SettingsController::class . '::show',
    true
);
$router->register(
    'settings_save',
    \Seismo\Controller\SettingsController::class . '::saveGeneral',
    false
);
$router->register(
    'settings_restore_researcher_builtin_prompts',
    \Seismo\Controller\SettingsController::class . '::restoreResearcherBuiltinPrompts',
    false
);
$router->register(
    'settings_save_admin_password',
    \Seismo\Controller\SettingsController::class . '::saveAdminPassword',
    false
);
$router->register(
    'export_source_configs',
    \Seismo\Controller\SourceConfigExportController::class . '::download',
    true
);
$router->register(
    'import_source_configs',
    \Seismo\Controller\SourceConfigImportController::class . '::importFeedsAndScraper',
    false
);
$router->register(
    'settings_save_mail',
    \Seismo\Controller\SettingsController::class . '::saveMail',
    false
);
$router->register(
    'mail_google_oauth_start',
    \Seismo\Controller\MailGoogleOAuthController::class . '::startConnect',
    false
);
$router->register(
    'mail_google_oauth_callback',
    \Seismo\Controller\MailGoogleOAuthController::class . '::callback',
    true
);
$router->register(
    'mail_google_disconnect',
    \Seismo\Controller\MailGoogleOAuthController::class . '::disconnect',
    false
);
$router->register(
    'mail_google_reconnect',
    \Seismo\Controller\MailGoogleOAuthController::class . '::reconnect',
    false
);
$router->register(
    'mail_gmail_catchup',
    \Seismo\Controller\MailGoogleOAuthController::class . '::catchUp',
    false
);
$router->register(
    'settings_save_magnitu',
    \Seismo\Controller\MagnituAdminController::class . '::saveConfig',
    false
);
$router->register(
    'settings_regenerate_magnitu_key',
    \Seismo\Controller\MagnituAdminController::class . '::regenerateKey',
    false
);
$router->register(
    'settings_clear_magnitu_scores',
    \Seismo\Controller\MagnituAdminController::class . '::clearScores',
    false
);
$router->register(
    'satellite_add',
    \Seismo\Controller\SatelliteController::class . '::add',
    false
);
$router->register(
    'satellite_remove',
    \Seismo\Controller\SatelliteController::class . '::remove',
    false
);
$router->register(
    'satellite_rotate_key',
    \Seismo\Controller\SatelliteController::class . '::rotateKey',
    false
);
$router->register(
    'satellite_rotate_refresh_key',
    \Seismo\Controller\SatelliteController::class . '::rotateRefreshKey',
    false
);
$router->register(
    'styleguide',
    \Seismo\Controller\StyleguideController::class . '::show',
    true
);
$router->register(
    'design_sandbox',
    \Seismo\Controller\StyleguideController::class . '::showSandbox',
    true
);
$router->register(
    'design_mockup',
    \Seismo\Controller\StyleguideController::class . '::showMockup',
    true
);
$router->register(
    'logbook',
    \Seismo\Controller\LogbookController::class . '::show',
    true
);
$router->register(
    'feeds',
    \Seismo\Controller\FeedController::class . '::show',
    true
);
$router->register(
    'feed_save',
    \Seismo\Controller\FeedController::class . '::save',
    false
);
$router->register(
    'feed_delete',
    \Seismo\Controller\FeedController::class . '::delete',
    false
);
$router->register(
    'feed_toggle_disabled',
    \Seismo\Controller\FeedController::class . '::toggleDisabled',
    false
);
$router->register(
    'feed_preview',
    \Seismo\Controller\FeedController::class . '::preview',
    false
);
$router->register(
    'refresh_feed_sources',
    \Seismo\Controller\FeedController::class . '::refreshFeedSources',
    false
);
$router->register(
    'media',
    \Seismo\Controller\MediaController::class . '::show',
    true
);
$router->register(
    'media_save',
    \Seismo\Controller\MediaController::class . '::save',
    false
);
$router->register(
    'media_delete',
    \Seismo\Controller\MediaController::class . '::delete',
    false
);
$router->register(
    'media_toggle_disabled',
    \Seismo\Controller\MediaController::class . '::toggleDisabled',
    false
);
$router->register(
    'media_preview',
    \Seismo\Controller\MediaController::class . '::preview',
    false
);
$router->register(
    'refresh_media_sources',
    \Seismo\Controller\MediaController::class . '::refreshMediaSources',
    false
);
$router->register(
    'scraper',
    \Seismo\Controller\ScraperController::class . '::show',
    true
);
$router->register(
    'scraper_save',
    \Seismo\Controller\ScraperController::class . '::save',
    false
);
$router->register(
    'scraper_delete',
    \Seismo\Controller\ScraperController::class . '::delete',
    false
);
$router->register(
    'scraper_toggle_disabled',
    \Seismo\Controller\ScraperController::class . '::toggleDisabled',
    false
);
$router->register(
    'scraper_preview',
    \Seismo\Controller\ScraperController::class . '::preview',
    false
);
$router->register(
    'scraper_analyze_gemini',
    \Seismo\Controller\ScraperController::class . '::analyzeGemini',
    false
);
$router->register(
    'refresh_scraper_sources',
    \Seismo\Controller\ScraperController::class . '::refreshScraperSources',
    false
);
$router->register(
    'mail',
    \Seismo\Controller\MailController::class . '::show',
    true
);
$router->register(
    'refresh_mail_ingest',
    \Seismo\Controller\MailController::class . '::refreshMailIngest',
    false
);
$router->register(
    'mail_subscription_save',
    \Seismo\Controller\MailController::class . '::saveSubscription',
    false
);
$router->register(
    'mail_subscription_analyze',
    \Seismo\Controller\MailController::class . '::analyzeBoilerplate',
    false
);
$router->register(
    'mail_subscription_analyze_splitting',
    \Seismo\Controller\MailController::class . '::analyzeSplitting',
    false
);
$router->register(
    'mail_subscription_delete',
    \Seismo\Controller\MailController::class . '::deleteSubscription',
    false
);
$router->register(
    'mail_subscription_disable',
    \Seismo\Controller\MailController::class . '::disableSubscription',
    false
);
$router->register(
    'mail_subscription_reprocess',
    \Seismo\Controller\MailController::class . '::reprocessSubscription',
    false
);
$router->register(
    'mail_subscription_move_newsletter',
    \Seismo\Controller\MailController::class . '::moveToNewsletter',
    false
);
$router->register(
    'newsletter',
    \Seismo\Controller\NewsletterController::class . '::show',
    true
);
$router->register(
    'newsletter_subscription_save',
    \Seismo\Controller\NewsletterController::class . '::saveSubscription',
    false
);
$router->register(
    'newsletter_subscription_analyze',
    \Seismo\Controller\NewsletterController::class . '::analyzeBoilerplate',
    false
);
$router->register(
    'newsletter_subscription_analyze_splitting',
    \Seismo\Controller\NewsletterController::class . '::analyzeSplitting',
    false
);
$router->register(
    'newsletter_subscription_delete',
    \Seismo\Controller\NewsletterController::class . '::deleteSubscription',
    false
);
$router->register(
    'newsletter_subscription_disable',
    \Seismo\Controller\NewsletterController::class . '::disableSubscription',
    false
);
$router->register(
    'newsletter_subscription_reprocess',
    \Seismo\Controller\NewsletterController::class . '::reprocessSubscription',
    false
);
$router->register(
    'newsletter_reprocess_all',
    \Seismo\Controller\NewsletterController::class . '::reprocessAll',
    false
);
$router->register(
    'newsletter_subscription_move_mail',
    \Seismo\Controller\NewsletterController::class . '::moveToMail',
    false
);
$router->register(
    'magnitu',
    \Seismo\Controller\MagnituHighlightsController::class . '::show',
    true
);
$router->register(
    'researcher',
    \Seismo\Controller\AiResearcherController::class . '::show',
    true
);
$router->register(
    'briefing_builder',
    \Seismo\Controller\AiResearcherController::class . '::show',
    true
);
$router->register(
    'researcher_prepare',
    \Seismo\Controller\AiResearcherController::class . '::prepare',
    false
);
$router->register(
    'briefing_builder_prepare',
    \Seismo\Controller\AiResearcherController::class . '::prepare',
    false
);
$router->register(
    'researcher_generate',
    \Seismo\Controller\AiResearcherController::class . '::generate',
    false
);
$router->register(
    'briefing_builder_generate',
    \Seismo\Controller\AiResearcherController::class . '::generate',
    false
);
$router->register(
    'researcher_save_prompt',
    \Seismo\Controller\AiResearcherController::class . '::savePrompt',
    false
);
$router->register(
    'briefing_builder_save_prompt',
    \Seismo\Controller\AiResearcherController::class . '::savePrompt',
    false
);
$router->register(
    'researcher_prompt_helper',
    \Seismo\Controller\AiResearcherController::class . '::promptHelper',
    false
);
$router->register(
    'briefing_prompt_helper',
    \Seismo\Controller\AiResearcherController::class . '::promptHelper',
    false
);
$router->register(
    'save_researcher_prompt',
    \Seismo\Controller\AiResearcherController::class . '::savePromptLibrary',
    false
);
$router->register(
    'save_briefing_prompt',
    \Seismo\Controller\AiResearcherController::class . '::savePromptLibrary',
    false
);
$router->register(
    'delete_researcher_prompt',
    \Seismo\Controller\AiResearcherController::class . '::deletePromptLibrary',
    false
);
$router->register(
    'delete_briefing_prompt',
    \Seismo\Controller\AiResearcherController::class . '::deletePromptLibrary',
    false
);
$router->register(
    'label',
    \Seismo\Controller\MagnituLabelUiController::class . '::show',
    true
);
$router->register(
    'label_save',
    \Seismo\Controller\MagnituLabelUiController::class . '::save',
    false
);
$router->register(
    'retention',
    \Seismo\Controller\RetentionController::class . '::show',
    true
);
$router->register(
    'retention_preview',
    \Seismo\Controller\RetentionController::class . '::preview',
    false
);
$router->register(
    'retention_save',
    \Seismo\Controller\RetentionController::class . '::save',
    false
);
$router->register(
    'retention_prune',
    \Seismo\Controller\RetentionController::class . '::runPrune',
    false
);
$router->register(
    'magnitu_entries',
    \Seismo\Controller\MagnituController::class . '::entries',
    true
);
$router->register(
    'magnitu_scores',
    \Seismo\Controller\MagnituController::class . '::scores',
    true
);
$router->register(
    'magnitu_recipe',
    \Seismo\Controller\MagnituController::class . '::recipe',
    true
);
$router->register(
    'magnitu_labels',
    \Seismo\Controller\MagnituController::class . '::labels',
    true
);
$router->register(
    'magnitu_status',
    \Seismo\Controller\MagnituController::class . '::status',
    true
);
$router->register(
    'export_entries',
    \Seismo\Controller\ExportController::class . '::entries',
    true
);
$router->register(
    'export_researcher',
    \Seismo\Controller\ExportController::class . '::researcher',
    true
);
$router->register(
    'export_briefing',
    \Seismo\Controller\ExportController::class . '::researcher',
    true
);
