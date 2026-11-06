@php
    $nonce = \Illuminate\Support\Facades\Vite::cspNonce();
    $appName ??= \App\Support\AppSettings::appName();
    $openItems ??= 0;
    $authUser = auth()->user();
    $theme = $authUser?->fixedTheme();
    // Pages with Livewire components (@section('livewire')) refresh their data parts with wire:poll.
    $livewirePage = $__env->hasSection('livewire');
@endphp
<!DOCTYPE html>
<html lang="de-CH"@if ($theme) data-theme="{{ $theme }}"@endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>@hasSection('title')@yield('title') – @endif{{ $appName }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ route('manifest') }}">
    @if ($theme)
        <meta name="theme-color" content="{{ $theme === 'dark' ? '#1c1917' : '#d97706' }}">
    @else
        <meta name="theme-color" content="#d97706" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#1c1917" media="(prefers-color-scheme: dark)">
    @endif
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
<body class="@yield('body_class')" @if ($livewirePage) data-livewire-page data-update-text="{{ __('site.update_available') }}" data-update-button="{{ __('site.update_reload') }}" @endif>
<a class="skip-link" href="#main">{{ __('site.skip_to_content') }}</a>
@if ($authUser)
    <header class="topbar">
        <div class="wrap topbar-inner">
            <a class="brand" href="{{ $authUser->homeUrl() }}">
                <img src="{{ asset('logo.svg') }}" alt="" width="32" height="32">
                <span>{{ $appName }}</span>
            </a>
            @php($navActive = \App\Support\TopNav::activeKey())
            @if ($livewirePage && $authUser->isKassier())
                {{-- Badges refreshed by polling (1.34.0); plain users have no live data in the navigation. --}}
                <livewire:top-nav :active="$navActive" />
            @else
                <nav class="nav" aria-label="Hauptnavigation">
                    @include('partials.top-nav', ['authUser' => $authUser, 'active' => $navActive, 'openItems' => $openItems, 'unreadFeedback' => $authUser->isAdmin() ? \App\Models\Feedback::unreadCount() : 0])
                </nav>
            @endif
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
    @foreach ($secretWarnings ?? [] as $secretProvider)
        @php($secretDays = $secretProvider->secretDaysLeft())
        <div class="alert {{ $secretProvider->secretUrgency() }}" role="alert" data-testid="secret-warning-{{ $secretProvider->slug }}">
            {{ __($secretDays < 0 ? 'sso.banner_expired' : 'sso.banner_expiring', ['name' => $secretProvider->name, 'date' => $secretProvider->client_secret_expires_at->format('d.m.Y'), 'days' => $secretDays]) }}
            <a href="{{ route('backend.sso.edit', $secretProvider) }}">{{ __('sso.banner_link') }}</a>
        </div>
    @endforeach
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
    @include('partials.alerts')
    @yield('content')
</main>
@php($footerVersion = \App\Support\SystemInfo::appVersion()['version'] ?? null)
@php($footerCopyright = \App\Support\AppSettings::copyrightDisplay())
<footer class="wrap site-footer">
    <div><span class="unit">{{ $appName }}@if ($footerVersion) v{{ $footerVersion }}@endif</span>@if ($footerCopyright !== '')<span aria-hidden="true"> · </span><span class="unit">{{ $footerCopyright }}</span>@endif</div>
    <div><a class="unit" href="{{ route('legal.privacy') }}">{{ __('legal.link.privacy') }}</a><span aria-hidden="true"> · </span><a class="unit" href="{{ route('legal.terms') }}">{{ __('legal.link.terms') }}</a></div>
</footer>
@if ($livewirePage)
    @livewireScripts(['nonce' => $nonce])
@endif
</body>
</html>
