@extends('layouts.app')

@section('title', __('auth.totp.title'))
@section('main_class', 'narrow')

@section('content')
<h1>{{ __('auth.totp.title') }}</h1>
<form method="post" action="{{ route('login.totp.verify') }}" class="card stack">
    @csrf
    <div class="field">
        <label for="code">{{ __('auth.totp.code') }}</label>
        <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus class="code-input">
        <div class="hint">{{ __('auth.totp.hint') }}</div>
    </div>
    <button class="btn primary big" type="submit">{{ __('auth.totp.submit') }}</button>
</form>
<form method="post" action="{{ route('login.totp.cancel') }}" class="center">
    @csrf
    <button class="linkish" type="submit">{{ __('auth.totp.cancel') }}</button>
</form>
@endsection
