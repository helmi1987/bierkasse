@extends('layouts.app')

@section('title', __('settings.qr_title'))
@section('body_class', 'printable'.($view === 'flyer' ? ' print-flyer' : ''))

@section('content')
@php
    $codeParam = $withCode ? [] : ['code' => 0];
    $flyerSvg = route('backend.registration.flyer-svg', $codeParam);
@endphp
<div class="no-print">
    @include('partials.backend-nav')
    <nav class="tabs" aria-label="{{ __('settings.qr_title') }}">
        <a href="{{ route('backend.registration.qr', $codeParam) }}" @class(['active' => $view === 'qr'])>{{ __('settings.qr_tab_qr') }}</a>
        <a href="{{ route('backend.registration.qr', $codeParam + ['view' => 'flyer']) }}" @class(['active' => $view === 'flyer'])>{{ __('settings.qr_tab_flyer') }}</a>
    </nav>
    <div class="row-buttons">
        @if ($open)
            <a href="{{ route('backend.registration.qr', $view === 'flyer' ? ['view' => 'flyer'] : []) }}" @class(['btn', 'primary' => $withCode])>{{ __('settings.qr_with_flyer_code') }}</a>
            <a href="{{ route('backend.registration.qr', ['code' => 0] + ($view === 'flyer' ? ['view' => 'flyer'] : [])) }}" @class(['btn', 'primary' => ! $withCode])>{{ __('settings.qr_without_flyer_code') }}</a>
        @endif
        <button type="button" class="btn" data-print>{{ $view === 'flyer' ? __('settings.flyer_print') : __('settings.qr_print') }}</button>
        @if ($view === 'flyer')
            <button type="button" class="btn" data-png-download="{{ $flyerSvg }}" data-png-filename="bierkasse-flyer.png" data-png-error="#flyer-png-error">{{ __('settings.flyer_download_png') }}</button>
            <a href="{{ route('backend.registration.flyer-svg', $codeParam + ['download' => 1]) }}" class="btn" download="bierkasse-flyer.svg">{{ __('settings.flyer_download_svg') }}</a>
        @endif
    </div>
    @if ($view === 'flyer')
        <p class="hint">{{ __('settings.flyer_hint') }}</p>
        <p class="alert bad" id="flyer-png-error" role="alert" hidden>{{ __('settings.flyer_png_error') }}</p>
    @endif
    @if ($withCode)
        <div class="alert warn">{{ __('settings.qr_warning') }}</div>
        <form method="post" action="{{ route('backend.registration.flyer-code', $view === 'flyer' ? ['view' => 'flyer'] : []) }}" class="inline" data-confirm="{{ __('settings.flyer_code_regenerate_confirm') }}">
            @csrf
            <button type="submit" class="btn small">{{ __('settings.flyer_code_regenerate') }}</button>
            <span class="hint">{{ __('settings.flyer_code_regenerate_hint') }}</span>
        </form>
    @endif
    @if ($mode === \App\Support\AppSettings::MODE_INVITE)
        <div class="alert warn">{{ __('settings.qr_invite_mode') }}</div>
    @elseif (! $open)
        <div class="alert bad">{{ __('settings.qr_closed') }}</div>
    @endif
</div>

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
        <p class="url">{{ $url }}</p>
        @if ($open)
            <p class="muted">{{ __('settings.flyer_url_password_note') }}</p>
        @endif
    </section>
@endif
@endsection
