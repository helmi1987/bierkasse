<?php

namespace App\Livewire\Concerns;

use App\Support\AssetVersion;

/**
 * Deploy while a page is open (1.34.0): the component remembers the CSS/JS version of the page it was rendered
 * with; when a poll finds a newer one on the server, the browser event "assets-changed" is dispatched once and
 * public/js/live.js shows the "Neue Version" bar (reload only outside of user input, never mid-use).
 */
trait WatchesAssets
{
    public string $assets = '';

    public bool $assetsNotified = false;

    protected function rememberAssets(): void
    {
        $this->assets = AssetVersion::current();
    }

    protected function checkAssets(): void
    {
        if ($this->assetsNotified || AssetVersion::current() === $this->assets) {
            return;
        }
        $this->assetsNotified = true;
        $this->dispatch('assets-changed');
    }
}
