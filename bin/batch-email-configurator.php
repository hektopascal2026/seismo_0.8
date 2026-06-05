<?php
/**
 * Seismo CLI Batch Email AI Cleanup & WebView Configurator
 *
 * Loops through active email subscriptions, analyzes their last 5 samples using Gemini,
 * generates clean static regular expressions & webview keywords, and saves the configuration.
 *
 * Usage:
 *   php bin/batch-email-configurator.php --all             # Process all active subscriptions
 *   php bin/batch-email-configurator.php --id=42            # Process a specific subscription ID
 *   php bin/batch-email-configurator.php --dry-run --all   # Preview what would be configured
 *   php bin/batch-email-configurator.php --reprocess --all  # Re-apply config to historical emails immediately
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Seismo\Repository\EmailSubscriptionRepository;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\EmailGeminiConfigGenerator;
use Seismo\Service\EmailSubscriptionReprocessService;

$dryRun = in_array('--dry-run', $argv, true);
$reprocess = in_array('--reprocess', $argv, true);
$all = in_array('--all', $argv, true);
$targetId = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--id=')) {
        $targetId = (int)substr($arg, 5);
    }
}

if (!$all && $targetId === null) {
    echo "Seismo Batch Email AI Cleanup & WebView Configurator\n";
    echo "====================================================\n";
    echo "This script uses Gemini to generate static regex cleanups for multiple newsletter senders.\n\n";
    echo "Options:\n";
    echo "  --all             Analyze all active email subscriptions\n";
    echo "  --id=X            Only analyze the email subscription with ID X\n";
    echo "  --dry-run         Fetch analysis results but do NOT save them to the database\n";
    echo "  --reprocess       Reprocess existing stored emails with the newly generated rules\n\n";
    echo "Example:\n";
    echo "  php bin/batch-email-configurator.php --all --reprocess\n";
    exit(0);
}

if (isSatellite()) {
    fwrite(STDERR, "Error: Satellites do not run email ingestion. Execute on the mothership.\n");
    exit(1);
}

try {
    $pdo = getDbConnection();
} catch (\Throwable $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$subRepo = new EmailSubscriptionRepository($pdo);
$configRepo = new SystemConfigRepository($pdo);
$ingestRepo = new \Seismo\Repository\EmailIngestRepository($pdo);

// Find active subscription rows
$subscriptions = [];
if ($targetId !== null) {
    $sub = $subRepo->findById($targetId);
    if ($sub === null) {
        fwrite(STDERR, "Error: Subscription with ID {$targetId} not found.\n");
        exit(1);
    }
    if (EmailSubscriptionRepository::isPendingRow($sub)) {
        echo "[!] Warning: Subscription ID {$targetId} is still pending review. Processing anyway...\n";
    }
    $subscriptions = [$sub];
} else {
    // Fetch all active subscriptions
    $subscriptions = $subRepo->listActive(EmailSubscriptionRepository::MAX_LIMIT, 0);
}

if (empty($subscriptions)) {
    echo "No subscriptions found to process.\n";
    exit(0);
}

// Ensure Gemini API Key is configured
$apiKey = trim((string)($configRepo->get(\Seismo\Controller\SettingsController::KEY_GEMINI_API_KEY) ?? ''));
if ($apiKey === '') {
    fwrite(STDERR, "Error: Google Gemini API key is not configured. Configure it in the web UI Settings first.\n");
    exit(1);
}

echo "Starting Batch Configurator (" . count($subscriptions) . " source(s) found)...\n";
echo "Dry Run Mode: " . ($dryRun ? "ENABLED (No database changes will be saved)" : "DISABLED") . "\n";
echo "Reprocess Stored Emails: " . ($reprocess ? "ENABLED" : "DISABLED") . "\n";
echo "--------------------------------------------------------------------------------\n\n";

$generator = new EmailGeminiConfigGenerator($configRepo);

$successCount = 0;
$skippedCount = 0;
$failedCount = 0;

foreach ($subscriptions as $sub) {
    $id = (int)$sub['id'];
    $name = $sub['display_name'] ?: ($sub['match_type'] . ':' . $sub['match_value']);
    echo "=> Processing #{$id}: {$name} ...\n";

    // 1. Fetch recent samples
    $emails = $ingestRepo->fetchRowsForSubscriptionMatch(
        (string)$sub['match_type'],
        (string)$sub['match_value'],
        \Seismo\Service\EmailGeminiConfigGenerator::GEMINI_SAMPLE_COUNT
    );

    if (empty($emails)) {
        echo "   [!] No sample emails found in Seismo database for this sender. Skipping.\n\n";
        $skippedCount++;
        continue;
    }

    echo "   [+] Found " . count($emails) . " sample email(s) for analysis. Calling Gemini...\n";

    $samples = [];
    foreach ($emails as $email) {
        $html = trim((string)($email['html_body'] ?? $email['body_html'] ?? ''));
        if ($html !== '') {
            $safeHtml = \Seismo\Core\Mail\EmailHtmlSanitizer::sanitize($html, true);
            $bodyText = \Seismo\Core\Mail\EmailPlainTextExtractor::fromSanitizedHtml($safeHtml, true);
        } else {
            $bodyText = (string)($email['text_body'] ?? $email['body_text'] ?? '');
        }

        $samples[] = [
            'subject' => (string)($email['subject'] ?? ''),
            'body' => $bodyText,
            'text_body' => $bodyText,
            'html_body' => $html,
        ];
    }

    try {
        // 2. Generate config
        $config = $generator->generateConfig($samples);

        echo "   [✓] Gemini Config Generated successfully:\n";
        echo "       - Regex Cleanup Rules count: " . count($config['strip_regexes'] ?? []) . "\n";
        if (!empty($config['strip_regexes'])) {
            foreach ($config['strip_regexes'] as $re) {
                echo "         * " . $re . "\n";
            }
        }
        echo "       - WebView Keywords count: " . count($config['webview_keywords'] ?? []) . "\n";
        if (!empty($config['webview_keywords'])) {
            echo "         * Keywords: " . implode(', ', $config['webview_keywords']) . "\n";
        }
        echo "       - Title Extractor: " . ($config['title_extractor'] ?: 'None') . "\n";

        // 3. Save to database (unless dry-run)
        if (!$dryRun) {
            $configJson = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            
            $payload = [
                'match_type'                => $sub['match_type'],
                'match_value'               => $sub['match_value'],
                'display_name'              => $sub['display_name'],
                'category'                  => $sub['category'],
                'disabled'                  => $sub['disabled'],
                'show_in_magnitu'           => $sub['show_in_magnitu'],
                'strip_listing_boilerplate' => 1, // Automatically enable Gemini cleanup
                'body_processor'            => $sub['body_processor'],
                'cleanup_config'            => $configJson,
                'unsubscribe_url'           => $sub['unsubscribe_url'],
                'unsubscribe_mailto'        => $sub['unsubscribe_mailto'],
                'unsubscribe_one_click'     => $sub['unsubscribe_one_click'],
            ];

            $subRepo->update($id, $payload);
            echo "   [✓] Static configuration saved and enabled.\n";

            // 4. Optionally reprocess emails
            if ($reprocess) {
                echo "   [+] Reprocessing existing stored emails with the new configuration...\n";
                $reprocessor = new EmailSubscriptionReprocessService($pdo);
                $numReprocessed = $reprocessor->reprocessSubscription($id);
                echo "       - Reprocessed {$numReprocessed} stored email(s).\n";
            }
        } else {
            echo "   [i] Dry-run enabled. Skipping database save.\n";
        }
        
        $successCount++;
        echo "   [Success]\n\n";

    } catch (\Throwable $e) {
        echo "   [X] Failed to analyze subscription #{$id}: " . $e->getMessage() . "\n";
        $failedCount++;
        echo "   [Failed]\n\n";
    }
}

echo "--------------------------------------------------------------------------------\n";
echo "Batch Ingestion Configurator Complete!\n";
echo "Processed: " . count($subscriptions) . "\n";
echo "Success:   {$successCount}\n";
echo "Skipped:   {$skippedCount}\n";
echo "Failed:    {$failedCount}\n";
exit($failedCount > 0 ? 1 : 0);
