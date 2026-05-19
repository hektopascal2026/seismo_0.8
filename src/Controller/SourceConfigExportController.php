<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Repository\SourceConfigExportRepository;

/**
 * Download a JSON snapshot of source/module configuration.
 *
 * Intended for host migrations and backup checks. Contains secrets from
 * system_config (e.g. OAuth tokens) and should be stored securely.
 */
final class SourceConfigExportController
{
    public function download(): void
    {
        if (isSatellite()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Satellite mode — source configs are managed on the mothership.\n";
            return;
        }

        try {
            $repo = new SourceConfigExportRepository(getDbConnection());
            $snapshot = [
                'meta' => [
                    'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                    'seismo_version' => defined('SEISMO_VERSION') ? (string)SEISMO_VERSION : 'unknown',
                    'export_type' => 'source_config_bundle_v1',
                    'base_path' => getBasePath(),
                ],
                'feeds_module_sources' => $repo->listFeedsModuleSources(),
                'scraper_configs' => $repo->listScraperConfigs(),
                'email_subscriptions' => $repo->listEmailSubscriptions(),
                'system_config' => [
                    'plugin_blocks' => $repo->mapSystemConfigByPrefix('plugin:'),
                    'mail_settings' => $repo->mapSystemConfigByPrefix('mail_'),
                    'retention_policies' => $repo->mapSystemConfigByPrefix('retention:'),
                    'ui_settings' => $repo->mapSystemConfigByPrefix('ui:'),
                    'core_runner' => $repo->mapSystemConfigByPrefix('core:'),
                    'satellites' => $repo->mapSystemConfigByPrefix('satellites_'),
                ],
            ];
        } catch (\Throwable $e) {
            error_log('Seismo source-config export: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Could not build export bundle.\n";
            return;
        }

        $json = json_encode(
            $snapshot,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($json)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Could not encode export bundle.\n";
            return;
        }

        $fileName = 'seismo-source-config-' . gmdate('Ymd-His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo $json . "\n";
    }
}

