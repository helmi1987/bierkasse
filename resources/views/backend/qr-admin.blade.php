@extends('layouts.app')

@section('title', __('qr.manage_title'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('qr.manage_title') }}</h1>
<p class="muted">{{ __('qr.manage_intro') }}</p>

<form method="post" action="{{ route('backend.qr-admin.settings') }}" class="card stack" autocomplete="off">
    @csrf
    @method('PUT')
    <h2>{{ __('qr.settings') }}</h2>
    <label class="switch"><input type="checkbox" name="cashbox_qr_enabled" value="1" @checked(old('cashbox_qr_enabled', $cashboxEnabled))> <span>{{ __('guest.cashbox_enabled') }}</span></label>
    <label class="switch"><input type="checkbox" name="guest_enabled" value="1" @checked(old('guest_enabled', $guestEnabled))> <span>{{ __('guest.enabled') }}</span></label>
    <div class="field">
        <label for="guest_password">{{ __('guest.password') }}</label>
        <input type="text" id="guest_password" name="guest_password" value="{{ old('guest_password', $guestPassword) }}" maxlength="100" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false">
        <div class="hint">{{ __('guest.password_hint') }}</div>
    </div>
    <div class="row-buttons">
        <button type="submit" class="btn primary">{{ __('guest.save') }}</button>
    </div>
</form>

@foreach ($kinds as $group)
    @php($kind = $group['kind'])
    <section class="card stack" id="qr-{{ $kind }}" data-testid="qr-admin-{{ $kind }}">
        <h2>{{ __('qr.kind.'.$kind) }}</h2>
        @if ($kind === \App\Models\QrCode::KIND_TWINT && ! $twintEnabled)
            <p class="hint">{{ __('guest.cashbox_twint_off') }}</p>
        @endif
        @if ($kind === \App\Models\QrCode::KIND_FLYER && ! $registrationOpen)
            <p class="hint">{{ __('qr.flyer_closed') }}</p>
        @endif
        @include('partials.qr.new', ['kind' => $kind, 'button' => __('qr.new')])
        @if ($group['active']->isEmpty())
            <p class="muted">{{ __('qr.none_active') }}</p>
        @endif
        <ul class="list">
            @foreach ($group['active'] as $code)
                @php($viewUrl = match ($kind) {
                    \App\Models\QrCode::KIND_GUEST => route('backend.guests.qr', ['qr' => $code->id]),
                    \App\Models\QrCode::KIND_FLYER => route('backend.registration.qr', ['qr' => 'flyer-'.$code->id]),
                    default => route('backend.guests').'#kassen-qr',
                })
                <li class="wrap-row">
                    <span>
                        <strong>{{ $code->displayName() }}</strong><br>
                        <small class="muted">{{ __('qr.meta', ['date' => local_time($code->created_at), 'user' => $code->creator?->username ?? '–']) }}</small>
                    </span>
                    <span class="row-buttons">
                        <a class="btn small" href="{{ $viewUrl }}">{{ __('qr.view') }}</a>
                        @include('partials.qr.manage', ['code' => $code])
                    </span>
                </li>
            @endforeach
        </ul>
        @include('partials.qr.history', ['history' => $group['history']])
    </section>
@endforeach

<section class="card stack" id="qr-registration">
    <h2>{{ __('qr.kind.registration') }}</h2>
    <p class="hint">{{ __('settings.codes_intro') }}</p>
    <form method="post" action="{{ route('backend.registration.codes.store') }}" class="inline-form">
        @csrf
        <label for="code_label">{{ __('settings.codes_label') }}</label>
        <input type="text" id="code_label" name="label" maxlength="100" required placeholder="{{ __('settings.codes_label_placeholder') }}">
        <button type="submit" class="btn small">{{ __('settings.codes_create') }}</button>
    </form>
    <ul class="list">
        @foreach ($registrationCodes as $code)
            <li class="wrap-row">
                <span>
                    <strong>{{ $code->label }}</strong>
                    @if ($code->isLocked())<span class="pill">{{ __('settings.codes_status_locked') }}</span>@endif
                    <br><small class="muted">{{ __('settings.codes_meta', ['user' => $code->creator?->username ?? '?', 'date' => local_time($code->created_at), 'count' => $code->users_count]) }}</small>
                </span>
                <span class="row-buttons">
                    @unless ($code->isLocked())
                        <a class="btn small" href="{{ route('backend.registration.qr', ['qr' => $code->id]) }}">{{ __('qr.view') }}</a>
                    @endunless
                    <form method="post" action="{{ route('backend.registration.codes.lock', $code) }}" class="inline">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="locked" value="{{ $code->isLocked() ? 0 : 1 }}">
                        <button type="submit" @class(['btn', 'small', 'danger' => ! $code->isLocked()])>{{ $code->isLocked() ? __('settings.codes_unlock') : __('settings.codes_lock') }}</button>
                    </form>
                </span>
            </li>
        @endforeach
    </ul>
</section>
@endsection
