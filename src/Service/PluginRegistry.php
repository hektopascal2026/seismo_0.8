<?php

declare(strict_types=1);

namespace Seismo\Service;

use Seismo\Plugin\LexEu\LexEuPlugin;
use Seismo\Plugin\LexFedlex\LexFedlexPlugin;
use Seismo\Plugin\LexJus\LexJusPlugin;
use Seismo\Plugin\LexLegifrance\LexLegifrancePlugin;
use Seismo\Plugin\LexRechtBund\LexRechtBundPlugin;
use Seismo\Plugin\ParlCh\ParlChPlugin;

/**
 * Explicit plugin list — no filesystem scanning (see architecture rules).
 *
 * Order here is the order RefreshAllService::runAll() iterates. Cheap /
 * fast plugins first so a slow upstream doesn't delay everything else.
 */
final class PluginRegistry
{
    /** @var array<string, SourceFetcherInterface> */
    private array $plugins;

    public function __construct()
    {
        $this->plugins = [
            'fedlex'      => new LexFedlexPlugin(),
            'lex_eu'      => new LexEuPlugin(),
            'recht_bund'  => new LexRechtBundPlugin(),
            'legifrance'  => new LexLegifrancePlugin(),
            'jus_bger'    => new LexJusPlugin('jus_bger', 'Jus: BGer', 'ch_bger', 'CH_BGer'),
            'jus_bge'     => new LexJusPlugin('jus_bge', 'Jus: BGE', 'ch_bge', 'CH_BGE'),
            'jus_bvger'   => new LexJusPlugin('jus_bvger', 'Jus: BVGer', 'ch_bvger', 'CH_BVGer'),
            'parl_ch'     => new ParlChPlugin(),
        ];
    }

    public function get(string $identifier): ?SourceFetcherInterface
    {
        return $this->plugins[$identifier] ?? null;
    }

    public function has(string $identifier): bool
    {
        return isset($this->plugins[$identifier]);
    }

    /**
     * @return array<string, SourceFetcherInterface>
     */
    public function all(): array
    {
        return $this->plugins;
    }
}
