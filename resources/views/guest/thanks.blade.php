@extends('layouts.app')

@section('title', __('guest.thanks_title'))
@section('main_class', 'narrow')

@section('content')
<section class="card stack center">
    <img src="{{ asset('logo.svg') }}" alt="" width="72" height="72" class="hero-logo">
    <h1>{{ __('guest.thanks_title') }}</h1>
    <p>{{ trans_choice('guest.thanks_beers', $sale['beers'], ['count' => $sale['beers']]) }}</p>
    @if ($sale['method'] === 'twint')
        @if ($twintEnabled)
            <p class="lead">{!! __('guest.thanks_twint', ['amount' => e(chf($sale['amount'])), 'phone' => $twintHtml]) !!}</p>
            @include('partials.twint-number', ['id' => 'twint-phone'])
        @else
            <p class="lead">{{ __('guest.thanks_twint_noname', ['amount' => chf($sale['amount'])]) }}</p>
        @endif
    @else
        <p class="lead">{{ __('guest.thanks_cash', ['amount' => chf($sale['amount'])]) }}</p>
    @endif
    <p><a href="{{ route('guest') }}">{{ __('guest.again') }}</a></p>
</section>
@endsection
