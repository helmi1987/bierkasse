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

    <section class="card stack">
        <h2>{{ __('settings.codes_heading') }}</h2>
        <p class="hint">{{ __('settings.codes_intro') }}</p>
        <form method="post" action="{{ route('backend.registration.codes.store') }}" class="inline-form">
            @csrf
            <label for="code_label">{{ __('settings.codes_label') }}</label>
            <input type="text" id="code_label" name="label" maxlength="100" required placeholder="{{ __('settings.codes_label_placeholder') }}">
            <button type="submit" class="btn small">{{ __('settings.codes_create') }}</button>
        </form>
        @if (! $target)
            <p class="muted">{{ __('settings.codes_none') }}</p>
        @endif
        <ul class="list">
            @foreach ($codes as $code)
                <li class="wrap-row">
                    <span>
                        <strong>{{ $code->label }}</strong>
                        @if ($code->isLocked())<span class="pill">{{ __('settings.codes_status_locked') }}</span>@endif
                        @if ($target && $target['key'] === (string) $code->id)<span class="pill ok">{{ __('settings.codes_shown') }}</span>@endif
                        <br><small class="muted">{{ __('settings.codes_meta', ['user' => $code->creator?->username ?? '?', 'date' => local_time($code->created_at), 'count' => $code->users_count]) }}</small>
                    </span>
                    <span class="row-buttons">
                        @unless ($code->isLocked())
                            <a href="{{ route('backend.registration.qr', ['qr' => $code->id] + ($view === 'flyer' ? ['view' => 'flyer'] : [])) }}" class="btn small">{{ __('settings.codes_show') }}</a>
                        @endunless
                        <form method="post" action="{{ route('backend.registration.codes.lock', $code) }}" class="inline">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="locked" value="{{ $code->isLocked() ? 0 : 1 }}">
                            <button type="submit" class="btn small">{{ $code->isLocked() ? __('settings.codes_unlock') : __('settings.codes_lock') }}</button>
                        </form>
                        <form method="post" action="{{ route('backend.registration.codes.destroy', $code) }}" class="inline" data-confirm="{{ __('settings.codes_delete_confirm', ['label' => $code->label]) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn small danger">{{ __('settings.codes_delete') }}</button>
                        </form>
                    </span>
                </li>
            @endforeach
            @if ($open)
                {{-- Flyer code: only in mode "password" (open registration). --}}
                <li class="wrap-row">
                    <span>
                        <strong>{{ __('settings.flyer_code_label') }}</strong>
                        @if ($target && $target['key'] === 'flyer')<span class="pill ok">{{ __('settings.codes_shown') }}</span>@endif
                        <br><small class="muted">{{ __('settings.qr_warning') }}</small>
                    </span>
                    <span class="row-buttons">
                        <a href="{{ route('backend.registration.qr', ['qr' => 'flyer'] + ($view === 'flyer' ? ['view' => 'flyer'] : [])) }}" class="btn small">{{ __('settings.codes_show') }}</a>
                        <form method="post" action="{{ route('backend.registration.flyer-code', $view === 'flyer' ? ['view' => 'flyer'] : []) }}" class="inline" data-confirm="{{ __('settings.flyer_code_regenerate_confirm') }}">
                            @csrf
                            <button type="submit" class="btn small" title="{{ __('settings.flyer_code_regenerate_hint') }}">{{ __('settings.flyer_code_regenerate') }}</button>
                        </form>
                    </span>
                </li>
            @endif
        </ul>
    </section>

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
            @if ($target['key'] === 'flyer')
                <p class="muted">{{ __('settings.flyer_url_password_note') }}</p>
            @endif
        </section>
    @endif
@endif
@endsection
