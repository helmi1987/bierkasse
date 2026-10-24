{{-- Buchungen: rounds, gifts and guest sales (Livewire; refreshed every 10 s while visible). --}}
<div wire:poll.10s.visible>
    @include('livewire.partials.tabs', [
        'tabs' => ['runden' => __('backend.tabs.rounds'), 'geschenke' => __('backend.tabs.gifts'), 'gaeste' => __('backend.tabs.guests')],
        'active' => $tab,
        'route' => 'backend.bookings',
        'label' => __('backend.bookings_title'),
    ])
    @include('livewire.partials.notice')

    @php($isAdmin = auth()->user()->isAdmin())
    @php($state = fn (bool $on) => $on ? __('qr.on') : __('qr.off'))

    @if ($tab === 'runden')
        <section class="card" data-testid="bookings-rounds">
            <h2>{{ __('round.list') }}</h2>
            <p class="muted">{{ __('round.backend_intro') }}</p>
            <p class="hint">{{ __('round.allowed') }}: <strong>{{ $state($allowed) }}</strong>@if ($isAdmin) · <a href="{{ route('backend.settings') }}#funktionen">{{ __('backend.features_link') }} →</a>@endif</p>
            <p class="muted">{{ __('round.list_total', ['beers' => $total['beers'], 'amount' => chf($total['amount'])]) }}</p>
            @if ($rows->isEmpty())
                <p class="muted">{{ __('round.list_none') }}</p>
            @else
                <ul class="list">
                    @foreach ($rows as $round)
                        <li class="wrap-row" wire:key="round-{{ $round->id }}">
                            <span>
                                <span class="tx-date">{{ local_time($round->created_at) }}</span><br>
                                {{ $round->user?->displayName() ?? '?' }} · {{ trans_choice('round.beers', $round->quantity, ['count' => $round->quantity]) }}<br>
                                <small class="muted">{{ __('round.tx_for', ['names' => \App\Services\Rounds::recipientsText($round)]) }}@if ($round->note) · «{{ $round->note }}»@endif</small>
                            </span>
                            <strong class="amount neg">{{ chf($round->amount) }}</strong>
                        </li>
                    @endforeach
                </ul>
                {{ $rows->links('livewire.pagination') }}
            @endif
        </section>
    @elseif ($tab === 'geschenke')
        <section class="card" data-testid="bookings-gifts">
            <h2>{{ __('gift.list') }}</h2>
            <p class="muted">{{ __('gift.backend_intro') }}</p>
            <p class="hint">{{ __('gift.allowed') }}: <strong>{{ $state($allowed) }}</strong>@if ($isAdmin) · <a href="{{ route('backend.settings') }}#funktionen">{{ __('backend.features_link') }} →</a>@endif</p>
            <p class="muted">{{ __('gift.list_total', ['beers' => $total['beers'], 'amount' => chf($total['amount'])]) }}</p>
            @if ($rows->isEmpty())
                <p class="muted">{{ __('gift.list_none') }}</p>
            @else
                <ul class="list">
                    @foreach ($rows as $gift)
                        <li class="wrap-row" wire:key="gift-{{ $gift->id }}">
                            <span>
                                <span class="tx-date">{{ local_time($gift->created_at) }}</span><br>
                                {{ $gift->user?->displayName() ?? '?' }} · {{ trans_choice('round.beers', $gift->quantity, ['count' => $gift->quantity]) }}<br>
                                <small class="muted">{{ __('gift.tx_to', ['names' => \App\Services\Gifts::recipientsText($gift)]) }}@if ($gift->note) · «{{ $gift->note }}»@endif</small>
                            </span>
                            <strong class="amount neg">{{ chf($gift->amount) }}</strong>
                        </li>
                    @endforeach
                </ul>
                {{ $rows->links('livewire.pagination') }}
            @endif
        </section>
    @else
        <section class="card" data-testid="bookings-guests">
            <h2>{{ __('guest.sales') }}</h2>
            <p class="muted">{{ __('guest.backend_intro') }}</p>
            <p class="hint">{{ __('guest.user_booking') }}: <strong>{{ $state($userBooking) }}</strong> · {{ __('guest.enabled') }}: <strong>{{ $state($open) }}</strong> · {{ __('guest.cashbox_enabled') }}: <strong>{{ $state($cashboxEnabled) }}</strong>@if ($isAdmin) · <a href="{{ route('backend.settings') }}#funktionen">{{ __('backend.features_link') }} →</a>@endif</p>
            <p class="muted">{{ __('guest.sales_total', ['beers' => $total['beers'], 'amount' => chf($total['amount'])]) }}</p>
            @if ($rows->isEmpty())
                <p class="muted">{{ __('guest.sales_none') }}</p>
            @else
                <ul class="list">
                    @foreach ($rows as $sale)
                        <li class="wrap-row" wire:key="sale-{{ $sale->id }}" data-testid="guest-sale">
                            <span>
                                <span class="tx-date">{{ local_time($sale->created_at) }} · {{ __('ledger.method.'.$sale->method) }}</span><br>
                                {{ $sale->name ?? __('guest.anonymous') }} · {{ trans_choice('stock.beers', $sale->beers, ['count' => $sale->beers]) }}<br>
                                <small class="muted">{{ $sale->source === \App\Models\GuestSale::SOURCE_CASHBOX ? __('guest.via_cashbox') : ($sale->booked_by === null ? __('guest.public') : __('guest.booked_by', ['user' => $sale->bookedBy?->username ?? '?'])) }}</small>
                            </span>
                            <strong class="amount pos">{{ chf($sale->amount) }}</strong>
                            <button type="button" class="btn small ghost" wire:click="cancelGuestSale({{ $sale->id }})" wire:confirm="{{ __('guest.cancel_confirm') }}" wire:loading.attr="disabled">{{ __('guest.cancel') }}</button>
                        </li>
                    @endforeach
                </ul>
                {{ $rows->links('livewire.pagination') }}
            @endif
        </section>
    @endif
</div>
