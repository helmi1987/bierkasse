@extends('layouts.app')

@section('title', __('maintenance.title'))
@section('main_class', 'narrow')

@section('content')
<div class="auth-hero">
    <img src="{{ asset('logo.svg') }}" alt="" width="96" height="96" class="hero-logo">
    <h1>{{ __('maintenance.page_title') }}</h1>
    @if ($message !== '')
        <p class="lead">{{ $message }}</p>
    @endif
    <p class="muted">{{ __('maintenance.page_text') }}</p>
</div>
<p class="center"><a href="{{ route('login') }}">{{ __('auth.login.title') }}</a></p>
@endsection
