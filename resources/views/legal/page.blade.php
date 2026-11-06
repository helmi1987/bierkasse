@extends('layouts.app')

@section('title', $title)

@section('content')
<article class="card legal-text" data-testid="legal-{{ $kind }}">
    <p class="muted">{{ __('legal.as_of', ['date' => $date]) }}@if ($changed) · {{ __('legal.last_changed', ['date' => $changed]) }}@endif</p>
    <h1>{{ $title }}</h1>
    {{ $html }}
</article>
<p class="center no-print"><a href="{{ auth()->check() ? auth()->user()->homeUrl() : route('login') }}">{{ __('legal.back') }}</a></p>
@endsection
