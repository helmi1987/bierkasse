@extends('layouts.app')

@section('title', __('guest.backend_title'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('guest.backend_title') }}</h1>
<p class="muted">{{ __('guest.backend_intro') }}</p>

<form method="post" action="{{ route('backend.guests.update') }}" class="card stack" autocomplete="off">
    @csrf
    @method('PUT')
    <label class="switch"><input type="checkbox" name="guest_enabled" value="1" @checked(old('guest_enabled', $enabled))> <span>{{ __('guest.enabled') }}</span></label>
    <div class="field">
        <label for="guest_password">{{ __('guest.password') }}</label>
        <input type="text" id="guest_password" name="guest_password" value="{{ old('guest_password', $password) }}" maxlength="100">
        <div class="hint">{{ __('guest.password_hint') }}</div>
    </div>
    <div class="row-buttons">
        <button type="submit" class="btn primary">{{ __('guest.save') }}</button>
        <a class="btn" href="{{ route('backend.guests.qr') }}">{{ __('guest.qr') }} →</a>
    </div>
    @unless ($open)
        <p class="hint">{{ __('guest.qr_closed') }}</p>
    @endunless
</form>

<section class="card">
    <h2>{{ __('guest.sales') }}</h2>
    <p class="muted">{{ __('guest.sales_total', ['beers' => $total['beers'], 'amount' => chf($total['amount'])]) }}</p>
    @if ($sales->isEmpty())
        <p class="muted">{{ __('guest.sales_none') }}</p>
    @else
        <ul class="list">
            @foreach ($sales as $sale)
                <li class="wrap-row">
                    <span>
                        <span class="tx-date">{{ local_time($sale->created_at) }} · {{ __('ledger.method.'.$sale->method) }}</span><br>
                        {{ $sale->name ?? __('guest.anonymous') }} · {{ trans_choice('stock.beers', $sale->beers, ['count' => $sale->beers]) }}
                    </span>
                    <strong class="amount pos">{{ chf($sale->amount) }}</strong>
                    <form method="post" action="{{ route('backend.guests.destroy', $sale) }}" class="inline" data-confirm="{{ __('guest.cancel_confirm') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn small ghost">{{ __('guest.cancel') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
        {{ $sales->links('partials.pagination') }}
    @endif
</section>
@endsection
