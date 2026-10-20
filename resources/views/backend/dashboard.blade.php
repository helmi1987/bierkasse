@extends('layouts.app')

@section('title', __('backend.title'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('backend.title') }}</h1>

<div class="kpis">
    <div class="kpi"><span>{{ __('backend.cash_balance') }}</span><strong>{{ chf($overview['cash']['total']) }}</strong>
        <small>{{ __('backend.cash_split', ['cash' => chf($overview['cash']['cash']), 'twint' => chf($overview['cash']['twint'])]) }}</small></div>
    <div class="kpi"><span>{{ __('backend.credit_total') }}</span><strong>{{ chf($overview['credit']) }}</strong></div>
    <div class="kpi"><span>{{ __('backend.surplus') }}</span><strong @class(['neg' => $overview['surplus'] < 0])>{{ chf($overview['surplus']) }}</strong>
        <small>{{ __('backend.surplus_hint') }}</small></div>
</div>

<section class="card">
    <h2>{{ __('backend.deposits') }} <span class="pill">{{ $deposits->count() }}</span></h2>
    @if ($deposits->isEmpty())
        <p class="muted">{{ __('backend.deposits_none') }}</p>
    @else
        <ul class="list">
            @foreach ($deposits as $tx)
                <li class="wrap-row">
                    <span>
                        <a href="{{ route('backend.users.show', $tx->user) }}"><strong>{{ $tx->user->displayName() }}</strong></a>
                        <span class="muted">· {{ local_time($tx->created_at) }} · {{ __('ledger.method.'.$tx->method) }}</span>
                        @if ($tx->note)<br><span class="tx-note">{{ $tx->note }}</span>@endif
                    </span>
                    <strong>{{ chf($tx->amount) }}</strong>
                    <span class="row-buttons">
                        <form method="post" action="{{ route('backend.deposits.confirm', $tx) }}" class="inline">
                            @csrf
                            <button type="submit" class="btn small primary">{{ __('backend.confirm') }}</button>
                        </form>
                        <form method="post" action="{{ route('backend.deposits.reject', $tx) }}" class="inline" data-confirm="{{ __('backend.reject_confirm') }}">
                            @csrf
                            <button type="submit" class="btn small ghost">{{ __('backend.reject') }}</button>
                        </form>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</section>

<section class="card">
    <h2>{{ __('backend.payouts') }} <span class="pill">{{ $payouts->count() }}</span></h2>
    @if ($payouts->isEmpty())
        <p class="muted">{{ __('backend.payouts_none') }}</p>
    @else
        <ul class="list">
            @foreach ($payouts as $tx)
                <li class="wrap-row">
                    <span>
                        <a href="{{ route('backend.users.show', $tx->user) }}"><strong>{{ $tx->user->displayName() }}</strong></a>
                        <span class="muted">· {{ local_time($tx->created_at) }}</span>
                    </span>
                    <strong>{{ __('backend.payout_amount', ['amount' => chf(max(0, $tx->user->balance()))]) }}</strong>
                    <span class="row-buttons">
                        <form method="post" action="{{ route('backend.payouts.confirm', $tx) }}" class="inline">
                            @csrf
                            <select name="method" aria-label="{{ __('cash.method') }}">
                                @foreach (\App\Models\Transaction::METHODS as $method)
                                    <option value="{{ $method }}">{{ __('ledger.method.'.$method) }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn small primary">{{ __('backend.paid_out') }}</button>
                        </form>
                        <form method="post" action="{{ route('backend.payouts.cancel', $tx) }}" class="inline" data-confirm="{{ __('backend.reject_confirm') }}">
                            @csrf
                            <button type="submit" class="btn small ghost">{{ __('backend.reject') }}</button>
                        </form>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</section>

<section class="card">
    <h2>{{ __('backend.minus') }}</h2>
    @if ($minus->isEmpty())
        <p class="muted">{{ __('backend.minus_none') }}</p>
    @else
        <ul class="list">
            @foreach ($minus as $row)
                <li>
                    <a href="{{ route('backend.users.show', $row['user']) }}">{{ $row['user']->displayName() }}</a>
                    <strong class="amount neg">{{ __('account.open', ['amount' => chf(-$row['balance'])]) }}</strong>
                </li>
            @endforeach
        </ul>
    @endif
</section>
@endsection
