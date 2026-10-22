{{-- One ledger row: description of a transaction. --}}
<span class="tx-what">
    @if ($tx->type === 'consumption')
        {{ trans_choice('ledger.beers', $tx->quantity, ['count' => $tx->quantity, 'price' => chf((int) $tx->unit_price)]) }}
    @elseif ($tx->type === 'round')
        {{ trans_choice('round.tx_label', (int) $tx->quantity, ['count' => $tx->quantity]) }} à {{ chf((int) $tx->unit_price) }}
        <span class="tx-note">{{ __('round.tx_for', ['names' => \App\Services\Rounds::recipientsText($tx)]) }}</span>
    @elseif ($tx->type === 'gift' && $tx->parent_id === null)
        {{ trans_choice('gift.tx_given', (int) $tx->quantity, ['count' => $tx->quantity]) }} à {{ chf((int) $tx->unit_price) }}
        <span class="tx-note">{{ __('gift.tx_to', ['names' => \App\Services\Gifts::recipientsText($tx)]) }}</span>
    @elseif ($tx->type === 'gift')
        {{ trans_choice('gift.tx_received', (int) $tx->quantity, ['count' => $tx->quantity, 'giver' => $tx->giftParent?->user?->displayName() ?? '?']) }}
    @elseif ($tx->type === 'carryover')
        {{ __('ledger.type.carryover') }}@if ($tx->quantity && $tx->unit_price) · {{ trans_choice('ledger.beers', $tx->quantity, ['count' => $tx->quantity, 'price' => chf((int) $tx->unit_price)]) }}@endif
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
