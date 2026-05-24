<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Feed\FeedModule;

/** Media monitoring admin — {@see FeedModuleHandler} with {@see FeedModule::media()}. */
final class MediaController
{
    private function handler(): FeedModuleHandler
    {
        return new FeedModuleHandler(FeedModule::media());
    }

    public function show(): void
    {
        $this->handler()->show();
    }

    public function refreshMediaSources(): void
    {
        $this->handler()->refreshSources();
    }

    public function save(): void
    {
        $this->handler()->save();
    }

    public function preview(): void
    {
        $this->handler()->preview();
    }

    public function delete(): void
    {
        $this->handler()->delete();
    }

    public function toggleDisabled(): void
    {
        $this->handler()->toggleDisabled();
    }
}
