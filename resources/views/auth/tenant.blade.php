@extends('layouts.app')

@section('title', __('auth.tenant.title'))
@section('main_class', 'narrow')

@section('content')
<h1>{{ __('auth.tenant.title') }}</h1>
<p class="muted">{{ __('auth.tenant.intro') }}</p>
<form method="post" action="{{ route('login.tenant.choose') }}" class="card stack">
    @csrf
    @error('user')<div class="alert error">{{ $message }}</div>@enderror
    <div class="stack">
        @foreach ($users as $user)
            <button class="btn big" type="submit" name="user" value="{{ $user->id }}">{{ $user->tenant?->name ?? '#'.$user->tenant_id }}</button>
        @endforeach
    </div>
</form>
<form method="get" action="{{ route('login') }}" class="center">
    <button class="linkish" type="submit">{{ __('auth.totp.cancel') }}</button>
</form>
@endsection
