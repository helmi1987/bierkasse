@extends('layouts.app')

@section('title', __('cash.title'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('cash.title') }}</h1>
<p class="muted">{{ __('cash.intro') }}</p>

<div class="kpis">
    <div class="kpi"><span>{{ __('backend.cash_balance') }}</span><strong>{{ chf($overview['cash']['total']) }}</strong>
        <small>{{ __('backend.cash_split', ['cash' => chf($overview['cash']['cash']), 'twint' => chf($overview['cash']['twint'])]) }}</small></div>
    <div class="kpi"><span>{{ __('cash.deposits') }}</span><strong>{{ chf($deposits) }}</strong></div>
    <div class="kpi"><span>{{ __('cash.payouts') }}</span><strong>{{ chf($payouts) }}</strong></div>
    <div class="kpi"><span>{{ __('backend.surplus') }}</span><strong @class(['neg' => $overview['surplus'] < 0])>{{ chf($overview['surplus']) }}</strong></div>
</div>

<section class="card">
    <h2>{{ __('cash.new') }}</h2>
    <form method="post" action="{{ route('backend.cash.store') }}" class="stack">
        @csrf
        <div class="grid-2">
            <div class="field">
                <label for="type">{{ __('cash.type') }}</label>
                <select id="type" name="type">
                    @foreach (\App\Models\CashEntry::TYPES as $type)
                        <option value="{{ $type }}" @selected(old('type') === $type)>{{ __('cash.types.'.$type) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="date">{{ __('cash.date') }}</label>
                <input type="date" id="date" name="date" value="{{ old('date', now()->format('Y-m-d')) }}" required>
            </div>
            <div class="field">
                <label for="amount">{{ __('cash.amount') }}</label>
                <input type="text" id="amount" name="amount" inputmode="decimal" value="{{ old('amount') }}" required>
                <div class="hint">{{ __('cash.amount_hint') }}</div>
            </div>
            <div class="field">
                <label for="method">{{ __('cash.method') }}</label>
                <select id="method" name="method">
                    @foreach (\App\Models\Transaction::METHODS as $method)
                        <option value="{{ $method }}" @selected(old('method') === $method)>{{ __('ledger.method.'.$method) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="field">
            <label for="note">{{ __('cash.note') }}</label>
            <input type="text" id="note" name="note" value="{{ old('note') }}" maxlength="500" required>
        </div>
        <button type="submit" class="btn primary">{{ __('cash.save') }}</button>
    </form>
</section>

<section class="card">
    <h2>{{ __('cash.entries') }}</h2>
    @if ($entries->isEmpty())
        <p class="muted">{{ __('cash.none') }}</p>
    @else
        <ul class="list">
            @foreach ($entries as $entry)
                <li class="wrap-row">
                    <span>
                        <span class="tx-date">{{ local_time($entry->date, 'd.m.Y') }} · {{ __('cash.types.'.$entry->type) }} · {{ __('ledger.method.'.$entry->method) }}</span><br>
                        {{ $entry->note }} <span class="muted">{{ __('cash.by') }} {{ $entry->creator?->username ?? '–' }}</span>
                    </span>
                    <strong @class(['amount', 'neg' => $entry->amount < 0, 'pos' => $entry->amount > 0])>{{ chf($entry->amount) }}</strong>
                    <form method="post" action="{{ route('backend.cash.destroy', $entry) }}" class="inline" data-confirm="{{ __('cash.delete_confirm') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn small ghost">{{ __('cash.delete') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
        {{ $entries->links('partials.pagination') }}
    @endif
</section>
@endsection
