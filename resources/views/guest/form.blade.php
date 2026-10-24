@extends('layouts.app')

@section('title', __('guest.title'))
@section('main_class', 'narrow')

@section('content')
<div class="auth-hero">
    <img src="{{ asset('logo.svg') }}" alt="" width="96" height="96" class="hero-logo">
    <h1>{{ __('guest.title') }}</h1>
    <p class="muted">{{ __('guest.intro') }}</p>
</div>


<form method="post" action="{{ route('guest.store') }}" class="card stack">
    @csrf
    {!! \App\Support\FormToken::field('guest') !!}
    @if ($access)
        <input type="hidden" name="{{ \App\Models\QrCode::GUEST_QUERY }}" value="{{ $access }}">
    @else
        <div class="field">
            <label for="code">{{ __('guest.code') }}</label>
            <input type="text" id="code" name="code" value="{{ old('code', $code) }}" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" maxlength="100" required>
        </div>
    @endif
    <div class="field">
        <label for="name">{{ __('guest.name') }}</label>
        <input type="text" id="name" name="name" value="{{ old('name') }}" maxlength="100" autocomplete="name">
    </div>
    <div class="field">
        <span class="label">{{ __('guest.quantity') }}</span>
        <div class="stepper" data-stepper>
            <button type="button" class="btn round" data-step="-1" aria-label="{{ __('account.less') }}">−</button>
            <label class="sr-only" for="quantity">{{ __('guest.quantity') }}</label>
            <input type="number" id="quantity" name="quantity" value="{{ old('quantity', 1) }}" min="1" max="{{ \App\Services\GuestSales::MAX_BEERS }}" inputmode="numeric" data-price="{{ $price }}">
            <button type="button" class="btn round" data-step="1" aria-label="{{ __('account.more') }}">+</button>
        </div>
        <p class="muted center">{{ __('guest.price', ['price' => chf($price)]) }} · <strong data-total>{{ chf($price) }}</strong></p>
    </div>
    <fieldset class="segmented">
        <legend>{{ __('guest.method') }}</legend>
        @foreach (\App\Models\Transaction::METHODS as $method)
            @continue($method === 'twint' && ! $twintEnabled)
            <label><input type="radio" name="method" value="{{ $method }}" @checked(old('method', 'cash') === $method)> <span>{{ __('ledger.method.'.$method) }}</span></label>
        @endforeach
    </fieldset>
    @if ($twintEnabled)
        <p>{!! __('guest.twint_to', ['name' => $twintHtml]) !!}</p>
        @include('partials.twint-number')
        @include('partials.twint-message', ['username' => \App\Support\UserRules::GUEST_USERNAME])
        <p class="hint">{{ __('guest.twint_hint') }}</p>
    @endif
    <button class="btn primary big" type="submit">{{ __('guest.submit') }}</button>
</form>
@include('legal.links')
@endsection
