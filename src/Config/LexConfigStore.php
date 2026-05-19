<?php
/**
 * Reads / writes Lex plugin configuration from `system_config`.
 *
 * Historical context: 0.4 and pre-Slice-5a 0.5 stored this blob in
 * `lex_config.json` on disk. Slice 5a folded each top-level block into
 * its own `system_config` row keyed `plugin:<block>` (except the shared
 * `jus_banned_words` list, which lives at `lex:jus_banned_words`) and
 * renamed the file to `.migrated-v21` as a manual-rollback sample.
 *
 * The public API — `load()`, `save()`, `saveChBlock()`, `defaultConfig()` —
 * is unchanged so no caller outside this class needs to know the backing
 * shifted from filesystem to SQL. Internally, `load()` assembles the
 * same flat map the JSON file used to return by reading the relevant
 * rows, and `save()` decomposes the map back into per-row writes.
 */

declare(strict_types=1);

namespace Seismo\Config;

use Seismo\Repository\SystemConfigRepository;

final class LexConfigStore
{
    /**
     * Top-level keys recognised by `load()` / `save()`. Anything else in
     * the passed blob is ignored on save, and missing keys fall back to
     * `defaultConfig()`. Keep this list aligned with the `getConfigKey()`
     * values the Lex plugins return.
     */
    private const PLUGIN_BLOCKS = [
        'eu', 'ch', 'de', 'ch_bger', 'ch_bge', 'ch_bvger', 'fr',
    ];

    private const SPECIAL_BANNED_WORDS_KEY = 'lex:jus_banned_words';

    private SystemConfigRepository $config;

    public function __construct(?SystemConfigRepository $config = null)
    {
        $this->config = $config ?? new SystemConfigRepository(getDbConnection());
    }

    /**
     * Full config with defaults merged for any missing top-level keys.
     * Same shape as the 0.4 `lex_config.json` contract.
     *
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $defaults = $this->defaultConfig();
        $merged   = $defaults;

        foreach (self::PLUGIN_BLOCKS as $block) {
            $stored = $this->config->getJson(SystemConfigRepository::PLUGIN_PREFIX . $block, []);
            if ($stored !== []) {
                $baseBlock = is_array($defaults[$block] ?? null) ? $defaults[$block] : [];
                // Stored wins per key so list-shaped fields (e.g. fr.natures) replace defaults
                // entirely — array_replace_recursive would keep leftover default indices.
                $merged[$block] = array_merge($baseBlock, $stored);
            }
        }

        $bannedRaw = $this->config->get(self::SPECIAL_BANNED_WORDS_KEY);
        if ($bannedRaw !== null && trim($bannedRaw) !== '') {
            $decoded = json_decode($bannedRaw, true);
            if (is_array($decoded)) {
                $merged['jus_banned_words'] = array_values(array_filter(array_map(
                    static fn ($v) => is_string($v) ? $v : null,
                    $decoded
                )));
            }
        }

        return $merged;
    }

    /**
     * Persist the entire config. Each top-level key is upserted as its
     * own row so that per-plugin writes are later possible without
     * round-tripping the whole blob.
     *
     * @param array<string, mixed> $config
     */
    public function save(array $config): void
    {
        foreach (self::PLUGIN_BLOCKS as $block) {
            if (!array_key_exists($block, $config)) {
                continue;
            }
            $value = $config[$block];
            if (!is_array($value)) {
                continue;
            }
            $this->config->setJson(SystemConfigRepository::PLUGIN_PREFIX . $block, $value);
        }

        if (array_key_exists('jus_banned_words', $config) && is_array($config['jus_banned_words'])) {
            $this->config->set(
                self::SPECIAL_BANNED_WORDS_KEY,
                json_encode(
                    array_values($config['jus_banned_words']),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
                ) . "\n"
            );
        }
    }

    /**
     * Replace only the `ch` block, keeping all other keys from load().
     *
     * @param array<string, mixed> $chBlock
     */
    public function saveChBlock(array $chBlock): void
    {
        $this->savePluginBlock('ch', $chBlock);
    }

    /**
     * Merge a single plugin block into `system_config` (`plugin:<block>`).
     *
     * Uses {@see array_merge()} (not `array_replace_recursive`) so list-shaped
     * keys like `fr.natures` or `ch.resource_types` are replaced wholesale when
     * the admin saves a shorter list — recursive merge would keep stale indices.
     *
     * @param array<string, mixed> $partial Full block from the controller (same keys as defaults).
     */
    public function savePluginBlock(string $block, array $partial): void
    {
        if (!in_array($block, self::PLUGIN_BLOCKS, true)) {
            throw new \InvalidArgumentException('Unknown lex plugin block: ' . $block);
        }
        $defaults = $this->defaultConfig();
        $existing = $this->config->getJson(
            SystemConfigRepository::PLUGIN_PREFIX . $block,
            is_array($defaults[$block] ?? null) ? $defaults[$block] : []
        );
        $merged = array_merge($existing, $partial);
        $this->config->setJson(SystemConfigRepository::PLUGIN_PREFIX . $block, $merged);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultConfig(): array
    {
        return [
            'eu' => [
                'enabled' => true,
                'endpoint' => 'https://publications.europa.eu/webapi/rdf/sparql',
                'language' => 'ENG',
                'lookback_days' => 90,
                'limit' => 100,
                'document_class' => 'cdm:legislation_secondary',
                'notes' => '',
            ],
            'ch' => [
                'enabled' => true,
                'endpoint' => 'https://fedlex.data.admin.ch/sparqlendpoint',
                'language' => 'DEU',
                'lookback_days' => 90,
                'limit' => 100,
                // Fedlex consultation procedures (`jolux:Consultation`); persists in Fedlex Settings.
                'ingest_vernehmlassungen' => true,
                'resource_types' => [
                    ['id' => 21, 'label' => 'Bundesgesetz'],
                    ['id' => 22, 'label' => 'Dringliches Bundesgesetz'],
                    ['id' => 29, 'label' => 'Verordnung des Bundesrates'],
                    ['id' => 26, 'label' => 'Departementsverordnung'],
                    ['id' => 27, 'label' => 'Amtsverordnung'],
                    ['id' => 28, 'label' => 'Verordnung der Bundesversammlung'],
                    ['id' => 8,  'label' => 'Einfacher Bundesbeschluss (andere)'],
                    ['id' => 9,  'label' => 'Bundesbeschluss (fakultatives Referendum)'],
                    ['id' => 10, 'label' => 'Bundesbeschluss (obligatorisches Referendum)'],
                    ['id' => 31, 'label' => 'Internationaler Rechtstext bilateral'],
                    ['id' => 32, 'label' => 'Internationaler Rechtstext multilateral'],
                ],
                'notes' => '',
            ],
            'de' => [
                'enabled' => true,
                'feed_url' => 'https://www.recht.bund.de/rss/feeds/rss_bgbl-1-2.xml?nn=211452',
                'lookback_days' => 90,
                'limit' => 100,
                /** Matched case-insensitively to the derived category (title heuristics). Empty = skip none. */
                'exclude_document_types' => ['Bekanntmachung'],
                'notes' => '',
            ],
            'ch_bger' => [
                'enabled' => true,
                'base_url' => 'https://entscheidsuche.ch',
                'lookback_days' => 90,
                'limit' => 100,
                'notes' => '',
            ],
            'ch_bge' => [
                'enabled' => false,
                'base_url' => 'https://entscheidsuche.ch',
                'lookback_days' => 90,
                'limit' => 50,
                'notes' => '',
            ],
            'ch_bvger' => [
                'enabled' => true,
                'base_url' => 'https://entscheidsuche.ch',
                'lookback_days' => 90,
                'limit' => 100,
                'notes' => '',
            ],
            'fr' => [
                'enabled' => false,
                'client_id' => '',
                'client_secret' => '',
                'oauth_token_url' => 'https://oauth.piste.gouv.fr/api/oauth/token',
                'api_base_url' => 'https://api.piste.gouv.fr/dila/legifrance/lf-engine-app',
                'fond' => 'JORF',
                'natures' => ['LOI', 'ORDONNANCE', 'DECRET'],
                'lookback_days' => 90,
                'limit' => 100,
                'notes' => '',
            ],
            'jus_banned_words' => [],
        ];
    }
}
