<nav class="subnav" aria-label="{{ __('backend.title') }}">
    <a href="{{ route('backend.dashboard') }}" @class(['active' => request()->routeIs('backend.dashboard')])>{{ __('backend.nav.dashboard') }}</a>
    <a href="{{ route('backend.cash') }}" @class(['active' => request()->routeIs('backend.cash')])>{{ __('backend.nav.cash') }}</a>
    <a href="{{ route('backend.users.index') }}" @class(['active' => request()->routeIs('backend.users.*')])>{{ __('backend.nav.users') }}</a>
    <a href="{{ route('backend.stats') }}" @class(['active' => request()->routeIs('backend.stats')])>{{ __('backend.nav.stats') }}</a>
    <a href="{{ route('backend.deposit-suggestions') }}" @class(['active' => request()->routeIs('backend.deposit-suggestions')])>{{ __('backend.nav.deposit_suggestions') }}</a>
    @if (auth()->user()->isAdmin())
        <a href="{{ route('backend.settings') }}" @class(['active' => request()->routeIs('backend.settings')])>{{ __('backend.nav.settings') }}</a>
        <a href="{{ route('backend.sso.index') }}" @class(['active' => request()->routeIs('backend.sso.*')])>{{ __('backend.nav.sso') }}</a>
        <a href="{{ route('backend.registration.qr') }}" @class(['active' => request()->routeIs('backend.registration.qr')])>{{ __('backend.nav.qr') }}</a>
    @endif
</nav>
