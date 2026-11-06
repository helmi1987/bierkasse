{{-- Top navigation with live badges (kassiers and admins on Livewire pages; refreshed every 10 s while visible). --}}
<nav class="nav" aria-label="Hauptnavigation" wire:poll.10s.visible="tick">
    @include('partials.top-nav', ['authUser' => $authUser, 'active' => $active, 'openItems' => $openItems, 'unreadFeedback' => $unreadFeedback])
</nav>
