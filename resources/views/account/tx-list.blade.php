@if ($transactions->isEmpty())
    <p class="muted">{{ __('ledger.none') }}</p>
@else
    <ul class="list tx-list">
        @foreach ($transactions as $tx)
            <li>
                <div>
                    <div class="tx-date">{{ local_time($tx->created_at) }}</div>
                    @include('partials.tx', ['tx' => $tx])
                    @if (($stornoUser ?? null) && \App\Services\Ledger::canStorno($tx))
                        {{-- Backend: storno by kassier/admin (note required). --}}
                        <details class="storno no-print">
                            <summary>{{ __('ledger.storno') }}</summary>
                            <form method="post" action="{{ route('backend.users.storno', [$stornoUser, $tx]) }}" class="inline-form" data-confirm="{{ __('ledger.storno_confirm') }}">
                                @csrf
                                <label for="storno-note-{{ $tx->id }}" class="sr-only">{{ __('ledger.storno_note') }}</label>
                                <input type="text" id="storno-note-{{ $tx->id }}" name="note" maxlength="500" placeholder="{{ __('ledger.storno_note') }}" required>
                                <button type="submit" class="btn small danger">{{ __('ledger.storno_submit') }}</button>
                            </form>
                        </details>
                    @endif
                </div>
                <strong @class(['amount', 'neg' => $tx->amount < 0, 'pos' => $tx->amount > 0, 'dim' => $tx->status !== 'confirmed'])>{{ chf($tx->amount) }}</strong>
            </li>
        @endforeach
    </ul>
@endif
