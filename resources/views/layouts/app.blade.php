@php
    $nonce = \Illuminate\Support\Facades\Vite::cspNonce();
    $appName ??= \App\Support\AppSettings::appName();
    $openItems ??= 0;
    $authUser = auth()->user();
    // Live refresh (public/js/live.js) only for logged-in GET pages without one-time content: after a
    // POST render, validation errors/old input or flashed secrets the page must stay as rendered.
    // Pages with Livewire components (@section('livewire')) refresh their data parts with wire:poll instead.
    $livewirePage = $__env->hasSection('livewire');
    $live = $authUser && ! $livewirePage && request()->isMethod('GET') && ! $__env->hasSection('live_off')
        && ! (isset($errors) && $errors->any()) && ! session()->hasOldInput()
        && ! session()->has('totp.recovery_codes') && ! session()->has('maintenance_output');
@endphp
<!DOCTYPE html>
<html lang="de-CH">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>@hasSection('title')@yield('title') – @endif{{ $appName }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ route('manifest') }}">
    <meta name="theme-color" content="#d97706" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1c1917" media="(prefers-color-scheme: dark)">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $appName }}">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
    <meta name="server-now" content="{{ now()->getTimestampMs() }}">
    <script src="{{ asset('js/live.js') }}?v={{ @filemtime(public_path('js/live.js')) }}" nonce="{{ $nonce }}" defer></script>
    <script src="{{ asset('js/app.js') }}?v={{ @filemtime(public_path('js/app.js')) }}" nonce="{{ $nonce }}" defer></script>
    @if ($livewirePage)
        {{-- Livewire CSP build; style and script tags carry the CSP nonce (FrontendAssets uses Vite::cspNonce()). --}}
        @livewireStyles(['nonce' => $nonce])
    @endif
</head>
<body class="@yield('body_class')" @if ($livewirePage) data-livewire-page @endif @if ($live) data-live="{{ route('live', [], false) }}" data-live-fp="{{ app()->bound(\App\Http\Middleware\CaptureLiveFingerprint::KEY) ? app(\App\Http\Middleware\CaptureLiveFingerprint::KEY) : \App\Support\LiveFingerprint::current() }}" @endif>
<a class="skip-link" href="#main">{{ __('site.skip_to_content') }}</a>
@if ($authUser)
    <header class="topbar">
        <div class="wrap topbar-inner">
            <a class="brand" href="{{ $authUser->homeUrl() }}">
                <img src="{{ asset('logo.svg') }}" alt="" width="32" height="32">
                <span>{{ $appName }}</span>
            </a>
            <nav class="nav" aria-label="Hauptnavigation">
                @if ($authUser->hasBeerAccount())
                    <a href="{{ route('account.dashboard') }}" @class(['active' => request()->routeIs('account.dashboard', 'account.history')])>{{ __('site.nav.account') }}</a>
                @elseif (\App\Support\AppSettings::invitationsAllowed())
                    {{-- Accounts without beer account have no dashboard: invite from the top bar. --}}
                    <a href="{{ route('account.invite') }}" @class(['active' => request()->routeIs('account.invite*')])>{{ __('site.nav.invite') }}</a>
                @endif
                <a href="{{ route('stats') }}" @class(['active' => request()->routeIs('stats')])>{{ __('site.nav.stats') }}</a>
                @if ($authUser->isKassier())
                    <a href="{{ route('backend.dashboard') }}" @class(['active' => \App\Support\BackendNav::isKassierPage()])>
                        {{ __('site.nav.kassier') }}
                        @if ($openItems > 0)
                            <span class="badge" title="{{ __('site.open_items', ['count' => $openItems]) }}">{{ $openItems }}</span>
                        @endif
                    </a>
                @endif
                @if ($authUser->isAdmin())
                    @php($unreadFeedback = \App\Models\Feedback::unreadCount())
                    <a href="{{ route('backend.settings') }}" @class(['active' => \App\Support\BackendNav::isAdminPage()])>
                        {{ __('site.nav.backend') }}
                        @if ($unreadFeedback > 0)
                            <span class="badge" title="{{ __('feedback.unread_count', ['count' => $unreadFeedback]) }}">{{ $unreadFeedback }}</span>
                        @endif
                    </a>
                @endif
                <a href="{{ route('account.settings') }}" @class(['active' => request()->routeIs('account.settings', 'account.leave')]) aria-label="{{ __('site.nav.settings') }}" title="{{ __('site.nav.settings') }}">
                    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5Z"/></svg>
                    <span class="nav-user">{{ $authUser->username }}</span>
                </a>
                <form method="post" action="{{ route('logout') }}" class="inline">
                    @csrf
                    <button type="submit" class="linkish" title="{{ __('site.nav.logout') }}" aria-label="{{ __('site.nav.logout') }}">
                        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4H5v16h5v-2H7V6h3V4Zm5.6 3.4L14.2 8.8 16.4 11H9v2h7.4l-2.2 2.2 1.4 1.4L20.2 12l-4.6-4.6Z"/></svg>
                    </button>
                </form>
            </nav>
        </div>
    </header>
@endif
<main id="main" class="wrap main @yield('main_class')">
    @if ($maintenanceBanner ?? false)
        <div class="alert warn" role="alert">
            <strong>{{ __('maintenance.banner') }}</strong> {{ __('maintenance.banner_text') }}
            <a href="{{ route('backend.maintenance') }}">{{ __('maintenance.banner_link') }}</a>
        </div>
    @endif
    @include('partials.install-hint')
    @if ($authUser && ! request()->routeIs('consent.*'))
        @foreach (\App\Support\LegalTexts::pendingNotices($authUser) as $noticeKind => $noticeDate)
            @continue(request()->routeIs($noticeKind === 'privacy' ? 'legal.privacy' : 'legal.terms'))
            <div class="alert ok legal-notice no-print" role="status" data-testid="legal-notice-{{ $noticeKind }}">
                <span>{{ __('legal.notice_'.$noticeKind, ['date' => $noticeDate]) }}</span>
                <a href="{{ route($noticeKind === 'privacy' ? 'legal.privacy' : 'legal.terms') }}">{{ __('legal.notice_view') }}</a>
                <form method="post" action="{{ route('legal.notice.dismiss', $noticeKind) }}" class="inline">
                    @csrf
                    <button type="submit" class="btn small">{{ __('legal.notice_ok') }}</button>
                </form>
            </div>
        @endforeach
    @endif
    @if ($authUser?->isKassier() && (string) $authUser->email === '')
        <div class="alert warn" role="alert">{{ __('account.email_missing') }} <a href="{{ route('account.settings') }}">{{ __('account.email_missing_link') }}</a></div>
    @endif
    <div class="live-keep" data-live-keep="alerts">@include('partials.alerts')</div>
    @yield('content')
</main>
@php($footerVersion = \App\Support\SystemInfo::appVersion()['version'] ?? null)
@php($footerCopyright = \App\Support\AppSettings::copyrightDisplay())
<footer class="wrap site-footer">
    <span>{{ $appName }}@if ($footerVersion) v{{ $footerVersion }}@endif</span>@if ($footerCopyright !== '')<span aria-hidden="true"> · </span><span>{{ $footerCopyright }}</span>@endif<span aria-hidden="true"> · </span><a href="{{ route('legal.privacy') }}">{{ __('legal.link.privacy') }}</a><span aria-hidden="true"> · </span><a href="{{ route('legal.terms') }}">{{ __('legal.link.terms') }}</a>
</footer>
@if ($livewirePage)
    @livewireScripts(['nonce' => $nonce])
@endif
</body>
</html>
