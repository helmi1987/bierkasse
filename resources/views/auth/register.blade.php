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
            <input type="text" id="code" name="code" value="{{ $code }}" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" required>
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
        <details class="carryover" @if (old('carryover_value') || $errors->has('carryover_value')) open @endif>
            <summary>{{ __('register.carryover') }}</summary>
            <div class="stack">
                <p class="hint">{{ __('register.carryover_intro') }}</p>
                <fieldset class="segmented">
                    <legend>{{ __('register.carryover_direction') }}</legend>
                    @foreach (['debt', 'credit'] as $dir)
                        <label><input type="radio" name="carryover_direction" value="{{ $dir }}" @checked(old('carryover_direction', 'debt') === $dir)> <span>{{ __('register.carryover_'.$dir) }}</span></label>
                    @endforeach
                </fieldset>
                <fieldset class="segmented">
                    <legend>{{ __('register.carryover_unit') }}</legend>
                    @foreach (['beers', 'chf'] as $unit)
                        <label><input type="radio" name="carryover_unit" value="{{ $unit }}" @checked(old('carryover_unit', 'beers') === $unit)> <span>{{ __('register.carryover_'.$unit) }}</span></label>
                    @endforeach
                </fieldset>
                <div class="field">
                    <label for="carryover_value">{{ __('register.carryover_value') }}</label>
                    <input type="text" id="carryover_value" name="carryover_value" value="{{ old('carryover_value') }}" inputmode="decimal" maxlength="20" autocomplete="off">
                    @if ($price > 0)
                        <div class="hint">{{ __('register.carryover_value_hint', ['price' => chf($price)]) }}</div>
                    @endif
                </div>
            </div>
        </details>
        <button class="btn primary big" type="submit">{{ __('register.submit') }}</button>
    </form>
@endif
<p class="center"><a href="{{ route('login') }}">{{ __('register.back') }}</a></p>
@endsection
