@extends('layouts.app')

@section('title', __('guest.cashbox_title_'.$method))
@section('main_class', 'narrow')

@section('content')
<div class="auth-hero">
    <img src="{{ asset('logo.svg') }}" alt="" width="96" height="96" class="hero-logo">
    <h1>{{ __('guest.cashbox_title_'.$method) }}</h1>
    <p class="muted">{{ __('guest.cashbox_intro') }}</p>
</div>

@if (is_array($sale))
    <p class="alert ok" role="status">{{ trans_choice('guest.cashbox_thanks', $sale['beers'], ['count' => $sale['beers'], 'amount' => chf($sale['amount'])]) }}</p>
@endif
@if ($available === 0)
    <p class="alert warn" role="alert">{{ trans_choice('guest.stock_short', 0) }}</p>
@endif

<form method="post" action="{{ route('cashbox.store', $code) }}" class="card stack">
    @csrf
    {!! \App\Support\FormToken::field('cashbox') !!}
    <div class="field">
        <span class="label">{{ __('guest.quantity') }}</span>
        <div class="stepper" data-stepper>
            <button type="button" class="btn round" data-step="-1" aria-label="{{ __('account.less') }}">−</button>
            <label class="sr-only" for="quantity">{{ __('guest.quantity') }}</label>
            <input type="number" id="quantity" name="quantity" value="{{ old('quantity', 1) }}" min="1" max="{{ max(1, $available) }}" inputmode="numeric" data-price="{{ $price }}">
            <button type="button" class="btn round" data-step="1" aria-label="{{ __('account.more') }}">+</button>
        </div>
        <p class="muted center">{{ __('guest.price', ['price' => chf($price)]) }} · <strong data-total>{{ chf($price) }}</strong></p>
    </div>
    @if ($method === 'twint')
        <p>{!! __('guest.twint_to', ['name' => $twintHtml]) !!}</p>
        @include('partials.twint-number')
    @endif
    <button class="btn primary big" type="submit" @disabled($available === 0)>{{ __('guest.cashbox_submit_'.$method) }}</button>
</form>
@endsection
