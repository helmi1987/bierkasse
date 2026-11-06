{{-- Kassier overview (Livewire since 1.36.0; see App\Livewire\Backend\Dashboard). Polls every 10 s while visible;
     only the islands are re-rendered. Rows carry wire:key, the per-row forms are wire:ignore (a chosen
     payout method or an edited carry-over amount survives a poll). --}}
<div wire:poll.10s.visible="tick" data-testid="backend-dashboard">
@island('kpis', with: $__livewire->data())
@if ($stockLow)
    <div class="alert warn" role="alert">{{ __('stock.low_alert', ['stock' => $stock, 'min' => $stockMin]) }} <a href="{{ route('backend.stock') }}">{{ __('stock.title') }} →</a></div>
@endif

<div class="kpis">
    <div class="kpi"><span>{{ __('stock.stock') }}</span><strong @class(['neg' => $stockLow || $stock < 0])>{{ trans_choice('stock.beers', $stock, ['count' => $stock]) }}</strong>
        <small>{{ $stockMin > 0 ? __('stock.minimum_short', ['min' => $stockMin]) : __('stock.minimum_off') }}</small></div>
    <div class="kpi"><span>{{ __('backend.cash_balance') }}</span><strong>{{ chf($overview['cash']['total']) }}</strong>
        <small>{{ __('backend.cash_split', ['cash' => chf($overview['cash']['cash']), 'twint' => chf($overview['cash']['twint'])]) }}</small></div>
    <div class="kpi"><span>{{ __('backend.credit_total') }}</span><strong>{{ chf($overview['credit']) }}</strong></div>
    <div class="kpi"><span>{{ __('backend.surplus') }}</span><strong @class(['neg' => $overview['surplus'] < 0])>{{ chf($overview['surplus']) }}</strong>
        <small>{{ __('backend.surplus_hint') }}</small></div>
</div>
@endisland

@island('deposits', with: $__livewire->data())
<section class="card">
    <h2>{{ __('backend.deposits') }} <span class="pill">{{ $deposits->count() }}</span></h2>
    @if ($deposits->isEmpty())
        <p class="muted">{{ __('backend.deposits_none') }}</p>
    @else
        <ul class="list">
            @foreach ($deposits as $tx)
                <li class="wrap-row" wire:key="deposit-{{ $tx->id }}">
                    <span>
                        <a href="{{ route('backend.users.show', $tx->user) }}"><strong>{{ $tx->user->displayName() }}</strong></a>
                        <span class="muted">· {{ local_time($tx->created_at) }} · {{ __('ledger.method.'.$tx->method) }}</span>
                        {{-- Internal staff note helps to match the TWINT payer to a meaningless username. --}}
                        @if ($tx->method === 'twint' && $tx->user->staffNoteForList())<br><small class="muted" data-testid="staff-note">{{ $tx->user->staffNoteForList() }}</small>@endif
                        @if ($tx->edited_at)<span class="pill warn">{{ __('backend.deposit_edited', ['time' => local_time($tx->edited_at, 'd.m. H:i')]) }}</span>@endif
                        @if ($tx->note)<br><span class="tx-note">{{ $tx->note }}</span>@endif
                    </span>
                    <strong>{{ chf($tx->amount) }}</strong>
                    <span class="row-buttons" wire:ignore>
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
@endisland

@island('payouts', with: $__livewire->data())
<section class="card">
    <h2>{{ __('backend.payouts') }} <span class="pill">{{ $payouts->count() }}</span></h2>
    @if ($payouts->isEmpty())
        <p class="muted">{{ __('backend.payouts_none') }}</p>
    @else
        <ul class="list">
            @foreach ($payouts as $tx)
                <li class="wrap-row" wire:key="payout-{{ $tx->id }}">
                    <span>
                        <a href="{{ route('backend.users.show', $tx->user) }}"><strong>{{ $tx->user->displayName() }}</strong></a>
                        <span class="muted">· {{ local_time($tx->created_at) }}</span>
                    </span>
                    <strong>{{ __('backend.payout_amount', ['amount' => chf(max(0, $tx->user->balance()))]) }}</strong>
                    <span class="row-buttons" wire:ignore>
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
@endisland

@island('carryovers', with: $__livewire->data())
@if ($carryovers->isNotEmpty())
    <section class="card">
        <h2>{{ __('backend.carryovers') }} <span class="pill">{{ $carryovers->count() }}</span></h2>
        <p class="hint">{{ __('backend.carryover_hint') }}</p>
        <ul class="list">
            @foreach ($carryovers as $tx)
                <li class="wrap-row" data-testid="carryover" wire:key="carryover-{{ $tx->id }}">
                    <span>
                        <a href="{{ route('backend.users.show', $tx->user) }}"><strong>{{ $tx->user->displayName() }}</strong></a>
                        <span class="muted">· {{ local_time($tx->created_at) }}</span><br>
                        <span>{{ $tx->carryoverText() }}</span>
                    </span>
                    <strong @class(['amount', 'neg' => $tx->amount < 0])>{{ chf($tx->amount) }}</strong>
                    <span class="row-buttons" wire:ignore>
                        <form method="post" action="{{ route('backend.carryovers.confirm', $tx) }}" class="inline">
                            @csrf
                            <input type="text" name="amount" value="{{ \App\Support\Money::plain($tx->amount) }}" inputmode="decimal" maxlength="20" size="8" aria-label="{{ __('backend.carryover_amount') }}" title="{{ __('backend.carryover_amount') }}">
                            <button type="submit" class="btn small primary">{{ __('backend.confirm') }}</button>
                        </form>
                        <form method="post" action="{{ route('backend.carryovers.reject', $tx) }}" class="inline" data-confirm="{{ __('backend.reject_confirm') }}">
                            @csrf
                            <button type="submit" class="btn small ghost">{{ __('backend.reject') }}</button>
                        </form>
                    </span>
                </li>
            @endforeach
        </ul>
    </section>
@endif
@endisland

@island('minus', with: $__livewire->data())
<section class="card">
    <h2>{{ __('backend.minus') }}</h2>
    @if ($minus->isEmpty())
        <p class="muted">{{ __('backend.minus_none') }}</p>
    @else
        <ul class="list">
            @foreach ($minus as $row)
                <li wire:key="minus-{{ $row['user']->id }}">
                    <a href="{{ route('backend.users.show', $row['user']) }}">{{ $row['user']->displayName() }}</a>@if ($row['user']->isLocked()) <span class="pill bad">{{ __('users.locked_badge') }}</span>@endif
                    <strong class="amount neg">{{ __('account.open', ['amount' => chf(-$row['balance'])]) }}</strong>
                </li>
            @endforeach
        </ul>
    @endif
</section>
@endisland
</div>
