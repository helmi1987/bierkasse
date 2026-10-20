@if ($transactions->isEmpty())
    <p class="muted">{{ __('ledger.none') }}</p>
@else
    <ul class="list tx-list">
        @foreach ($transactions as $tx)
            <li>
                <div>
                    <div class="tx-date">{{ local_time($tx->created_at) }}</div>
                    @include('partials.tx', ['tx' => $tx])
                </div>
                <strong @class(['amount', 'neg' => $tx->amount < 0, 'pos' => $tx->amount > 0, 'dim' => $tx->status !== 'confirmed'])>{{ chf($tx->amount) }}</strong>
            </li>
        @endforeach
    </ul>
@endif
