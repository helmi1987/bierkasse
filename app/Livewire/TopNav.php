<?php

namespace App\Livewire;

use App\Livewire\Concerns\WatchesAssets;
use App\Models\Feedback;
use App\Services\Ledger;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Top navigation of kassiers and admins on Livewire pages (1.34.0): the badges (open items, unread feedback)
 * are refreshed by polling. Plain users get the static partial (no live data in their navigation).
 * The active entry is fixed at mount: an update request has no page route.
 */
class TopNav extends Component
{
    use WatchesAssets;

    public ?string $active = null;

    public function boot(): void
    {
        abort_unless((bool) auth()->user()?->isKassier(), 403);
    }

    public function mount(?string $active = null): void
    {
        $this->active = $active;
        $this->rememberAssets();
    }

    /** wire:poll: badges are re-rendered (two cheap counts); a deploy is announced once. */
    public function tick(): void
    {
        $this->checkAssets();
    }

    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.top-nav', [
            'authUser' => $user,
            'openItems' => Ledger::pendingCount(),
            'unreadFeedback' => $user->isAdmin() ? Feedback::unreadCount() : 0,
        ]);
    }
}
