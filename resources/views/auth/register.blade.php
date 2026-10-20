@extends('layouts.app')

@section('title', __('register.title'))
@section('main_class', 'narrow')

@section('content')
<h1>{{ __('register.title') }}</h1>
@if (! $open)
    <div class="card"><p>{{ __('register.closed') }}</p></div>
@else
    <p class="muted">{{ __('register.intro') }}</p>
    <form method="post" action="{{ route('register.store') }}" class="card stack" autocomplete="off">
        @csrf
        <div class="field">
            <label for="code">{{ __('register.code') }}</label>
            <input type="password" id="code" name="code" value="{{ $code }}" required>
        </div>
        <div class="field">
            <label for="username">{{ __('register.username') }}</label>
            <input type="text" id="username" name="username" value="{{ old('username') }}" autocomplete="username" autocapitalize="none" spellcheck="false" pattern="[a-z0-9._\-]{3,32}" minlength="3" maxlength="32" required>
            <div class="hint">{{ __('register.username_hint') }}</div>
        </div>
        <div class="grid-2">
            <div class="field">
                <label for="first_name">{{ __('register.first_name') }}</label>
                <input type="text" id="first_name" name="first_name" value="{{ old('first_name') }}" autocomplete="given-name" maxlength="100">
            </div>
            <div class="field">
                <label for="last_name">{{ __('register.last_name') }}</label>
                <input type="text" id="last_name" name="last_name" value="{{ old('last_name') }}" autocomplete="family-name" maxlength="100">
            </div>
        </div>
        <div class="field">
            <label for="email">{{ __('register.email') }}</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="email">
            <div class="hint">{{ __('register.email_hint') }}</div>
        </div>
        <div class="field">
            <label for="password">{{ __('register.password') }}</label>
            <input type="password" id="password" name="password" autocomplete="new-password" minlength="8" required>
        </div>
        <div class="field">
            <label for="password_confirmation">{{ __('register.password_confirmation') }}</label>
            <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="8" required>
        </div>
        <button class="btn primary big" type="submit">{{ __('register.submit') }}</button>
    </form>
@endif
<p class="center"><a href="{{ route('login') }}">{{ __('register.back') }}</a></p>
@endsection
