@extends('layouts.app')

@section('title', __('guest.qr_title'))
@section('body_class', 'printable')

@section('content')
<div class="no-print">
    @include('partials.backend-nav')
    <div class="row-buttons">
        <a href="{{ route('backend.guests.qr') }}" @class(['btn', 'primary' => $withCode])>{{ __('settings.qr_with_code') }}</a>
        <a href="{{ route('backend.guests.qr', ['code' => 0]) }}" @class(['btn', 'primary' => ! $withCode])>{{ __('settings.qr_without_code') }}</a>
        <button type="button" class="btn" data-print>{{ __('settings.qr_print') }}</button>
    </div>
    @if ($withCode)
        <div class="alert warn">{{ __('guest.qr_warning') }}</div>
    @endif
    @unless ($open)
        <div class="alert bad">{{ __('guest.qr_closed') }}</div>
    @endunless
</div>

<section class="print-sheet">
    <img src="{{ asset('logo.svg') }}" alt="" width="96" height="96">
    <h1>{{ __('guest.qr_title') }}</h1>
    <p class="lead">{{ $appName }} · {{ __('guest.qr_intro') }}</p>
    <img src="{{ $qr }}" alt="QR" width="320" height="320" class="qr big">
    <p class="url">{{ $url }}</p>
</section>
@endsection
