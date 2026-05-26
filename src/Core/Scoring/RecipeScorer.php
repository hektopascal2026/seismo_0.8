<?php
/**
 * Deterministic recipe-based scorer.
 *
 * Pure-function port of 0.4's `scoreEntryWithRecipe()` (config.php). Given a
 * recipe JSON (keywords, source_weights, classes, class_weights) and the
 * title + body text of an entry, it returns a `relevance_score`, predicted
 * label, and an explanation of the top contributing features.
 *
 * Largely a port of 0.4's `scoreEntryWithRecipe()` (config.php). Magnitu's
 * distiller should stay aligned on tokenisation and softmax; deliberate PHP-only
 * divergences (n-gram window, once-per-document keyword hits) are documented below.
 */

declare(strict_types=1);

namespace Seismo\Core\Scoring;

final class RecipeScorer
{
    public const DEFAULT_CLASSES = ['investigation_lead', 'important', 'background', 'noise'];
    public const DEFAULT_CLASS_WEIGHTS = [1.0, 0.66, 0.33, 0.0];

    /**
     * Normalised CLIR expansion map: German keyword (lowercase) → translated tokens (lowercase).
     * Loaded once per PHP process from {@see SWISS_DICTIONARY_PATH}.
     *
     * @var array<string, list<string>>|null
     */
    private static ?array $swissDictionary = null;

    private const SWISS_DICTIONARY_PATH = '/config/swiss_dictionary.json';

    /**
     * Upper bound for n-gram phrases (unigrams through this many words).
     *
     * Set to **3** intentionally — see README section "Scoring tuning (May 2026)".
     *
     * Magnitu's distiller currently emits some 4- and 5-grams (e.g.
     * `"category bekanntmachung bekanntmachung"`, `"english tight query"`). Those
     * tend to be boilerplate matches that fire on every article from a given
     * source, fragment the softmax denominator, and pull relevance toward the
     * 0.4975 "no-signal" attractor of the formula
     * `Σ P(class_i) × class_weight_i` with `class_weights = [1.0, 0.66, 0.33, 0]`.
     *
     * Trigrams cover every signal-bearing concept in the current recipe
     * (e.g. `"member states only"`, `"third country"`, `"eu eea"`,
     * `"equivalence decision"`) without that dilution. Keep in mind this
     * intentionally diverges from Magnitu's distiller token window — the
     * deterministic Seismo fallback is allowed to be a more conservative
     * subset of the same feature space.
     */
    private const MAX_NGRAM = 3;

    /**
     * Each recipe keyword (unigram or n-gram token) may contribute at most once per
     * document. Without this, full-text RSS hydration repeats the same logits on
     * every occurrence and softmax collapses to ~100% relevance on long articles.
     */
    private const MAX_HITS_PER_TOKEN = 1;

    /**
     * Score one entry. Returns null when the recipe is missing / empty
     * (caller should treat as "unscored"), else the 0.4-shaped dictionary.
     *
     * @param array<string, mixed> $recipe Decoded recipe JSON.
     * @return array{
     *   relevance_score: float,
     *   predicted_label: string,
     *   explanation: array{top_features: array<int, array<string, mixed>>, confidence: float, prediction: string}
     * }|null
     */
    public static function score(array $recipe, string $title, string $content, string $sourceType = ''): ?array
    {
        if ($recipe === [] || empty($recipe['keywords'])) {
            return null;
        }

        /** @var array<int, string> $classes */
        $classes       = $recipe['classes']        ?? self::DEFAULT_CLASSES;
        /** @var array<int, float> $classWeights */
        $classWeights  = $recipe['class_weights']  ?? self::DEFAULT_CLASS_WEIGHTS;
        /** @var array<string, array<string, float>> $keywords */
        $keywords      = self::expandRecipeKeywords($recipe['keywords'] ?? []);
        /** @var array<string, array<string, float>> $sourceWeights */
        $sourceWeights = $recipe['source_weights'] ?? [];

        $text  = mb_strtolower(trim($title . ' ' . $content));
        $words = preg_split(
            '/[^a-zA-ZäöüàéèêïôùûçÄÖÜÀÉÈÊÏÔÙÛÇß0-9]+/u',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];

        // Unigrams through MAX_NGRAM-grams (space-joined), same shape as recipe/dictionary keys.
        // MAX_NGRAM = 3 (intentional 5 → 3 rollback, see class docblock).
        $tokens = [];
        $count  = count($words);
        for ($i = 0; $i < $count; $i++) {
            $maxSpan = min(self::MAX_NGRAM, $count - $i);
            $chunk   = $words[$i];
            $tokens[] = $chunk;
            for ($span = 2; $span <= $maxSpan; $span++) {
                $chunk   .= ' ' . $words[$i + $span - 1];
                $tokens[] = $chunk;
            }
        }

        $classScores   = array_fill_keys($classes, 0.0);
        $topFeatures   = [];
        $tokenHitCount = [];

        foreach ($tokens as $token) {
            if (!isset($keywords[$token])) {
                continue;
            }
            $hits = $tokenHitCount[$token] ?? 0;
            if ($hits >= self::MAX_HITS_PER_TOKEN) {
                continue;
            }
            $tokenHitCount[$token] = $hits + 1;

            foreach ($keywords[$token] as $class => $weight) {
                if (!isset($classScores[$class])) {
                    continue;
                }
                $classScores[$class] += (float)$weight;
                if (!isset($topFeatures[$token])) {
                    $topFeatures[$token] = ['feature' => $token, 'weight' => 0.0, 'class' => $class];
                }
                $topFeatures[$token]['weight'] += (float)$weight;
            }
        }

        if ($sourceType !== '' && isset($sourceWeights[$sourceType])) {
            foreach ($sourceWeights[$sourceType] as $class => $weight) {
                if (isset($classScores[$class])) {
                    $classScores[$class] += (float)$weight;
                }
            }
        }

        // Softmax — subtract max for numerical stability (same as 0.4).
        $maxScore = $classScores === [] ? 0.0 : max($classScores);
        $expScores = [];
        $expSum    = 0.0;
        foreach ($classes as $class) {
            $e = exp(($classScores[$class] ?? 0.0) - $maxScore);
            $expScores[$class] = $e;
            $expSum           += $e;
        }

        $probabilities = [];
        foreach ($classes as $class) {
            $probabilities[$class] = $expSum > 0
                ? $expScores[$class] / $expSum
                : 1.0 / max(count($classes), 1);
        }

        $relevance = 0.0;
        foreach ($classes as $i => $class) {
            $relevance += $probabilities[$class] * (float)($classWeights[$i] ?? 0);
        }

        $predictedLabel = $classes[0] ?? 'noise';
        $maxProb = 0.0;
        foreach ($probabilities as $class => $prob) {
            if ($prob > $maxProb) {
                $maxProb = $prob;
                $predictedLabel = $class;
            }
        }

        usort($topFeatures, static fn ($a, $b) => abs($b['weight']) <=> abs($a['weight']));
        $explanation = array_slice(array_values($topFeatures), 0, 5);
        foreach ($explanation as &$feat) {
            $feat['direction'] = ($feat['weight'] ?? 0) >= 0 ? 'positive' : 'negative';
            $feat['weight']    = round((float)($feat['weight'] ?? 0), 3);
        }
        unset($feat);

        return [
            'relevance_score' => round($relevance, 4),
            'predicted_label' => $predictedLabel,
            'explanation' => [
                'top_features' => $explanation,
                'confidence'   => round($maxProb, 3),
                'prediction'   => $predictedLabel,
            ],
        ];
    }

    /**
     * In-memory dictionary expansion: for each recipe keyword, keep the original
     * class weights and add the same weights under every translated token from
     * {@see SWISS_DICTIONARY_PATH}. Duplicate translation keys merge by summing
     * per-class weights.
     *
     * @param array<string, array<string, float>> $baseKeywords
     * @return array<string, array<string, float>>
     */
    private static function expandRecipeKeywords(array $baseKeywords): array
    {
        $dict = self::loadSwissDictionary();
        if ($dict === []) {
            return $baseKeywords;
        }

        $expanded = $baseKeywords;
        foreach ($baseKeywords as $keyword => $classWeights) {
            $lookup = mb_strtolower((string)$keyword, 'UTF-8');
            if (!isset($dict[$lookup])) {
                continue;
            }
            foreach ($dict[$lookup] as $translated) {
                if ($translated === '' || $translated === $lookup) {
                    continue;
                }
                if (!isset($expanded[$translated])) {
                    $expanded[$translated] = $classWeights;
                    continue;
                }
                foreach ($classWeights as $class => $w) {
                    if (!isset($expanded[$translated][$class])) {
                        $expanded[$translated][$class] = 0.0;
                    }
                    $expanded[$translated][$class] += (float)$w;
                }
            }
        }

        return $expanded;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function loadSwissDictionary(): array
    {
        if (self::$swissDictionary !== null) {
            return self::$swissDictionary;
        }

        $path = dirname(__DIR__, 3) . self::SWISS_DICTIONARY_PATH;
        if (!is_readable($path)) {
            self::$swissDictionary = [];
            return self::$swissDictionary;
        }

        $json = file_get_contents($path);
        if ($json === false || $json === '') {
            self::$swissDictionary = [];
            return self::$swissDictionary;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            self::$swissDictionary = [];
            return self::$swissDictionary;
        }

        $normalized = [];
        foreach ($data as $german => $translations) {
            if (!is_array($translations)) {
                continue;
            }
            $key = mb_strtolower((string)$german, 'UTF-8');
            $words = [];
            foreach ($translations as $t) {
                $w = mb_strtolower((string)$t, 'UTF-8');
                if ($w !== '') {
                    $words[] = $w;
                }
            }
            if ($words !== []) {
                $normalized[$key] = $words;
            }
        }

        self::$swissDictionary = $normalized;
        return self::$swissDictionary;
    }
}
