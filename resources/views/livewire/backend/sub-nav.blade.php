{{-- Admin sub-navigation with live badges (Livewire pages; refreshed every 10 s while visible). --}}
<nav class="subnav" aria-label="{{ __('backend.title') }}" wire:poll.10s.visible>
    @include('partials.backend-nav-items', ['navGroup' => $group, 'activeRoute' => $activeRoute, 'pendingItems' => $pendingItems, 'unreadFeedback' => $unreadFeedback, 'stockLow' => $stockLow])
</nav>
