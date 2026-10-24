@extends('layouts.app')

@section('title', $user->displayName())

@section('content')
@include('partials.backend-nav')
@php($me = auth()->user())
@php($protected = $user->isAdmin() && ! $me->isAdmin())
<h1>{{ $user->displayName() }} <span class="pill">{{ __('users.roles.'.$user->role) }}</span> <span @class(['pill', 'bad' => ! $user->isActive()])>{{ __('users.status.'.$user->status) }}</span>@if ($giftsGiven > 0 || $giftsReceived > 0)
    <p class="muted" data-testid="gift-kpi">{{ __('gift.given_kpi') }}: {{ trans_choice('round.beers', $giftsGiven, ['count' => $giftsGiven]) }} · {{ __('gift.received_kpi') }}: {{ trans_choice('round.beers', $giftsReceived, ['count' => $giftsReceived]) }}</p>
@endif

@if ($user->isAdminOnly()) <span class="pill">{{ __('account.admin_only') }}</span>@endif</h1>
@if ($user->isLocked())
    <p class="alert bad" data-testid="locked-info">{{ __('users.locked_info', ['date' => local_time($user->locked_at), 'by' => $user->lockedBy?->username ?? '–', 'reason' => $user->locked_reason ?? '–']) }}</p>
@endif
<p class="muted">{{ $user->username }}@if ($user->email) · {{ $user->email }}@endif · {{ __('users.totp') }}: {{ $user->hasTotp() ? __('account.totp_active') : '–' }}@if ($user->stats_opt_out) · {{ __('users.stats_opt_out') }}@endif · {{ __('users.last_login') }}: {{ local_time($user->last_login_at) }}@if ($personalSso) · {{ __('users.personal_sso', ['name' => $personalSso->name, 'host' => parse_url((string) $personalSso->authorize_url, PHP_URL_HOST) ?: '–']) }} ({{ $personalSso->identities_count > 0 ? __('sso.personal.connected') : __('sso.personal.not_connected') }})@endif</p>

<p class="muted" data-testid="consent-info">{{ __('legal.user_consent') }}: @if ($user->consent_accepted_at){{ local_time($user->consent_accepted_at) }}: @include('legal.accepted')@if ($user->needsConsent()) ({{ __('legal.user_consent_open') }})@endif @else{{ __('legal.user_consent_open') }}@endif · <a href="{{ route('backend.legal-revisions') }}">{{ __('legal.all_revisions') }}</a></p>

@if ($roundsGiven > 0)
    <p class="muted" data-testid="round-given">{{ __('round.given_kpi') }}: {{ trans_choice('round.beers', $roundsGiven, ['count' => $roundsGiven]) }}</p>
@endif

@if ($user->isAdminOnly())
    <p class="alert" data-testid="admin-only-note">{{ __('users.admin_only_no_money') }}</p>
@else
<section @class(['balance-card', 'small', 'negative' => $balance < 0])>
    <div class="balance-label">{{ __('users.balance') }}</div>
    <div class="balance-amount">{{ chf($balance) }}</div>
    @if ($balance < 0)
        @include('partials.open-items', ['open' => $open])
    @endif
</section>

@endif

<div class="grid-2">
    @unless ($user->isAdminOnly())
    <section class="card">
        <h2>{{ __('users.deposit_heading') }}</h2>
        <form method="post" action="{{ route('backend.users.deposit', $user) }}" class="stack">
            @csrf
            <div class="field">
                <label for="dep-amount">{{ __('account.amount') }}</label>
                <input type="text" id="dep-amount" name="amount" inputmode="decimal" required>
            </div>
            <fieldset class="segmented">
                <legend>{{ __('account.method') }}</legend>
                @foreach (\App\Models\Transaction::METHODS as $method)
                    <label><input type="radio" name="method" value="{{ $method }}" @checked($loop->first)> <span>{{ __('ledger.method.'.$method) }}</span></label>
                @endforeach
            </fieldset>
            <div class="field">
                <label for="dep-note">{{ __('account.note') }}</label>
                <input type="text" id="dep-note" name="note" maxlength="500">
            </div>
            <button type="submit" class="btn primary">{{ __('users.deposit_submit') }}</button>
        </form>
    </section>

    <section class="card">
        <h2>{{ __('users.correction_heading') }}</h2>
        <form method="post" action="{{ route('backend.users.correction', $user) }}" class="stack">
            @csrf
            <div class="field">
                <label for="cor-amount">{{ __('account.amount') }}</label>
                <input type="text" id="cor-amount" name="amount" inputmode="decimal" required>
                <div class="hint">{{ __('users.correction_hint') }}</div>
            </div>
            <div class="field">
                <label for="cor-note">{{ __('ledger.what') }}</label>
                <input type="text" id="cor-note" name="note" maxlength="500" required>
            </div>
            <button type="submit" class="btn">{{ __('users.correction_submit') }}</button>
        </form>
    </section>

    <section class="card">
        <h2>{{ __('users.limit_heading') }}</h2>
        <form method="post" action="{{ route('backend.users.limit', $user) }}" class="stack">
            @csrf
            @method('PUT')
            <div class="field">
                <label for="overdraft_limit">{{ __('users.limit_label') }}</label>
                <input type="text" id="overdraft_limit" name="overdraft_limit" inputmode="decimal" value="{{ $user->overdraft_limit === null ? '' : \App\Support\Money::plain($user->overdraft_limit) }}" placeholder="{{ \App\Support\Money::plain(\App\Support\AppSettings::defaultLimit()) }}">
                <div class="hint">{{ __('users.limit_hint') }}</div>
            </div>
            <button type="submit" class="btn">{{ __('account.save') }}</button>
        </form>
    </section>

    @endunless

    @unless ($protected)
        <section class="card">
            <h2>{{ __('users.password_heading') }}</h2>
            <form method="post" action="{{ route('backend.users.password', $user) }}" class="stack" autocomplete="off">
                @csrf
                @method('PUT')
                <div class="field">
                    <label for="pw">{{ __('users.password_new') }}</label>
                    <input type="password" id="pw" name="password" autocomplete="new-password" minlength="8" required>
                </div>
                <div class="field">
                    <label for="pw2">{{ __('users.password_repeat') }}</label>
                    <input type="password" id="pw2" name="password_confirmation" autocomplete="new-password" minlength="8" required>
                </div>
                <label class="check"><input type="checkbox" name="must_change" value="1" checked> {{ __('users.must_change') }}</label>
                <button type="submit" class="btn">{{ __('users.password_submit') }}</button>
            </form>
        </section>
    @endunless
</div>

<section class="card">
    <h2>{{ __('users.actions') }}</h2>
    <div class="row-buttons">
        @if (! $protected && $user->hasTotp())
            <form method="post" action="{{ route('backend.users.totp', $user) }}" class="inline" data-confirm="{{ __('users.totp_reset_confirm') }}">
                @csrf
                <button type="submit" class="btn">{{ __('users.totp_reset') }}</button>
            </form>
        @endif
        @if (! $protected && $user->isLocked())
            <form method="post" action="{{ route('backend.users.unlock', $user) }}" class="inline" data-confirm="{{ __('users.unlock_confirm') }}">
                @csrf
                <button type="submit" class="btn">{{ __('users.unlock') }}</button>
            </form>
        @endif
        @if (! $protected && $user->id !== $me->id && ! $user->isLocked())
            <form method="post" action="{{ route('backend.users.status', $user) }}" class="inline" @if ($user->isActive()) data-confirm="{{ __('users.deactivate_confirm') }}" @endif>
                @csrf
                <input type="hidden" name="active" value="{{ $user->isActive() ? 0 : 1 }}">
                <button type="submit" @class(['btn', 'danger' => $user->isActive()])>{{ $user->isActive() ? __('users.deactivate') : __('users.reactivate') }}</button>
            </form>
        @endif
    </div>
    @if (! $protected && $user->id !== $me->id && $user->isActive())
        <form method="post" action="{{ route('backend.users.lock', $user) }}" class="stack" data-confirm="{{ __('users.lock_confirm') }}" id="sperren">
            @csrf
            <h3>{{ __('users.lock_heading') }}</h3>
            <p class="hint">{{ __('users.lock_hint') }}</p>
            <div class="field">
                <label for="lock-reason">{{ __('users.lock_reason') }}</label>
                <input type="text" id="lock-reason" name="reason" maxlength="500" required>
            </div>
            <button type="submit" class="btn danger">{{ __('users.lock_submit') }}</button>
        </form>
    @endif
    @if ($me->isAdmin())
        <form method="post" action="{{ route('backend.users.role', $user) }}" class="inline-form">
            @csrf
            @method('PUT')
            <label for="role">{{ __('users.role_heading') }}</label>
            <select id="role" name="role">
                @foreach (\App\Models\User::ROLES as $role)
                    <option value="{{ $role }}" @selected($user->role === $role)>{{ __('users.roles.'.$role) }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn small">{{ __('users.role_save') }}</button>
        </form>
        @if ($user->isKassier())
            <form method="post" action="{{ route('backend.users.admin-only', $user) }}" class="stack" id="nur-verwaltung">
                @csrf
                @method('PUT')
                <label class="check"><input type="checkbox" name="admin_only" value="1" @checked($user->isAdminOnly()) @disabled(! $adminOnlySettled)> {{ __('users.admin_only_heading') }}</label>
                <p class="hint">{{ __('users.admin_only_hint') }}</p>
                @unless ($adminOnlySettled)
                    <p class="alert warn">{{ __('account.admin_only_locked', ['amount' => chf($balance)]) }}</p>
                @endunless
                <button type="submit" class="btn small" @disabled(! $adminOnlySettled)>{{ __('users.admin_only_save') }}</button>
            </form>
        @endif
    @endif
</section>

<section class="card">
    <h2>{{ __('users.history') }}</h2>
    @include('account.tx-list', ['transactions' => $transactions, 'stornoUser' => ($user->isActive() || $user->isLocked()) && $user->hasBeerAccount() ? $user : null])
    {{ $transactions->links('partials.pagination') }}
</section>
@endsection
