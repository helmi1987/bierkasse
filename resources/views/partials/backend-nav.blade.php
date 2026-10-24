{{-- Kassier pages (kassiers and admins) or the admin-only pages ("Admin"), in the order of the account; see BackendNav. --}}
@php($navGroup = \App\Support\BackendNav::isAdminPage() ? \App\Support\BackendNav::BACKEND : \App\Support\BackendNav::KASSIER)
<nav class="subnav" aria-label="{{ __('backend.title') }}">
    @foreach (\App\Support\BackendNav::order($navGroup, auth()->user()) as $key)
        @php([$navRoute, $navActive] = \App\Support\BackendNav::ITEMS[$navGroup][$key])
        @php($unreadFeedback = $key === 'feedback' ? \App\Models\Feedback::unreadCount() : 0)
        {{-- Pending deposits, payouts and carry-overs are handled on the overview tab. --}}
        @php($pendingItems = $key === 'dashboard' ? \App\Services\Ledger::pendingCount() : 0)
        <a href="{{ route($navRoute) }}" @class(['active' => request()->routeIs($navActive)])>{{ __('backend.nav.'.$key) }}@if ($key === 'stock' && \App\Services\BeerStock::isLow()) <span class="pill warn">{{ __('stock.low_badge') }}</span>@endif @if ($pendingItems > 0)<span class="badge" title="{{ __('site.open_items', ['count' => $pendingItems]) }}">{{ $pendingItems }}</span>@endif @if ($unreadFeedback > 0)<span class="badge" title="{{ __('feedback.unread_count', ['count' => $unreadFeedback]) }}">{{ $unreadFeedback }}</span>@endif</a>
    @endforeach
</nav>
