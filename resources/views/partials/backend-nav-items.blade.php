{{-- Tabs of the admin sub-navigation (inside <nav class="subnav">): partials/backend-nav for classic pages,
     App\Livewire\Backend\SubNav for Livewire pages (badges refreshed by polling). Expects $navGroup
     (BackendNav::KASSIER or BACKEND), $activeRoute (route name of the page, null if none), $pendingItems,
     $unreadFeedback and $stockLow. --}}
@foreach (\App\Support\BackendNav::order($navGroup, auth()->user()) as $key)
    @php([$navRoute, $navActive] = \App\Support\BackendNav::ITEMS[$navGroup][$key])
    {{-- Pending deposits, payouts and carry-overs are handled on the overview tab. --}}
    @php($navPending = $key === 'dashboard' ? $pendingItems : 0)
    @php($navUnread = $key === 'feedback' ? $unreadFeedback : 0)
    <a href="{{ route($navRoute) }}" @class(['active' => $activeRoute !== null && \Illuminate\Support\Str::is($navActive, $activeRoute)])>{{ __('backend.nav.'.$key) }}@if ($key === 'stock' && $stockLow) <span class="pill warn">{{ __('stock.low_badge') }}</span>@endif @if ($navPending > 0)<span class="badge" title="{{ __('site.open_items', ['count' => $navPending]) }}">{{ $navPending }}</span>@endif @if ($navUnread > 0)<span class="badge" title="{{ __('feedback.unread_count', ['count' => $navUnread]) }}">{{ $navUnread }}</span>@endif</a>
@endforeach
