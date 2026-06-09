<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Http\CsrfToken;
use Seismo\Repository\SourceConfigImportRepository;

/**
 * Import source definitions from a JSON payload.
 *
 * Scope: feeds-module sources + scraper configs only.
 */
final class SourceConfigImportController
{
    public function importFeedsAndScraper(): void
    {
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode — source configs are managed on the mothership only.';
            $this->redirectGeneral();
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectGeneral();
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirectGeneral();
        }

        $raw = $this->readPayloadInput();
        if ($raw === '') {
            $_SESSION['error'] = 'No JSON provided. Paste JSON or upload an export file.';
            $this->redirectGeneral();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $_SESSION['error'] = 'Invalid JSON payload.';
            $this->redirectGeneral();
        }

        $feedsRows = $decoded['feeds_module_sources'] ?? [];
        $scraperRows = $decoded['scraper_configs'] ?? [];
        if (!is_array($feedsRows)) {
            $feedsRows = [];
        }
        if (!is_array($scraperRows)) {
            $scraperRows = [];
        }

        if ($feedsRows === [] && $scraperRows === []) {
            $_SESSION['error'] = 'No importable sections found. Expected feeds_module_sources and/or scraper_configs.';
            $this->redirectGeneral();
        }

        try {
            $repo = new SourceConfigImportRepository(getDbConnection());
            $feedStats = $repo->importFeedsModuleSources($this->arrayList($feedsRows));
            $scraperStats = $repo->importScraperConfigs($this->arrayList($scraperRows));

            $_SESSION['success'] = sprintf(
                'Import finished. Feeds: +%d / ~%d / skip %d. Scraper: +%d / ~%d / skip %d.',
                $feedStats['inserted'],
                $feedStats['updated'],
                $feedStats['skipped'],
                $scraperStats['inserted'],
                $scraperStats['updated'],
                $scraperStats['skipped']
            );
        } catch (\Throwable $e) {
            error_log('Seismo source-config import: ' . $e->getMessage());
            $_SESSION['error'] = 'Import failed: ' . $e->getMessage();
        }

        $this->redirectGeneral();
    }

    private function readPayloadInput(): string
    {
        $jsonText = trim((string)($_POST['source_config_json'] ?? ''));
        if ($jsonText !== '') {
            return $jsonText;
        }

        if (!isset($_FILES['source_config_file']) || !is_array($_FILES['source_config_file'])) {
            return '';
        }
        $file = $_FILES['source_config_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return '';
        }
        $tmpPath = (string)($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return '';
        }
        $body = file_get_contents($tmpPath);
        if (!is_string($body)) {
            return '';
        }

        return trim($body);
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function arrayList(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function redirectGeneral(): void
    {
        header('Location: ' . getBasePath() . '/index.php?action=settings&tab=backup', true, 303);
        exit;
    }
}

