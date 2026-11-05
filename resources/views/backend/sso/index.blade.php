@extends('layouts.app')

@section('title', __('sso.title'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('sso.title') }}</h1>
<p class="muted">{{ __('sso.intro') }}</p>

<div class="row-buttons">
    @foreach (\App\Models\SsoProvider::TYPES as $type)
        <a class="btn" href="{{ route('backend.sso.create', ['type' => $type]) }}">{{ __('sso.new_type', ['type' => __('sso.types.'.$type)]) }}</a>
    @endforeach
</div>

<section class="card">
    @if ($providers->isEmpty())
        <p class="muted">{{ __('sso.none') }}</p>
    @else
        <p class="hint">{{ __('sso.order_hint') }}</p>
        <ul class="list">
            @foreach ($providers as $provider)
                <li class="wrap-row">
                    <span><a href="{{ route('backend.sso.edit', $provider) }}"><strong>{{ $provider->name }}</strong></a>
                        <span class="muted">· {{ __('sso.types.'.$provider->type) }} · {{ __('sso.users') }}: {{ $provider->identities_count }}</span>
                        <span class="tx-note">{{ __('sso.redirect_uri_short') }}: <code id="redirect-uri-{{ $provider->id }}">{{ $provider->redirectUri() }}</code>
                            <button type="button" class="btn small" data-copy="#redirect-uri-{{ $provider->id }}" data-copied="{{ __('account.copied') }}">{{ __('account.copy') }}</button></span></span>
                    <span class="row-buttons">
                        @if ($provider->client_secret_expires_at)
                            @php($daysLeft = $provider->secretDaysLeft())
                            <span class="pill {{ $provider->secretUrgency() }}" @if ($daysLeft >= 0) title="{{ __('sso.secret_days_left', ['days' => $daysLeft]) }}" @endif data-testid="secret-expiry-{{ $provider->slug }}">{{ __($daysLeft < 0 ? 'sso.secret_expired' : 'sso.secret_until', ['date' => $provider->client_secret_expires_at->format('d.m.Y')]) }}</span>
                        @endif
                        @if ($provider->isUsable())
                            <span class="pill ok">{{ __('sso.status_active') }}</span>
                        @elseif ($provider->enabled)
                            <span class="pill warn">{{ __('sso.status_incomplete') }}</span>
                        @else
                            <span class="pill">{{ __('sso.status_off') }}</span>
                        @endif
                        @unless ($loop->first)
                            <form method="post" action="{{ route('backend.sso.move', $provider) }}">
                                @csrf
                                <input type="hidden" name="direction" value="up">
                                <button type="submit" class="btn small" aria-label="{{ __('sso.move_up') }}" title="{{ __('sso.move_up') }}">↑</button>
                            </form>
                        @endunless
                        @unless ($loop->last)
                            <form method="post" action="{{ route('backend.sso.move', $provider) }}">
                                @csrf
                                <input type="hidden" name="direction" value="down">
                                <button type="submit" class="btn small" aria-label="{{ __('sso.move_down') }}" title="{{ __('sso.move_down') }}">↓</button>
                            </form>
                        @endunless
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
@endsection
