@extends('layouts.app')

@section('title', __('account.settings'))

@section('content')
<h1>{{ __('account.settings') }}</h1>

<div class="grid-2">
    <section class="card">
        <h2>{{ __('account.profile_heading') }}</h2>
        <form method="post" action="{{ route('account.profile') }}" class="stack">
            @csrf
            @method('PUT')
            <div class="field">
                <label>{{ __('account.username') }}</label>
                <input type="text" value="{{ $user->username }}" disabled>
            </div>
            <div class="grid-2">
                <div class="field">
                    <label for="first_name">{{ __('account.first_name') }}</label>
                    <input type="text" id="first_name" name="first_name" value="{{ old('first_name', $user->first_name) }}" maxlength="100" autocomplete="given-name">
                </div>
                <div class="field">
                    <label for="last_name">{{ __('account.last_name') }}</label>
                    <input type="text" id="last_name" name="last_name" value="{{ old('last_name', $user->last_name) }}" maxlength="100" autocomplete="family-name">
                </div>
            </div>
            <div class="field">
                <label for="email">{{ __('account.email') }}</label>
                <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" autocomplete="email">
                <div class="hint">{{ __('account.email_hint') }}</div>
            </div>
            <button type="submit" class="btn">{{ __('account.save') }}</button>
        </form>
    </section>

    <section class="card" id="passwort">
        <h2>{{ __('account.password_heading') }}</h2>
        <form method="post" action="{{ route('account.password') }}" class="stack">
            @csrf
            @method('PUT')
            <input type="text" name="username" value="{{ $user->username }}" autocomplete="username" hidden>
            @if ($user->password)
                <div class="field">
                    <label for="current_password">{{ __('account.password_current') }}</label>
                    <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                </div>
            @endif
            <div class="field">
                <label for="password">{{ __('account.password_new') }}</label>
                <input type="password" id="password" name="password" autocomplete="new-password" minlength="8" required>
            </div>
            <div class="field">
                <label for="password_confirmation">{{ __('account.password_repeat') }}</label>
                <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="8" required>
            </div>
            <button type="submit" class="btn">{{ __('account.password_submit') }}</button>
        </form>
    </section>
</div>

<section class="card">
    <h2>{{ __('account.stats_heading') }}</h2>
    <form method="post" action="{{ route('account.stats') }}" class="stack">
        @csrf
        <label class="switch">
            <input type="checkbox" name="show" value="1" @checked(! $user->stats_opt_out) data-autosubmit>
            <span>{{ __('account.stats_show') }}</span>
        </label>
        <p class="hint">{{ __('account.stats_hint') }}</p>
        <button type="submit" class="btn small" data-hide-js>{{ __('account.save') }}</button>
    </form>
</section>

<section class="card">
    <h2>{{ __('account.totp_heading') }}
        @if ($user->hasTotp())
            <span class="pill ok">{{ __('account.totp_active') }}</span>
        @else
            <span class="pill">{{ __('account.totp_missing') }}</span>
        @endif
    </h2>

    @if ($newCodes)
        <div class="alert warn">
            <strong>{{ __('account.recovery_heading') }}</strong>
            <p>{{ __('account.recovery_intro') }}</p>
            <pre class="codes" id="recovery-codes">{{ implode("\n", $newCodes) }}</pre>
            <button type="button" class="btn small" data-copy="#recovery-codes" data-copied="{{ __('account.copied') }}">{{ __('account.copy') }}</button>
        </div>
    @endif

    @if ($user->hasTotp())
        <p class="muted">{{ __('account.totp_since', ['date' => local_time($user->totp_confirmed_at)]) }} · {{ __('account.recovery_left', ['count' => $recoveryLeft]) }}</p>
        <div class="grid-2">
            <form method="post" action="{{ route('account.totp.recovery') }}" class="stack">
                @csrf
                <div class="field">
                    <label for="code-recovery">{{ __('account.current_code') }}</label>
                    <input type="text" id="code-recovery" name="code" inputmode="numeric" autocomplete="one-time-code" required>
                </div>
                <button type="submit" class="btn">{{ __('account.recovery_regenerate') }}</button>
            </form>
            <form method="post" action="{{ route('account.totp.disable') }}" class="stack" data-confirm="{{ __('account.totp_disable_confirm') }}">
                @csrf
                <div class="field">
                    <label for="code-disable">{{ __('account.current_code') }}</label>
                    <input type="text" id="code-disable" name="code" inputmode="numeric" autocomplete="one-time-code" required>
                </div>
                <button type="submit" class="btn danger">{{ __('account.totp_disable') }}</button>
            </form>
        </div>
    @elseif ($secret)
        <ol class="steps">
            <li>{{ __('account.totp_step_scan') }}</li>
            <li>{{ __('account.totp_step_code') }}</li>
        </ol>
        <div class="totp-setup">
            <img src="{{ $qr }}" alt="{{ __('account.qr_alt') }}" width="220" height="220" class="qr">
            <div class="stack">
                <p class="muted">{{ __('account.secret_label') }} <code class="secret">{{ trim(chunk_split($secret, 4, ' ')) }}</code></p>
                <form method="post" action="{{ route('account.totp.enable') }}" class="stack">
                    @csrf
                    <div class="field">
                        <label for="code">{{ __('account.app_code') }}</label>
                        <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" class="code-input" required>
                    </div>
                    <button type="submit" class="btn primary">{{ __('account.totp_enable') }}</button>
                </form>
                <form method="post" action="{{ route('account.totp.restart') }}">
                    @csrf
                    <button type="submit" class="linkish">{{ __('account.totp_new_secret') }}</button>
                </form>
            </div>
        </div>
    @endif
</section>

<section class="card">
    <h2>{{ __('account.identities_heading') }}</h2>
    @error('sso')
        <div class="alert bad">{{ $message }}</div>
    @enderror
    @if ($identities->isEmpty())
        <p class="muted">{{ __('account.identities_none') }}</p>
    @else
        <ul class="list">
            @foreach ($identities as $identity)
                <li>
                    <span><strong>{{ $identity->provider?->name }}</strong> {{ $identity->label }}
                        @if ($identity->last_login_at)
                            <span class="muted">· {{ __('account.last_login', ['date' => local_time($identity->last_login_at)]) }}</span>
                        @endif
                    </span>
                    <form method="post" action="{{ route('account.sso.unlink', $identity) }}" class="inline" data-confirm="{{ __('account.identity_unlink_confirm') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn small ghost">{{ __('account.identity_unlink') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
    @if ($providers->isNotEmpty())
        <div class="row-buttons">
            @foreach ($providers as $provider)
                <a class="btn sso" href="{{ route('account.sso.link', $provider->slug) }}">{{ __('account.identity_link', ['provider' => $provider->name]) }}</a>
            @endforeach
        </div>
    @endif
</section>
@endsection
