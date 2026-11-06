@extends('layouts.app')

@section('title', __('sso.personal.login_title'))
@section('main_class', 'narrow')

@section('content')
<h1>{{ __('sso.personal.login_title') }}</h1>

<form method="post" action="{{ route('login.personal.post') }}" class="card stack">
    @csrf
    <p class="muted">{{ __('sso.personal.login_intro') }}</p>
    <div class="field">
        <label for="username">{{ __('auth.login.username_only') }}</label>
        <input type="text" id="username" name="username" value="{{ old('username') }}" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="255" required autofocus>
    </div>
    <button class="btn primary big" type="submit">{{ __('sso.personal.login_submit') }}</button>
    <div class="row-links">
        <a href="{{ route('login') }}">{{ __('sso.personal.login_back') }}</a>
    </div>
</form>
@endsection
