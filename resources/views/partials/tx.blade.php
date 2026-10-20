{{-- One ledger row: description of a transaction. --}}
<span class="tx-what">
    @if ($tx->type === 'consumption')
        {{ trans_choice('ledger.beers', $tx->quantity, ['count' => $tx->quantity, 'price' => chf((int) $tx->unit_price)]) }}
    @else
        {{ __('ledger.type.'.$tx->type) }}
        @if ($tx->method)
            · {{ __('ledger.method.'.$tx->method) }}
        @endif
    @endif
    @if ($tx->status !== 'confirmed')
        <span @class(['pill', 'warn' => $tx->status === 'pending', 'bad' => $tx->status === 'rejected'])>{{ __('ledger.status.'.$tx->status) }}</span>
    @endif
</span>
@if ($tx->note)
    <span class="tx-note">{{ $tx->note }}</span>
@endif
