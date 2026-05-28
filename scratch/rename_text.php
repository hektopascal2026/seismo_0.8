<?php
declare(strict_types=1);

$dir = dirname(__DIR__);

$replacements = [
    // Class names
    'AiBriefingController' => 'AiResearcherController',
    'BriefingEntryCardPresenter' => 'ResearcherEntryCardPresenter',
    'BriefingEntryGatherer' => 'ResearcherEntryGatherer',
    'BriefingGeminiContext' => 'ResearcherGeminiContext',
    'BriefingLookback' => 'ResearcherLookback',
    'BriefingModuleGuard' => 'ResearcherModuleGuard',
    'BriefingPromptHelperService' => 'ResearcherPromptHelperService',
    'BriefingScoreFilter' => 'ResearcherScoreFilter',
    'BriefingSourceSelection' => 'ResearcherSourceSelection',
    'GeminiBriefingException' => 'GeminiResearcherException',
    'GeminiBriefingResult' => 'GeminiResearcherResult',
    'GeminiBriefingService' => 'GeminiResearcherService',
    'MarkdownBriefingFormatter' => 'MarkdownResearcherFormatter',

    // Route actions
    'briefing_builder_prepare' => 'researcher_prepare',
    'briefing_builder_generate' => 'researcher_generate',
    'briefing_builder_save_prompt' => 'researcher_save_prompt',
    'briefing_prompt_helper' => 'researcher_prompt_helper',
    'save_briefing_prompt' => 'save_researcher_prompt',
    'delete_briefing_prompt' => 'delete_researcher_prompt',
    'briefing_builder' => 'researcher',
    'export_briefing' => 'export_researcher',

    // Config keys
    'briefing:system_prompt' => 'researcher:system_prompt',
    'ai_briefing_prompts' => 'ai_researcher_prompts',
    'briefing:max_context_entries' => 'researcher:max_context_entries',

    // Wording and labels
    'AI Briefing Builder' => 'AI Researcher',
    'Briefing Builder' => 'Researcher',
    'Briefing' => 'Researcher',
    'briefing' => 'researcher',
    'Briefings' => 'Researchers',
    'briefings' => 'researchers',
];

// We want to scan PHP, JS, CSS, SQL, and Markdown files in the project.
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->isDir()) {
        continue;
    }
    $path = $file->getRealPath();
    // Skip .git, vendor, and this script
    if (str_contains($path, '/.git/') || str_contains($path, '/vendor/') || str_contains($path, '/scratch/')) {
        continue;
    }

    $ext = pathinfo($path, PATHINFO_EXTENSION);
    if (!in_array($ext, ['php', 'js', 'css', 'sql', 'md', 'html'], true)) {
        continue;
    }

    $content = file_get_contents($path);
    $original = $content;

    foreach ($replacements as $search => $replace) {
        $content = str_replace($search, $replace, $content);
    }

    if ($content !== $original) {
        file_put_contents($path, $content);
        echo "Updated: " . str_replace($dir . '/', '', $path) . "\n";
    }
}
echo "Done replacing text!\n";
