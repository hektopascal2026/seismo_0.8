<?php
/**
 * Satellite-only route table — UI nav (drawer) is Timeline, Highlights, Label, Settings;
 * the Filter view remains registered and reachable by URL but is hidden from the nav on
 * satellites. In-app: highlights, AI Researcher, in-app Label training, settings (General +
 * Magnitu), Magnitu Bearer API, remote mothership refresh POST, auth, health.
 * No feeds, Lex/Leg admin, Settings → Diagnostics tab, retention, exports, or other
 * mothership-only surfaces.
 *
 * Path satellites share the mothership codebase; this route table hides admin surfaces.
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
    'refresh_remote',
    \Seismo\Controller\DashboardController::class . '::refreshRemote',
    false
);
$router->register(
    'toggle_favourite',
    \Seismo\Controller\FavouriteController::class . '::toggle',
    false
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
    'settings_save_admin_password',
    \Seismo\Controller\SettingsController::class . '::saveAdminPassword',
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
    'researcher_prepare',
    \Seismo\Controller\AiResearcherController::class . '::prepare',
    false
);
$router->register(
    'researcher_generate',
    \Seismo\Controller\AiResearcherController::class . '::generate',
    false
);
$router->register(
    'researcher_save_prompt',
    \Seismo\Controller\AiResearcherController::class . '::savePrompt',
    false
);
$router->register(
    'researcher_prompt_helper',
    \Seismo\Controller\AiResearcherController::class . '::promptHelper',
    false
);
$router->register(
    'save_researcher_prompt',
    \Seismo\Controller\AiResearcherController::class . '::savePromptLibrary',
    false
);
$router->register(
    'delete_researcher_prompt',
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
