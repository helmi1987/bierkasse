@extends('layouts.app')

@section('title', __('guest.qr_title'))
@section('body_class', 'printable')

@section('content')
<div class="no-print">
    @include('partials.backend-nav')
    <h1>{{ __('guest.qr') }}</h1>
    @unless ($open)
        <div class="alert bad">{{ __('guest.qr_closed') }}</div>
    @endunless
    <section class="card stack">
        <p class="hint">{{ __('guest.qr_codes_intro') }}</p>
        @if ($admin)
            <p><a class="btn small" href="{{ route('backend.qr-admin') }}">{{ __('qr.manage_link') }} →</a></p>
        @endif
        @if ($active->isEmpty())
            <p class="muted">{{ $admin ? __('qr.none_active') : __('qr.none_kassier') }}</p>
        @endif
        <ul class="list">
            @foreach ($active as $code)
                <li class="wrap-row" data-testid="guest-code">
                    <span>
                        <strong>{{ $code->displayName() }}</strong>
                        @if ($selected && $selected->id === $code->id)<span class="pill ok">{{ __('settings.codes_shown') }}</span>@endif
                        <br><small class="muted">{{ __('qr.meta', ['date' => local_time($code->created_at), 'user' => $code->creator?->username ?? '–']) }}</small>
                    </span>
                    <span class="row-buttons">
                        <a href="{{ route('backend.guests.qr', ['qr' => $code->id]) }}" class="btn small">{{ __('settings.codes_show') }}</a>
                    </span>
                </li>
            @endforeach
            <li class="wrap-row">
                <span>
                    <strong>{{ __('guest.qr_without_code') }}</strong>
                    @if (! $selected)<span class="pill ok">{{ __('settings.codes_shown') }}</span>@endif
                    <br><small class="muted">{{ __('guest.qr_without_code_hint') }}</small>
                </span>
                <a href="{{ route('backend.guests.qr', ['qr' => 0]) }}" class="btn small">{{ __('settings.codes_show') }}</a>
            </li>
        </ul>
    </section>
    <div class="row-buttons">
        <button type="button" class="btn" data-print>{{ __('settings.qr_print') }}</button>
    </div>
</div>

<section class="print-sheet">
    <img src="{{ asset('logo.svg') }}" alt="" width="96" height="96">
    <h1>{{ __('guest.qr_title') }}</h1>
    <p class="lead">{{ $appName }} · {{ __('guest.qr_intro') }}</p>
    <img src="{{ $qr }}" alt="QR" width="320" height="320" class="qr big">
    @unless ($selected)
        <p class="url">{{ $url }}</p>
    @endunless
</section>
@endsection
