@extends('layouts.app')

@section('title', __('auth.login.title'))
@section('main_class', 'narrow')

@section('content')
<div class="auth-hero">
    <img src="{{ asset('logo.svg') }}" alt="" width="96" height="96" class="hero-logo">
    <h1>{{ \App\Support\AppSettings::appName() }}</h1>
    <p class="muted">{{ __('auth.login.tagline') }}</p>
</div>

<form method="post" action="{{ route('login.post') }}" class="card stack">
    @csrf
    <div class="field">
        <label for="username">{{ __('auth.login.username') }}</label>
        <input type="text" id="username" name="username" value="{{ old('username') }}" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
    </div>
    <div class="field">
        <label for="password">{{ __('auth.login.password') }}</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
    </div>
    <label class="check"><input type="checkbox" name="remember" value="1"> {{ __('auth.login.remember') }}</label>
    <button class="btn primary big" type="submit">{{ __('auth.login.submit') }}</button>
    <div class="row-links">
        @if ($registration)
            <a href="{{ route('register') }}">{{ __('auth.login.register') }}</a>
        @endif
        <a href="{{ route('password.request') }}">{{ __('auth.login.forgot') }}</a>
    </div>
</form>

@if ($providers->isNotEmpty())
    <div class="divider"><span>{{ __('auth.login.or') }}</span></div>
    <div class="stack">
        @foreach ($providers as $provider)
            <a class="btn sso big" href="{{ route('login.sso', $provider->slug) }}">{{ __('auth.login.with_provider', ['provider' => $provider->name]) }}</a>
        @endforeach
        <p class="hint center">{{ __('auth.login.sso_hint') }}</p>
    </div>
@endif
@endsection
