@extends('layouts.app')

@section('title', __('passwords.reset_title'))
@section('main_class', 'narrow')

@section('content')
<h1>{{ __('passwords.reset_title') }}</h1>
<form method="post" action="{{ route('password.update') }}" class="card stack">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <div class="field">
        <label for="email">{{ __('passwords.email') }}</label>
        <input type="email" id="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" required>
    </div>
    <div class="field">
        <label for="password">{{ __('passwords.password') }}</label>
        <input type="password" id="password" name="password" autocomplete="new-password" minlength="8" required>
    </div>
    <div class="field">
        <label for="password_confirmation">{{ __('passwords.password_confirmation') }}</label>
        <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="8" required>
    </div>
    <button class="btn primary big" type="submit">{{ __('passwords.save') }}</button>
</form>
<p class="center"><a href="{{ route('login') }}">{{ __('passwords.back') }}</a></p>
@endsection
