<?php

namespace App\Livewire\Concerns;

/**
 * Tabs of a component, kept in the URL (?tab=…) so redirects and bookmarks open the right tab.
 * The component declares TABS (first = default) and a public $tab property with #[Url(as: 'tab')].
 */
trait HasTabs
{
    public function mountHasTabs(): void
    {
        $this->tab = $this->validTab($this->tab);
    }

    public function updatedTab(): void
    {
        $this->tab = $this->validTab($this->tab);
        $this->resetPage();
    }

    public function showTab(string $tab): void
    {
        $this->tab = $this->validTab($tab);
        $this->resetPage();
        if (property_exists($this, 'notice')) {
            $this->notice = [];
        }
    }

    protected function validTab(mixed $tab): string
    {
        return in_array($tab, static::TABS, true) ? $tab : static::TABS[0];
    }
}
