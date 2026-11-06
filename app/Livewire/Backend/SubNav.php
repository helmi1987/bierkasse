<?php

namespace App\Livewire\Backend;

use App\Livewire\Concerns\AuthorizesAdminArea;
use App\Models\Feedback;
use App\Services\BeerStock;
use App\Services\Ledger;
use App\Support\BackendNav;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Sub-navigation of the admin area on Livewire pages (1.36.0): the badges (pending items on "Übersicht",
 * unread feedback, "Lager" running low) are refreshed by polling (three cheap counts, no change marker needed).
 * Group and active tab are fixed at mount: an update request has no page route.
 */
class SubNav extends Component
{
    use AuthorizesAdminArea;

    /** BackendNav::KASSIER or BackendNav::BACKEND. */
    public string $group = BackendNav::KASSIER;

    /** Route name of the page (compared with the tab patterns of BackendNav::ITEMS). */
    public ?string $activeRoute = null;

    public function mount(): void
    {
        $this->group = BackendNav::isAdminPage() ? BackendNav::BACKEND : BackendNav::KASSIER;
        $this->activeRoute = request()->route()?->getName();
    }

    public function render(): View
    {
        $kassier = $this->group === BackendNav::KASSIER;

        return view('livewire.backend.sub-nav', [
            'pendingItems' => $kassier ? Ledger::pendingCount() : 0,
            'unreadFeedback' => $kassier ? 0 : Feedback::unreadCount(),
            'stockLow' => $kassier && BeerStock::isLow(),
        ]);
    }
}
