@php
    $nonce = \Illuminate\Support\Facades\Vite::cspNonce();
    $appName ??= \App\Support\AppSettings::appName();
    $openItems ??= 0;
    $authUser = auth()->user();
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
    <script src="{{ asset('js/app.js') }}?v={{ @filemtime(public_path('js/app.js')) }}" nonce="{{ $nonce }}" defer></script>
</head>
<body class="@yield('body_class')">
<a class="skip-link" href="#main">{{ __('site.skip_to_content') }}</a>
@if ($authUser)
    <header class="topbar">
        <div class="wrap topbar-inner">
            <a class="brand" href="{{ route('account.dashboard') }}">
                <img src="{{ asset('logo.svg') }}" alt="" width="32" height="32">
                <span>{{ $appName }}</span>
            </a>
            <nav class="nav" aria-label="Hauptnavigation">
                <a href="{{ route('account.dashboard') }}" @class(['active' => request()->routeIs('account.dashboard', 'account.history')])>{{ __('site.nav.account') }}</a>
                <a href="{{ route('stats') }}" @class(['active' => request()->routeIs('stats')])>{{ __('site.nav.stats') }}</a>
                @if ($authUser->isKassier())
                    <a href="{{ route('backend.dashboard') }}" @class(['active' => request()->routeIs('backend.*')])>
                        {{ __('site.nav.backend') }}
                        @if ($openItems > 0)
                            <span class="badge" title="{{ __('site.open_items', ['count' => $openItems]) }}">{{ $openItems }}</span>
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
    @include('partials.alerts')
    @yield('content')
</main>
</body>
</html>
