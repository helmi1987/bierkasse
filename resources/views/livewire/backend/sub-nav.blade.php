{{-- Admin sub-navigation with live badges (Livewire pages; refreshed every 10 s). No ".visible": the bar is at the top
     of the page and scrolled out of view most of the time, the badges must follow anyway (Livewire pauses in hidden tabs). --}}
<nav class="subnav" aria-label="{{ __('backend.title') }}" wire:poll.10s>
    @include('partials.backend-nav-items', ['navGroup' => $group, 'activeRoute' => $activeRoute, 'pendingItems' => $pendingItems, 'unreadFeedback' => $unreadFeedback, 'stockLow' => $stockLow])
</nav>
