@extends('layouts.app')

@section('title', __('settings.qr_title'))
@section('body_class', 'printable'.($view === 'flyer' ? ' print-flyer' : ''))

@section('content')
@php
    $codeParam = $withCode ? [] : ['code' => 0];
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
    </div>
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
    @if ($view === 'flyer')
        <p class="muted">{{ __('settings.flyer_hint') }}</p>
    @endif
</div>

@if ($view === 'flyer')
    <div class="flyer-frame">
        <article class="flyer" aria-label="{{ __('settings.qr_tab_flyer') }}">
            <header class="flyer-band">
                <img src="{{ asset('logo.svg') }}" alt="" width="64" height="64">
                <span>{{ $appName }}</span>
            </header>
            <div class="flyer-body">
                <h1 class="flyer-headline">{{ __('settings.flyer_headline') }}</h1>
                <p class="flyer-subline">{{ __('settings.flyer_subline') }}</p>

                <ol class="flyer-steps" aria-label="{{ __('settings.flyer_steps_title') }}">
                    <li>
                        <strong>{{ __('settings.flyer_step1_title') }}</strong>
                        <span>{{ __('settings.flyer_step1_text') }}</span>
                    </li>
                    <li>
                        <strong>{{ __('settings.flyer_step2_title') }}</strong>
                        <span>{{ $withCode ? __('settings.flyer_step2_text') : ($mode === \App\Support\AppSettings::MODE_INVITE ? __('settings.flyer_step2_text_invite') : __('settings.flyer_step2_text_no_code')) }}</span>
                    </li>
                    <li>
                        <strong>{{ __('settings.flyer_step3_title') }}</strong>
                        <span>{{ __('settings.flyer_step3_text') }}</span>
                    </li>
                </ol>

                <div class="flyer-qr">
                    <p class="flyer-scan">{{ __('settings.flyer_scan') }}</p>
                    <img src="{{ $qr }}" alt="QR" width="480" height="480">
                    <p class="flyer-or">{{ __('settings.flyer_or_open') }}</p>
                    <p class="flyer-url">{{ $url }}</p>
                    @if ($open)
                        <p class="flyer-or">{{ __('settings.flyer_url_password_note') }}</p>
                    @endif
                </div>

                <p class="flyer-joke">{{ __('settings.flyer_joke') }}</p>
            </div>
            <footer class="flyer-footer">
                <span>{{ __('settings.flyer_install') }}</span>
                <span>{{ __('settings.flyer_questions') }}</span>
            </footer>
        </article>
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
