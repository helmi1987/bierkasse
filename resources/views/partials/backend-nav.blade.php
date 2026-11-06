{{-- Kassier pages (kassiers and admins) or the admin-only pages ("Admin"), in the order of the account; see BackendNav.
     Classic pages (static badges); Livewire pages use <livewire:backend.sub-nav /> (App\Livewire\Backend\SubNav). --}}
@php($navGroup = \App\Support\BackendNav::isAdminPage() ? \App\Support\BackendNav::BACKEND : \App\Support\BackendNav::KASSIER)
<nav class="subnav" aria-label="{{ __('backend.title') }}">
    @include('partials.backend-nav-items', [
        'navGroup' => $navGroup,
        'activeRoute' => request()->route()?->getName(),
        'pendingItems' => $navGroup === \App\Support\BackendNav::KASSIER ? \App\Services\Ledger::pendingCount() : 0,
        'unreadFeedback' => $navGroup === \App\Support\BackendNav::BACKEND ? \App\Models\Feedback::unreadCount() : 0,
        'stockLow' => $navGroup === \App\Support\BackendNav::KASSIER && \App\Services\BeerStock::isLow(),
    ])
</nav>
