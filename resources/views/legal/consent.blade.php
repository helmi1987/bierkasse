@extends('layouts.app')

@section('title', __('legal.consent_title'))
@section('live_off', '1')

@section('content')
<h1>{{ __('legal.consent_title') }}</h1>
<p class="alert warn">{{ $renewal ? __('legal.consent_renewal') : __('legal.consent_intro') }}</p>

@foreach (['terms', 'privacy'] as $kind)
    @php($t = $texts[$kind])
    <section class="card">
        <details>
            <summary><h2>{{ $t['title'] }} <small class="muted">· {{ __('legal.as_of', ['date' => $t['date']]) }}</small></h2></summary>
            <div class="legal-text" data-testid="consent-{{ $kind }}">{{ $t['html'] }}</div>
            <p><a href="{{ $t['url'] }}" target="_blank" rel="noopener">{{ __('legal.consent_open_page') }} →</a></p>
        </details>
    </section>
@endforeach

<section class="card">
    <form method="post" action="{{ route('consent.store') }}" class="stack">
        @csrf
        @include('legal.consent-check')
        <button type="submit" class="btn primary big">{{ __('legal.consent_submit') }}</button>
    </form>
    <form method="post" action="{{ route('logout') }}" class="stack consent-logout">
        @csrf
        <button type="submit" class="btn">{{ __('legal.consent_logout') }}</button>
    </form>
</section>
@endsection
