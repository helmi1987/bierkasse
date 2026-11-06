@extends('layouts.app')

@section('title', __('passwords.forgot_title'))
{{-- No live refresh (1.36.0: no page uses live.js any more). --}}
@section('live_off', '1')
@section('main_class', 'narrow')

@section('content')
<h1>{{ __('passwords.forgot_title') }}</h1>
<p class="muted">{{ __('passwords.forgot_intro') }}</p>
<form method="post" action="{{ route('password.email') }}" class="card stack">
    @csrf
    <div class="field">
        <label for="login">{{ __('passwords.login') }}</label>
        <input type="text" id="login" name="login" value="{{ old('login') }}" autocapitalize="none" required autofocus>
    </div>
    <button class="btn primary big" type="submit">{{ __('passwords.send') }}</button>
</form>
<p class="center"><a href="{{ route('login') }}">{{ __('passwords.back') }}</a></p>
@endsection
