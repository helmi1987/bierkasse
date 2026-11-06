{{-- Entries of the top navigation (inside <nav class="nav">): layout for classic pages, App\Livewire\TopNav for
     Livewire pages of kassiers (badges refreshed by polling). Expects $authUser, $active (App\Support\TopNav::activeKey()),
     $openItems and $unreadFeedback. --}}
@if ($authUser->hasBeerAccount())
    <a href="{{ route('account.dashboard') }}" @class(['active' => $active === 'account'])>{{ __('site.nav.account') }}</a>
@elseif (\App\Support\AppSettings::invitationsAllowed())
    {{-- Accounts without beer account have no dashboard: invite from the top bar. --}}
    <a href="{{ route('account.invite') }}" @class(['active' => $active === 'invite'])>{{ __('site.nav.invite') }}</a>
@endif
@if (\App\Support\AppSettings::statsEnabled())
    <a href="{{ route('stats') }}" @class(['active' => $active === 'stats'])>{{ __('site.nav.stats') }}</a>
@endif
@if ($authUser->isKassier())
    <a href="{{ route('backend.dashboard') }}" @class(['active' => $active === 'kassier'])>
        {{ __('site.nav.kassier') }}
        @if ($openItems > 0)
            <span class="badge" title="{{ __('site.open_items', ['count' => $openItems]) }}">{{ $openItems }}</span>
        @endif
    </a>
@endif
@if ($authUser->isAdmin())
    <a href="{{ route('backend.settings') }}" @class(['active' => $active === 'backend'])>
        {{ __('site.nav.backend') }}
        @if ($unreadFeedback > 0)
            <span class="badge" title="{{ __('feedback.unread_count', ['count' => $unreadFeedback]) }}">{{ $unreadFeedback }}</span>
        @endif
    </a>
@endif
<a href="{{ route('account.settings') }}" @class(['active' => $active === 'settings']) aria-label="{{ __('site.nav.settings') }}" title="{{ __('site.nav.settings') }}">
    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5Z"/></svg>
    <span class="nav-user">{{ $authUser->username }}</span>
</a>
<form method="post" action="{{ route('logout') }}" class="inline">
    @csrf
    <button type="submit" class="linkish" title="{{ __('site.nav.logout') }}" aria-label="{{ __('site.nav.logout') }}">
        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4H5v16h5v-2H7V6h3V4Zm5.6 3.4L14.2 8.8 16.4 11H9v2h7.4l-2.2 2.2 1.4 1.4L20.2 12l-4.6-4.6Z"/></svg>
    </button>
</form>
