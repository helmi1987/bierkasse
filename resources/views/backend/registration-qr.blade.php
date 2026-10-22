@extends('layouts.app')

@section('title', __('settings.qr_title'))
@section('body_class', 'printable'.($view === 'flyer' ? ' print-flyer' : ''))

@section('content')
@php
    $sel = $target ? ['qr' => $target['key']] : [];
    $flyerSvg = $target ? route('backend.registration.flyer-svg', $sel) : null;
@endphp
<div class="no-print">
    @include('partials.backend-nav')
    <h1>{{ __('settings.qr_title') }}</h1>

    @php($viewQuery = $view === 'flyer' ? ['view' => 'flyer'] : [])
    <section class="card stack">
        <h2>{{ __('settings.codes_heading') }}</h2>
        <p class="hint">{{ __('settings.codes_intro') }}</p>
        @if ($admin)
            <p><a class="btn small" href="{{ route('backend.qr-admin') }}">{{ __('qr.manage_link') }} →</a></p>
        @endif
        @if ($codes->isEmpty())
            <p class="muted">{{ __('settings.codes_none') }}</p>
        @endif
        <ul class="list">
            @foreach ($codes as $code)
                <li class="wrap-row">
                    <span>
                        <strong>{{ $code->label }}</strong>
                        @if ($target && $target['key'] === (string) $code->id)<span class="pill ok">{{ __('settings.codes_shown') }}</span>@endif
                        <br><small class="muted">{{ __('settings.codes_meta', ['user' => $code->creator?->username ?? '?', 'date' => local_time($code->created_at), 'count' => $code->users_count]) }}</small>
                    </span>
                    <span class="row-buttons">
                        <a href="{{ route('backend.registration.qr', ['qr' => $code->id] + $viewQuery) }}" class="btn small">{{ __('settings.codes_show') }}</a>
                    </span>
                </li>
            @endforeach
        </ul>
    </section>

    @if ($open)
        {{-- Flyer codes: only in mode "password" (open registration). --}}
        <section class="card stack">
            <h2>{{ __('settings.flyer_codes_heading') }}</h2>
            <p class="hint">{{ __('settings.flyer_codes_intro') }} {{ __('settings.qr_warning') }}</p>
            @if ($flyerCodes->isEmpty())
                <p class="muted">{{ __('qr.none_active') }}</p>
            @endif
            <ul class="list">
                @foreach ($flyerCodes as $code)
                    <li class="wrap-row" data-testid="flyer-code">
                        <span>
                            <strong>{{ $code->displayName() }}</strong>
                            @if ($target && $target['key'] === 'flyer-'.$code->id)<span class="pill ok">{{ __('settings.codes_shown') }}</span>@endif
                            <br><small class="muted">{{ __('qr.meta', ['date' => local_time($code->created_at), 'user' => $code->creator?->username ?? '–']) }}</small>
                        </span>
                        <span class="row-buttons">
                            <a href="{{ route('backend.registration.qr', ['qr' => 'flyer-'.$code->id] + $viewQuery) }}" class="btn small">{{ __('settings.codes_show') }}</a>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($target)
        <h2>{{ $target['label'] }}</h2>
        <nav class="tabs" aria-label="{{ __('settings.qr_title') }}">
            <a href="{{ route('backend.registration.qr', $sel) }}" @class(['active' => $view === 'qr'])>{{ __('settings.qr_tab_qr') }}</a>
            <a href="{{ route('backend.registration.qr', $sel + ['view' => 'flyer']) }}" @class(['active' => $view === 'flyer'])>{{ __('settings.qr_tab_flyer') }}</a>
        </nav>
        <div class="row-buttons">
            <button type="button" class="btn" data-print>{{ $view === 'flyer' ? __('settings.flyer_print') : __('settings.qr_print') }}</button>
            @if ($view === 'flyer')
                <button type="button" class="btn" data-png-download="{{ $flyerSvg }}" data-png-filename="bierkasse-flyer.png" data-png-error="#flyer-png-error">{{ __('settings.flyer_download_png') }}</button>
                <a href="{{ route('backend.registration.flyer-svg', $sel + ['download' => 1]) }}" class="btn" download="bierkasse-flyer.svg">{{ __('settings.flyer_download_svg') }}</a>
            @endif
        </div>
        @if ($view === 'flyer')
            <p class="hint">{{ __('settings.flyer_hint') }}</p>
            <p class="alert bad" id="flyer-png-error" role="alert" hidden>{{ __('settings.flyer_png_error') }}</p>
        @endif
    @endif
</div>

@if ($target)
    @if ($view === 'flyer')
        <div class="flyer-image">
            <img src="{{ $flyerSvg }}" alt="{{ __('settings.qr_tab_flyer') }}" width="794" height="1123">
        </div>
    @else
        <section class="print-sheet">
            <img src="{{ asset('logo.svg') }}" alt="" width="96" height="96">
            <h1>{{ $appName }}</h1>
            <p class="lead">{{ __('settings.qr_intro') }}</p>
            <img src="{{ $qr }}" alt="QR" width="320" height="320" class="qr big">
            <p class="url">{{ $target['printed_url'] }}</p>
            @if ($target['flyer'])
                <p class="muted">{{ __('settings.flyer_url_password_note') }}</p>
            @endif
        </section>
    @endif
@endif
@endsection
