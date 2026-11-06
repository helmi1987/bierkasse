@extends('layouts.app')

@section('title', __('sso.personal.confirm_title', ['host' => $host]))
@section('main_class', 'narrow')

@section('content')
<h1>{{ __('sso.personal.confirm_title', ['host' => $host]) }}</h1>

<form method="post" action="{{ route('login.personal.start', $username) }}" class="card stack">
    @csrf
    <p>{{ __('sso.personal.confirm_intro', ['name' => $name, 'host' => $host]) }}</p>
    <p><code>{{ $host }}</code></p>
    <button class="btn primary big" type="submit">{{ __('sso.personal.confirm_submit', ['host' => $host]) }}</button>
    <div class="row-links">
        <a href="{{ route('login') }}">{{ __('sso.personal.login_back') }}</a>
    </div>
</form>
@endsection
