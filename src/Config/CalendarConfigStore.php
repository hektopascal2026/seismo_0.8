<?php
/**
 * Reads / writes Leg (calendar) plugin configuration from `system_config`.
 *
 * Historical context: 0.4 and pre-Slice-5a 0.5 stored this blob in
 * `calendar_config.json` on disk. Slice 5a folded each top-level block
 * into its own `system_config` row keyed `plugin:<block>`. The public
 * API — `load()`, `save()`, `saveParlChBlock()`, `defaultConfig()` —
 * is unchanged so plugin / controller code outside this class needs no
 * edits.
 */

declare(strict_types=1);

namespace Seismo\Config;

use Seismo\Repository\SystemConfigRepository;

final class CalendarConfigStore
{
    /**
     * Top-level keys recognised by load() / save(). Aligned with the
     * `getConfigKey()` values the Leg plugins return.
     */
    private const PLUGIN_BLOCKS = ['parliament_ch'];

    private SystemConfigRepository $config;

    public function __construct(?SystemConfigRepository $config = null)
    {
        $this->config = $config ?? new SystemConfigRepository(getDbConnection());
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $defaults = $this->defaultConfig();
        $merged   = $defaults;

        foreach (self::PLUGIN_BLOCKS as $block) {
            $stored = $this->config->getJson(SystemConfigRepository::PLUGIN_PREFIX . $block, []);
            if ($stored !== []) {
                $merged[$block] = array_replace_recursive(
                    is_array($defaults[$block] ?? null) ? $defaults[$block] : [],
                    $stored
                );
            }
        }

        return $merged;
    }

    /**
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
    }

    /**
     * Replace only the `parliament_ch` block, keeping all other keys from load().
     *
     * @param array<string, mixed> $block
     */
    public function saveParlChBlock(array $block): void
    {
        $existing = $this->config->getJson(
            SystemConfigRepository::PLUGIN_PREFIX . 'parliament_ch',
            is_array($this->defaultConfig()['parliament_ch'] ?? null) ? $this->defaultConfig()['parliament_ch'] : []
        );
        $merged = array_replace_recursive($existing, $block);
        $this->config->setJson(SystemConfigRepository::PLUGIN_PREFIX . 'parliament_ch', $merged);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultConfig(): array
    {
        return [
            'parliament_ch' => [
                'enabled'          => true,
                'api_base'         => 'https://ws.parlament.ch/odata.svc',
                'language'         => 'DE',
                'lookforward_days' => 90,
                'lookback_days'    => 28,
                'limit'            => 200,
                'business_types'   => [
                    1  => 'Geschaeft des Bundesrates',
                    3  => 'Standesinitiative',
                    4  => 'Parlamentarische Initiative',
                    5  => 'Motion',
                    6  => 'Postulat',
                    8  => 'Interpellation',
                    12 => 'Einfache Anfrage',
                ],
                'notes'            => '',
            ],
        ];
    }
}
