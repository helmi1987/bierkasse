@extends('layouts.app')

@section('title', __('account.title'))

@section('content')
<section @class(['balance-card', 'negative' => $balance < 0])>
    <div class="balance-label">{{ $balance < 0 ? __('account.balance_negative') : __('account.balance') }}</div>
    <div class="balance-amount" data-testid="balance">{{ chf($balance) }}</div>
    @if ($balance < 0)
        <div class="open-box">
            <strong>{{ __('account.open', ['amount' => chf($open['total'])]) }}</strong>
            @if ($open['beers'])
                <ul class="plain">
                    @foreach ($open['beers'] as $item)
                        <li>{{ __('account.open_beers', ['count' => $item['quantity'], 'price' => chf($item['unit_price'])]) }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
    <div class="balance-sub">{{ $limit > 0 ? __('account.limit_info', ['limit' => chf($limit)]) : __('account.limit_none') }}</div>
</section>

@if ($pending->where('type', 'payout')->isNotEmpty())
    <div class="alert warn">{{ __('account.payout_pending') }}</div>
@else
    <section class="card beer-card">
        <h2>{{ __('account.beer') }}</h2>
        <form method="post" action="{{ route('account.consume') }}" class="beer-form">
            @csrf
            <div class="stepper" data-stepper>
                <button type="button" class="btn round" data-step="-1" aria-label="{{ __('account.less') }}">−</button>
                <label class="sr-only" for="quantity">{{ __('account.quantity') }}</label>
                <input type="number" id="quantity" name="quantity" value="1" min="1" max="10" inputmode="numeric" data-price="{{ $price }}">
                <button type="button" class="btn round" data-step="1" aria-label="{{ __('account.more') }}">+</button>
            </div>
            <button type="submit" class="btn primary huge">
                <img src="{{ asset('logo.svg') }}" alt="" width="36" height="36">
                <span>{{ __('account.beer') }}</span>
            </button>
            <p class="muted center">{{ __('account.beer_price', ['price' => chf($price)]) }} · <span data-total>{{ chf($price) }}</span></p>
        </form>

        @if ($undoable->isNotEmpty())
            <ul class="undo-list">
                @foreach ($undoable as $tx)
                    <li>
                        <span>{{ local_time($tx->created_at, 'H:i') }} · {{ trans_choice('ledger.beers', $tx->quantity, ['count' => $tx->quantity, 'price' => chf($tx->unit_price)]) }}</span>
                        <form method="post" action="{{ route('account.undo', $tx) }}" class="inline">
                            @csrf
                            <button type="submit" class="btn small ghost">{{ __('account.undo') }}</button>
                        </form>
                    </li>
                @endforeach
            </ul>
            <p class="hint">{{ __('account.undo_hint', ['minutes' => \App\Models\Transaction::UNDO_MINUTES]) }}</p>
        @endif
    </section>
@endif

<div class="grid-2">
    @if ($twintPhone !== '')
        <section class="card twint">
            <h2>{{ __('account.twint_heading') }}</h2>
            <p class="muted">{{ __('account.twint_intro') }}</p>
            <div class="twint-number">
                <span id="twint-phone">{{ $twintPhone }}</span>
                <button type="button" class="btn small" data-copy="#twint-phone" data-copied="{{ __('account.copied') }}">{{ __('account.copy') }}</button>
            </div>
            @if ($twintName !== '')
                <p>{{ $twintName }}</p>
            @endif
            <p class="muted">{{ __('account.twint_suggested', ['amount' => chf($suggested)]) }}</p>
        </section>
    @endif

    <section class="card">
        <h2>{{ __('account.deposit_heading') }}</h2>
        <p class="muted">{{ __('account.deposit_intro') }}</p>
        <form method="post" action="{{ route('account.deposit') }}" class="stack">
            @csrf
            <div class="field">
                <label for="amount">{{ __('account.amount') }}</label>
                <input type="text" id="amount" name="amount" inputmode="decimal" value="{{ old('amount', \App\Support\Money::plain($suggested)) }}" required>
            </div>
            <fieldset class="segmented">
                <legend>{{ __('account.method') }}</legend>
                @foreach (\App\Models\Transaction::METHODS as $method)
                    <label><input type="radio" name="method" value="{{ $method }}" @checked(old('method', $twintPhone !== '' ? 'twint' : 'cash') === $method)> <span>{{ __('ledger.method.'.$method) }}</span></label>
                @endforeach
            </fieldset>
            <div class="field">
                <label for="note">{{ __('account.note') }}</label>
                <input type="text" id="note" name="note" maxlength="200" value="{{ old('note') }}">
            </div>
            <button type="submit" class="btn">{{ __('account.deposit_submit') }}</button>
        </form>
        @if ($pending->isNotEmpty())
            <h3>{{ __('account.pending_heading') }}</h3>
            <ul class="list">
                @foreach ($pending as $tx)
                    <li>
                        <span>{{ local_time($tx->created_at) }} · {{ __('ledger.type.'.$tx->type) }}@if ($tx->method) · {{ __('ledger.method.'.$tx->method) }}@endif</span>
                        <strong>{{ chf(abs($tx->amount)) }}</strong>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>

<section class="card">
    <h2>{{ __('account.my_stats') }}</h2>
    <div class="kpis">
        <div class="kpi"><span>{{ __('account.total_beers') }}</span><strong>{{ $stats['total_beers'] }}</strong></div>
        <div class="kpi"><span>{{ __('account.total_amount') }}</span><strong>{{ chf($stats['total_amount']) }}</strong></div>
        <div class="kpi"><span>{{ __('account.per_week') }}</span><strong>{{ number_format($stats['per_week'], 1, '.', "'") }}</strong></div>
    </div>
    {!! $chart !!}
</section>

<section class="card">
    <h2>{{ __('account.recent') }}</h2>
    @include('account.tx-list', ['transactions' => $recent])
    <p><a href="{{ route('account.history') }}">{{ __('account.history') }} →</a></p>
</section>

<p class="center"><a class="btn ghost" href="{{ route('account.leave') }}">{{ __('account.leave') }}</a></p>
@endsection
