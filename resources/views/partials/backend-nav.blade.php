<nav class="subnav" aria-label="{{ __('backend.title') }}">
    <a href="{{ route('backend.dashboard') }}" @class(['active' => request()->routeIs('backend.dashboard')])>{{ __('backend.nav.dashboard') }}</a>
    <a href="{{ route('backend.cash') }}" @class(['active' => request()->routeIs('backend.cash')])>{{ __('backend.nav.cash') }}</a>
    <a href="{{ route('backend.users.index') }}" @class(['active' => request()->routeIs('backend.users.*')])>{{ __('backend.nav.users') }}</a>
    <a href="{{ route('backend.stats') }}" @class(['active' => request()->routeIs('backend.stats')])>{{ __('backend.nav.stats') }}</a>
    <a href="{{ route('backend.guests') }}" @class(['active' => request()->routeIs('backend.guests*')])>{{ __('backend.nav.guests') }}</a>
    <a href="{{ route('backend.rounds') }}" @class(['active' => request()->routeIs('backend.rounds*')])>{{ __('backend.nav.rounds') }}</a>
    <a href="{{ route('backend.invitations') }}" @class(['active' => request()->routeIs('backend.invitations*')])>{{ __('backend.nav.invitations') }}</a>
    <a href="{{ route('backend.stock') }}" @class(['active' => request()->routeIs('backend.stock*')])>{{ __('backend.nav.stock') }}@if (\App\Services\BeerStock::isLow()) <span class="pill warn">{{ __('stock.low_badge') }}</span>@endif</a>
    <a href="{{ route('backend.deposit-suggestions') }}" @class(['active' => request()->routeIs('backend.deposit-suggestions')])>{{ __('backend.nav.deposit_suggestions') }}</a>
    @if (auth()->user()->isAdmin())
        <a href="{{ route('backend.settings') }}" @class(['active' => request()->routeIs('backend.settings')])>{{ __('backend.nav.settings') }}</a>
        <a href="{{ route('backend.sso.index') }}" @class(['active' => request()->routeIs('backend.sso.*')])>{{ __('backend.nav.sso') }}</a>
        <a href="{{ route('backend.registration.qr') }}" @class(['active' => request()->routeIs('backend.registration.qr')])>{{ __('backend.nav.qr') }}</a>
        @php($unreadFeedback = \App\Models\Feedback::unreadCount())
        <a href="{{ route('backend.feedback') }}" @class(['active' => request()->routeIs('backend.feedback*')])>{{ __('backend.nav.feedback') }}@if ($unreadFeedback > 0) <span class="badge" title="{{ __('feedback.unread_count', ['count' => $unreadFeedback]) }}">{{ $unreadFeedback }}</span>@endif</a>
        <a href="{{ route('backend.audit') }}" @class(['active' => request()->routeIs('backend.audit*')])>{{ __('backend.nav.audit') }}</a>
        <a href="{{ route('backend.maintenance') }}" @class(['active' => request()->routeIs('backend.maintenance*')])>{{ __('backend.nav.maintenance') }}</a>
    @endif
</nav>
