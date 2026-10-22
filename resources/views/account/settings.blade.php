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
                <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" autocomplete="email" @required($user->isKassier())>
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

@if ($user->isKassier())
    <section class="card" id="nur-verwaltung">
        <h2>{{ __('account.admin_only') }}</h2>
        <form method="post" action="{{ route('account.admin-only') }}" class="stack">
            @csrf
            <label class="switch">
                <input type="checkbox" name="admin_only" value="1" @checked($user->isAdminOnly()) @disabled(! $adminOnlySettled) data-autosubmit>
                <span>{{ __('account.admin_only_switch') }}</span>
            </label>
            <p class="hint">{{ __('account.admin_only_hint') }}</p>
            @unless ($adminOnlySettled)
                <p class="alert warn">{{ __('account.admin_only_locked', ['amount' => chf($user->balance())]) }}</p>
            @endunless
            <button type="submit" class="btn small" data-hide-js @disabled(! $adminOnlySettled)>{{ __('account.save') }}</button>
        </form>
    </section>
@endif

@if ($user->isKassier())
    <section class="card" id="navigation">
        <h2>{{ __('backend.nav.nav') }}</h2>
        <form method="post" action="{{ route('account.nav') }}" class="stack">
            @csrf
            @method('PUT')
            <p class="hint">{{ __('backend.nav_own_intro') }}@if ($user->nav_order === null) {{ __('backend.nav_own_default') }}@endif</p>
            @php($navGroups = \App\Support\BackendNav::groups($user))
            @include('partials.nav-order', ['groups' => $navGroups, 'orders' => collect($navGroups)->mapWithKeys(fn ($g) => [$g => \App\Support\BackendNav::order($g, $user)])->all()])
            <button type="submit" class="btn small">{{ __('account.save') }}</button>
        </form>
        @if ($user->nav_order !== null)
            <form method="post" action="{{ route('account.nav.reset') }}" class="inline">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn small ghost">{{ __('backend.nav_reset') }}</button>
            </form>
        @endif
    </section>
@endif

@if ($user->hasBeerAccount())
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

@endif

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
    @endif
    @if (! $ssoActive)
        @if ($user->isAdmin())
            <p class="muted">{{ __('account.identities_admin_hint') }}</p>
            <p><a class="btn" href="{{ route('backend.sso.index') }}">{{ __('account.identities_admin_link') }}</a></p>
        @else
            <p class="muted">{{ __('account.identities_user_hint') }}</p>
        @endif
    @endif
    @if ($identities->isNotEmpty())
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
        <div class="sso-buttons">
            @foreach ($providers as $provider)
                @include('partials.sso-button', ['provider' => $provider, 'href' => route('account.sso.link', $provider->slug), 'label' => __('account.identity_link', ['provider' => $provider->name])])
            @endforeach
        </div>
    @endif
</section>

<section class="card" id="eigener-anbieter">
    <h2>{{ __('sso.personal.heading') }}
        @if ($personal)
            @if ($personalIdentity)
                <span class="pill ok">{{ __('sso.personal.connected') }}</span>
            @else
                <span class="pill">{{ __('sso.personal.not_connected') }}</span>
            @endif
        @endif
    </h2>
    @if (! $personalAllowed)
        <p class="muted">{{ __('sso.personal.disabled') }}</p>
    @else
        <p class="muted">{{ __('sso.personal.intro') }}</p>
        <p><strong>{{ __('sso.personal.redirect_uri') }}:</strong> <code id="personal-redirect-uri">{{ $personalRedirectUri }}</code>
            <button type="button" class="btn small" data-copy="#personal-redirect-uri" data-copied="{{ __('account.copied') }}">{{ __('account.copy') }}</button></p>

        @if ($personal)
            <div class="row-buttons">
                @if ($personal->isUsable())
                    <a class="btn primary" href="{{ route('account.personal-sso.connect') }}">{{ $personalIdentity ? __('sso.personal.reconnect') : __('sso.personal.connect') }}</a>
                @endif
                @if ($personalIdentity)
                    <form method="post" action="{{ route('account.sso.unlink', $personalIdentity) }}" class="inline" data-confirm="{{ __('account.identity_unlink_confirm') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn ghost">{{ __('sso.personal.disconnect') }}</button>
                    </form>
                @endif
            </div>
            @if ($personalIdentity)
                <p class="muted">{{ $personalIdentity->label }}
                    @if ($personalIdentity->last_login_at)
                        · {{ __('account.last_login', ['date' => local_time($personalIdentity->last_login_at)]) }}
                    @endif
                </p>
                <p><strong>{{ __('sso.personal.login_link') }}:</strong> <code id="personal-login-url">{{ $personalLoginUrl }}</code>
                    <button type="button" class="btn small" data-copy="#personal-login-url" data-copied="{{ __('account.copied') }}">{{ __('account.copy') }}</button></p>
            @endif
        @endif

        <form method="post" action="{{ route('account.personal-sso.save') }}" class="stack" autocomplete="off">
            @csrf
            @method('PUT')
            <div class="grid-2">
                <div class="field">
                    <label for="ps-name">{{ __('sso.personal.name') }}</label>
                    <input type="text" id="ps-name" name="name" value="{{ old('name', $personal?->name) }}" maxlength="100" required>
                </div>
                <div class="field">
                    <label for="ps-discovery">{{ __('sso.discovery_url') }}</label>
                    <input type="url" id="ps-discovery" name="discovery_url" value="{{ old('discovery_url', $personal?->discovery_url) }}" maxlength="500" placeholder="https://">
                    <div class="hint">{{ __('sso.personal.discovery_hint') }}</div>
                </div>
                <div class="field">
                    <label for="ps-client-id">{{ __('sso.client_id') }}</label>
                    <input type="text" id="ps-client-id" name="client_id" value="{{ old('client_id', $personal?->client_id) }}" maxlength="255" required>
                </div>
                <div class="field">
                    <label for="ps-client-secret">{{ __('sso.client_secret') }}</label>
                    <input type="password" id="ps-client-secret" name="client_secret" autocomplete="new-password" placeholder="{{ $personal?->client_secret ? __('sso.secret_stored') : '' }}" @required(! $personal)>
                    <div class="hint">{{ __('sso.secret_hint') }}</div>
                </div>
            </div>
            @if ($personal?->discovered_at)
                <p class="hint">{{ __('sso.discovered_at', ['date' => local_time($personal->discovered_at)]) }}</p>
            @endif
            <details>
                <summary>{{ __('sso.advanced') }}</summary>
                <p class="hint">{{ __('sso.personal.manual_hint') }}</p>
                <div class="grid-2">
                    <div class="field">
                        <label for="ps-issuer">{{ __('sso.issuer') }}</label>
                        <input type="text" id="ps-issuer" name="issuer" value="{{ old('issuer', $personal?->issuer) }}" maxlength="500">
                    </div>
                    <div class="field">
                        <label for="ps-scopes">{{ __('sso.scopes') }}</label>
                        <input type="text" id="ps-scopes" name="scopes" value="{{ old('scopes', $personal?->scopes ?? 'openid profile email') }}" maxlength="255">
                    </div>
                    <div class="field">
                        <label for="ps-authorize">{{ __('sso.authorize_url') }}</label>
                        <input type="url" id="ps-authorize" name="authorize_url" value="{{ old('authorize_url', $personal?->authorize_url) }}" maxlength="500">
                    </div>
                    <div class="field">
                        <label for="ps-token">{{ __('sso.token_url') }}</label>
                        <input type="url" id="ps-token" name="token_url" value="{{ old('token_url', $personal?->token_url) }}" maxlength="500">
                    </div>
                    <div class="field">
                        <label for="ps-userinfo">{{ __('sso.userinfo_url') }}</label>
                        <input type="url" id="ps-userinfo" name="userinfo_url" value="{{ old('userinfo_url', $personal?->userinfo_url) }}" maxlength="500">
                    </div>
                    <div class="field">
                        <label for="ps-token-auth">{{ __('sso.token_auth') }}</label>
                        <select id="ps-token-auth" name="token_auth">
                            <option value="post" @selected(old('token_auth', $personal?->token_auth ?? 'post') === 'post')>client_secret_post</option>
                            <option value="basic" @selected(old('token_auth', $personal?->token_auth) === 'basic')>client_secret_basic</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="ps-subject">{{ __('sso.claim_subject') }}</label>
                        <input type="text" id="ps-subject" name="subject_claim" value="{{ old('subject_claim', $personal?->subject_claim ?? 'sub') }}" maxlength="64">
                    </div>
                    <div class="field">
                        <label for="ps-label">{{ __('sso.claim_label') }}</label>
                        <input type="text" id="ps-label" name="label_claim" value="{{ old('label_claim', $personal?->label_claim ?? 'preferred_username') }}" maxlength="64">
                    </div>
                </div>
                <input type="hidden" name="use_pkce" value="0">
                <label class="check"><input type="checkbox" name="use_pkce" value="1" @checked(old('use_pkce', $personal?->use_pkce ?? true))> {{ __('sso.use_pkce') }}</label>
            </details>
            <div class="row-buttons">
                <button type="submit" class="btn primary">{{ __('sso.personal.save') }}</button>
                @if ($personal?->discovery_url)
                    <button type="submit" name="rediscover" value="1" class="btn">{{ __('sso.save_rediscover') }}</button>
                @endif
            </div>
        </form>
    @endif
    @if ($personal)
        <form method="post" action="{{ route('account.personal-sso.destroy') }}" data-confirm="{{ __('sso.personal.delete_confirm') }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn danger small">{{ __('sso.personal.delete') }}</button>
        </form>
    @endif
</section>

<section class="card">
    <h2>{{ __('feedback.title') }}</h2>
    <p class="muted">{{ __('feedback.settings_hint') }}</p>
    <p><a class="btn" href="{{ route('account.feedback') }}">{{ __('feedback.link') }} …</a></p>
</section>

@if ($user->hasBeerAccount())
    <section class="card">
        <h2>{{ __('account.leave') }}</h2>
        <p class="muted">{{ __('account.leave_settings_hint') }}</p>
        <p><a class="btn danger" href="{{ route('account.leave') }}">{{ __('account.leave') }} …</a></p>
    </section>
@endif
@endsection
