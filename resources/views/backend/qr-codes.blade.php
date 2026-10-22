@extends('layouts.app')

@section('title', __('qr.title'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('qr.title') }}</h1>
<p class="muted">{{ __('qr.intro_kassier') }}</p>
@if ($admin)
    <p><a class="btn small" href="{{ route('backend.qr-admin') }}">{{ __('qr.manage_link') }} →</a></p>
@endif

<section class="card">
    <ul class="list">
        @foreach ($rows as $row)
            @php($off = match ($row['key']) { 'cashbox_cash', 'cashbox_twint' => ! $cashboxEnabled, 'guest' => ! $guestOpen, 'flyer' => ! $registrationOpen, default => false })
            <li class="wrap-row" data-testid="qr-kind-{{ $row['key'] }}">
                <span>
                    <strong>{{ __('qr.kind.'.$row['key']) }}</strong>
                    @if ($off)<span class="pill">{{ __('qr.off') }}</span>@endif
                    <br><small class="muted">{{ __('qr.count_active', ['count' => $row['active']]) }}</small>
                </span>
                <a class="btn small" href="{{ $row['url'] }}">{{ __('qr.open') }}</a>
            </li>
        @endforeach
    </ul>
</section>
@endsection
