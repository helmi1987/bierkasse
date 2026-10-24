@extends('layouts.app')

@section('title', __('account.title'))

@section('content')
<section @class(['balance-card', 'negative' => $balance < 0])>
    <div class="balance-label">{{ $balance < 0 ? __('account.balance_negative') : __('account.balance') }}</div>
    @php
        $openBeers = array_sum(array_column($open['beers'], 'quantity')) + count($open['partial']);
        $beerCount = $balance < 0 ? $openBeers : intdiv($balance, max(1, $price));
    @endphp
    @if ($balance >= 0 || $openBeers > 0)
        <div class="balance-amount" data-testid="balance-beers">{{ trans_choice($balance < 0 ? 'account.balance_beers_open' : 'account.balance_beers', $beerCount, ['count' => $beerCount]) }}</div>
        <div class="balance-chf" data-testid="balance">{{ chf($balance) }}</div>
    @else
        <div class="balance-amount" data-testid="balance">{{ chf($balance) }}</div>
    @endif
    {{-- Pending deposits/carry-overs are part of $balance (decision 2026-10-23): one number, no "ausstehend" note. --}}
    @if ($balance < 0)
        @include('partials.open-items', ['open' => $open])
    @endif
</section>

@foreach ($giftsReceived as $gift)
    <div class="alert ok round-notice" data-testid="gift-notice">
        <span>🎁 {{ trans_choice('gift.notice', $gift->quantity, ['count' => $gift->quantity, 'giver' => $gift->giftParent?->user?->displayName() ?? '?']) }}@if ($gift->note) – {{ $gift->note }}@endif</span>
        @include('account.notice-dismiss', ['transaction' => $gift])
    </div>
@endforeach
@foreach ($roundsReceived as $round)
    {{-- A colleague paid the user a beer in the last days ("Runde spendieren"). --}}
    <div class="alert ok round-notice" data-testid="round-notice">
        <span>🍺 {{ __('round.notice', ['giver' => $round->user?->displayName() ?? '?']) }}@if ($round->note) – {{ $round->note }}@endif</span>
        @include('account.notice-dismiss', ['transaction' => $round])
    </div>
@endforeach

@if ($pending->where('type', 'payout')->isNotEmpty())
    <div class="alert warn">{{ __('account.payout_pending') }}</div>
@else
    <section class="card beer-card">
        <h2>{{ __('account.beer') }}</h2>
        <form method="post" action="{{ route('account.consume') }}" class="beer-form">
            @csrf
            {!! \App\Support\FormToken::field('consume') !!}
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
        @if ($stock !== null)
            <p @class(['stock-line', 'low' => $stockLow || $stock <= 0])>
                @if ($stock > 0)
                    {{ trans_choice('account.stock_left', $stock, ['count' => $stock]) }}
                @else
                    {{ __('account.stock_empty') }}
                @endif
            </p>
        @endif

        @if ($undoable->isNotEmpty())
            <div class="undo-group" data-undo-group>
                <ul class="undo-list">
                    @foreach ($undoable as $tx)
                        <li data-expires="{{ $tx->undoExpiresAt()?->getTimestampMs() }}">
                            <span>{{ local_time($tx->created_at, 'H:i') }} · {{ trans_choice('ledger.beers', $tx->quantity, ['count' => $tx->quantity, 'price' => chf($tx->unit_price)]) }}</span>
                            <form method="post" action="{{ route('account.undo', $tx) }}" class="inline">
                                @csrf
                                <button type="submit" class="btn small ghost">{{ __('account.undo') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
                <p class="hint">{{ __('account.undo_hint', ['minutes' => \App\Support\AppSettings::undoMinutes('consume')]) }}</p>
            </div>
        @endif
    </section>
@endif

@if (($colleagues->isNotEmpty() || $giftColleagues->isNotEmpty()) && ($rounds || $gifts) && $pending->where('type', 'payout')->isEmpty())
    {{-- Shared by the pin buttons of both colleague lists (each list has its own favourites) (form="favorites-form", formaction per colleague). --}}
    <form method="post" action="{{ route('account.dashboard') }}" id="favorites-form" data-favorites>@csrf</form>
@endif

@if ($rounds && $pending->where('type', 'payout')->isEmpty())
    {{-- Round for colleagues (one beer each) and beers without a name, paid by the user. --}}
    {{-- Inline @php only: a @php block here would swallow the inline @php() above it. --}}
    @php($roundOld = array_map('intval', (array) old('recipients', [])))
    @php($roundExtra = max(0, (int) old('extra', 0)))
    @php($roundCount = count($roundOld) + $roundExtra)
    <section class="card round-card" data-testid="round">
        <details @if ($roundUndoable->isNotEmpty() || $errors->hasAny(['recipients', 'recipients.*', 'extra', 'message']) || old('round_form')) open @endif>
            <summary><h2>{{ __('round.heading') }}</h2></summary>
            <p class="muted">{{ __('round.intro') }}</p>
            <form method="post" action="{{ route('account.round') }}" class="stack" data-round data-price="{{ $price }}" data-max="{{ \App\Services\Rounds::MAX_BEERS }}"
                data-one="{{ trans_choice('round.beers', 1, ['count' => 1]) }}" data-many="{{ trans_choice('round.beers', 2, ['count' => ':count']) }}">
                @csrf
                {!! \App\Support\FormToken::field('round') !!}
                <input type="hidden" name="round_form" value="1">
                <fieldset class="field">
                    <legend>{{ __('round.colleagues') }}</legend>
                    @if ($colleagues->isEmpty())
                        <p class="hint">{{ __('round.no_colleagues') }}</p>
                    @else
                        <label class="sr-only" for="round-filter">{{ __('round.filter') }}</label>
                        <input type="search" id="round-filter" placeholder="{{ __('round.filter') }}" autocomplete="off" data-round-filter hidden>
                        <ul class="round-list" data-list="round">
                            @foreach ($colleagues as $colleague)
                                <li data-name="{{ mb_strtolower($colleague->displayName().' '.$colleague->username) }}" data-pos="{{ $colleague->sort_pos }}" data-user="{{ $colleague->id }}" @class(['is-fav' => $colleague->is_favorite])>
                                    <label class="check"><input type="checkbox" name="recipients[]" value="{{ $colleague->id }}" @checked(in_array($colleague->id, $roundOld, true))> <span>{{ $colleague->displayName() }}</span></label>
                                    @include('account.pin-button', ['colleague' => $colleague, 'list' => 'round'])
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </fieldset>
                <div class="field">
                    <span class="label">{{ __('round.extra') }}</span>
                    <div class="stepper" data-stepper>
                        <button type="button" class="btn round" data-step="-1" aria-label="{{ __('account.less') }}">−</button>
                        <label class="sr-only" for="round-extra">{{ __('round.extra') }}</label>
                        <input type="number" id="round-extra" name="extra" value="{{ $roundExtra }}" min="0" max="{{ \App\Services\Rounds::MAX_EXTRA }}" inputmode="numeric">
                        <button type="button" class="btn round" data-step="1" aria-label="{{ __('account.more') }}">+</button>
                    </div>
                    <p class="hint center">{{ __('round.extra_hint', ['max' => \App\Services\Rounds::MAX_EXTRA]) }}</p>
                </div>
                <div class="field">
                    <label for="round-message">{{ __('round.message') }}</label>
                    <input type="text" id="round-message" name="message" value="{{ old('message') }}" maxlength="{{ \App\Services\Rounds::MESSAGE_MAX }}" placeholder="{{ __('round.message_placeholder') }}" autocomplete="off">
                </div>
                <p class="round-total center" aria-live="polite">{{ __('round.total') }}: <strong data-round-count>{{ trans_choice('round.beers', $roundCount, ['count' => $roundCount]) }}</strong> · <strong data-round-amount>{{ chf($roundCount * $price) }}</strong></p>
                <p class="hint center">{{ __('round.total_hint', ['max' => \App\Services\Rounds::MAX_BEERS, 'price' => chf($price)]) }}</p>
                <div class="row-buttons">
                    <button type="submit" class="btn primary big" data-round-submit data-label="{{ __('round.submit') }}" @disabled($roundCount < 1)>{{ __('round.submit', ['count' => $roundCount]) }}</button>
                    <button type="submit" name="include_self" value="1" class="btn big" data-round-submit-self data-label="{{ __('round.submit_self') }}" @disabled($roundCount < 1)>{{ __('round.submit_self', ['count' => $roundCount + 1]) }}</button>
                </div>
            </form>

            @if ($roundUndoable->isNotEmpty())
                <div class="undo-group" data-undo-group>
                    <ul class="undo-list">
                        @foreach ($roundUndoable as $tx)
                            <li data-expires="{{ $tx->undoExpiresAt()?->getTimestampMs() }}">
                                <span>{{ local_time($tx->created_at, 'H:i') }} · {{ trans_choice('round.beers', $tx->quantity, ['count' => $tx->quantity]) }} · {{ chf(-$tx->amount) }}<br><small class="muted">{{ __('round.tx_for', ['names' => \App\Services\Rounds::recipientsText($tx)]) }}</small></span>
                                <form method="post" action="{{ route('account.undo', $tx) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="btn small ghost">{{ __('account.undo') }}</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                    <p class="hint">{{ __('round.undo_hint', ['minutes' => \App\Support\AppSettings::undoMinutes('round')]) }}</p>
                </div>
            @endif
        </details>
    </section>
@endif

@if ($gifts && $pending->where('type', 'payout')->isEmpty())
    {{-- "Bier verschenken": the same number of beers as credit for each chosen colleague, paid by the user. --}}
    @php($giftOld = array_map('intval', (array) old('gift_recipients', [])))
    @php($giftQty = min(\App\Services\Gifts::MAX_PER_RECIPIENT, max(1, (int) old('gift_quantity', 1))))
    <section class="card round-card" data-testid="gift">
        <details @if ($giftUndoable->isNotEmpty() || $errors->hasAny(['gift_recipients', 'gift_recipients.*', 'gift_quantity', 'gift_message']) || old('gift_quantity')) open @endif>
            <summary><h2>{{ __('gift.heading') }}</h2></summary>
            <p class="muted">{{ __('gift.intro') }}</p>
            <form method="post" action="{{ route('account.gift') }}" class="stack" data-gift data-price="{{ $price }}"
                data-one="{{ trans_choice('round.beers', 1, ['count' => 1]) }}" data-many="{{ trans_choice('round.beers', 2, ['count' => ':count']) }}">
                @csrf
                {!! \App\Support\FormToken::field('gift') !!}
                <fieldset class="field">
                    <legend>{{ __('gift.colleagues') }}</legend>
                    @if ($giftColleagues->isEmpty())
                        <p class="hint">{{ __('round.no_colleagues') }}</p>
                    @else
                        <label class="sr-only" for="gift-filter">{{ __('gift.filter') }}</label>
                        <input type="search" id="gift-filter" placeholder="{{ __('gift.filter') }}" autocomplete="off" data-round-filter hidden>
                        <ul class="round-list" data-list="gift">
                            @foreach ($giftColleagues as $colleague)
                                <li data-name="{{ mb_strtolower($colleague->displayName().' '.$colleague->username) }}" data-pos="{{ $colleague->sort_pos }}" data-user="{{ $colleague->id }}" @class(['is-fav' => $colleague->is_favorite])>
                                    <label class="check"><input type="checkbox" name="gift_recipients[]" value="{{ $colleague->id }}" @checked(in_array($colleague->id, $giftOld, true))> <span>{{ $colleague->displayName() }}</span></label>
                                    @include('account.pin-button', ['colleague' => $colleague, 'list' => 'gift'])
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </fieldset>
                <div class="field">
                    <span class="label">{{ __('gift.quantity') }}</span>
                    <div class="stepper" data-stepper>
                        <button type="button" class="btn round" data-step="-1" aria-label="{{ __('account.less') }}">−</button>
                        <label class="sr-only" for="gift-quantity">{{ __('gift.quantity') }}</label>
                        <input type="number" id="gift-quantity" name="gift_quantity" value="{{ $giftQty }}" min="1" max="{{ \App\Services\Gifts::MAX_PER_RECIPIENT }}" inputmode="numeric">
                        <button type="button" class="btn round" data-step="1" aria-label="{{ __('account.more') }}">+</button>
                    </div>
                </div>
                <div class="field">
                    <label for="gift-message">{{ __('gift.message') }}</label>
                    <input type="text" id="gift-message" name="gift_message" value="{{ old('gift_message') }}" maxlength="{{ \App\Services\Gifts::MESSAGE_MAX }}" placeholder="{{ __('gift.message_placeholder') }}" autocomplete="off">
                </div>
                @php($giftCount = count($giftOld) * $giftQty)
                <p class="round-total center" aria-live="polite">{{ __('gift.total') }}: <strong data-gift-count>{{ trans_choice('round.beers', $giftCount, ['count' => $giftCount]) }}</strong> · <strong data-gift-amount>{{ chf($giftCount * $price) }}</strong></p>
                <button type="submit" class="btn primary big" data-gift-submit @disabled($giftOld === [])>{{ __('gift.submit') }}</button>
            </form>

            @if ($giftUndoable->isNotEmpty())
                <div class="undo-group" data-undo-group>
                    <ul class="undo-list">
                        @foreach ($giftUndoable as $tx)
                            <li data-expires="{{ $tx->undoExpiresAt()?->getTimestampMs() }}">
                                <span>{{ local_time($tx->created_at, 'H:i') }} · {{ trans_choice('round.beers', $tx->quantity, ['count' => $tx->quantity]) }} · {{ chf(-$tx->amount) }}<br><small class="muted">{{ __('gift.tx_to', ['names' => \App\Services\Gifts::recipientsText($tx)]) }}</small></span>
                                <form method="post" action="{{ route('account.undo', $tx) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="btn small ghost">{{ __('account.undo') }}</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                    <p class="hint">{{ __('gift.undo_hint', ['minutes' => \App\Support\AppSettings::undoMinutes('gift')]) }}</p>
                </div>
            @endif
        </details>
    </section>
@endif

@if ($guestBooking)
    {{-- Beers for a guest: paid by the guest right away, the own balance stays unchanged. --}}
    <section class="card guest-card" data-testid="guest-booking">
        <details @if ($guestUndoable->isNotEmpty() || $errors->hasAny(['guest_quantity', 'guest_method', 'guest_name']) || old('guest_quantity')) open @endif>
            <summary><h2>{{ __('guest.book_heading') }}</h2></summary>
            <p class="muted">{{ __('guest.book_intro') }}</p>
            <form method="post" action="{{ route('account.guest') }}" class="stack">
                @csrf
                {!! \App\Support\FormToken::field('guest-booking') !!}
                <div class="field">
                    <span class="label">{{ __('guest.quantity') }}</span>
                    <div class="stepper" data-stepper>
                        <button type="button" class="btn round" data-step="-1" aria-label="{{ __('account.less') }}">−</button>
                        <label class="sr-only" for="guest_quantity">{{ __('guest.quantity') }}</label>
                        <input type="number" id="guest_quantity" name="guest_quantity" value="{{ old('guest_quantity', 1) }}" min="1" max="{{ \App\Services\GuestSales::MAX_BEERS }}" inputmode="numeric" data-price="{{ $price }}">
                        <button type="button" class="btn round" data-step="1" aria-label="{{ __('account.more') }}">+</button>
                    </div>
                    <p class="muted center">{{ __('guest.price', ['price' => chf($price)]) }} · <strong data-total>{{ chf($price) }}</strong></p>
                </div>
                <fieldset class="segmented">
                    <legend>{{ __('guest.method') }}</legend>
                    @foreach (\App\Models\Transaction::METHODS as $method)
                        @continue($method === 'twint' && ! $twintEnabled)
                        <label><input type="radio" name="guest_method" value="{{ $method }}" @checked(old('guest_method', 'cash') === $method)> <span>{{ __('ledger.method.'.$method) }}</span></label>
                    @endforeach
                </fieldset>
                @if ($twintEnabled)
                    <p>{!! __('guest.twint_to', ['name' => $twintHtml]) !!}</p>
                    @include('partials.twint-number')
                    <p class="hint">{{ __('guest.twint_hint') }}</p>
                @endif
                <div class="field">
                    <label for="guest_name">{{ __('guest.name') }}</label>
                    <input type="text" id="guest_name" name="guest_name" value="{{ old('guest_name') }}" maxlength="100" autocomplete="off">
                </div>
                <button type="submit" class="btn primary big">{{ __('guest.book_submit') }}</button>
            </form>

            @if ($guestUndoable->isNotEmpty())
                <div class="undo-group" data-undo-group>
                    <ul class="undo-list">
                        @foreach ($guestUndoable as $sale)
                            <li data-expires="{{ $sale->undoExpiresAt()?->getTimestampMs() }}">
                                <span>{{ local_time($sale->created_at, 'H:i') }} · {{ $sale->name ?? __('guest.anonymous') }} · {{ trans_choice('stock.beers', $sale->beers, ['count' => $sale->beers]) }} · {{ __('ledger.method.'.$sale->method) }} · {{ chf($sale->amount) }}</span>
                                <form method="post" action="{{ route('account.guest.undo', $sale) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="btn small ghost">{{ __('account.undo') }}</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                    <p class="hint">{{ __('guest.undo_hint', ['minutes' => \App\Support\AppSettings::undoMinutes('guest')]) }}</p>
                </div>
            @endif
        </details>
    </section>
@endif

<section class="card" data-testid="deposit">
    <details @if ($errors->hasAny(['amount', 'method', 'note']) || old('amount')) open @endif>
    <summary><h2>{{ __('account.deposit_heading') }}</h2></summary>
    @if ($twintEnabled)
        {{-- TWINT recipients and number: pay first, then report below. --}}
        <div class="twint">
            @if ($suggested)
                <p class="lead">{!! __('account.twint_send_amount', ['amount' => e(chf($suggested['amount'])), 'name' => $twintHtml]) !!}</p>
                <p class="muted">{{ trans_choice('account.suggestion_beers', $suggested['beers'], ['count' => $suggested['beers']]) }}@if ($suggested['debt'] > 0) · {{ __('account.suggestion_debt') }}@endif · {{ __('account.twint_then_report') }}</p>
            @else
                <p class="lead">{!! __('account.twint_send', ['name' => $twintHtml]) !!}</p>
                <p class="muted">{{ __('account.twint_then_report') }}</p>
            @endif
            @include('partials.twint-number')
        </div>
    @endif
    <p class="muted">{{ __('account.deposit_intro') }}</p>
    <form method="post" action="{{ route('account.deposit') }}" class="stack">
        @csrf
        {!! \App\Support\FormToken::field('deposit') !!}
        @if ($suggestions)
            <div class="field">
                <span class="label">{{ __('account.suggestions') }}</span>
                <div class="suggestions">
                    @foreach ($suggestions as $s)
                        <button type="button" class="suggestion" data-fill="#amount" data-value="{{ \App\Support\Money::plain($s['amount']) }}">
                            @include('partials.beer-amount', ['s' => $s])
                        </button>
                    @endforeach
                </div>
            </div>
        @endif
        <div class="field">
            <label for="amount">{{ __('account.amount') }}</label>
            <input type="text" id="amount" name="amount" inputmode="decimal" value="{{ old('amount', $suggested ? \App\Support\Money::plain($suggested['amount']) : '') }}" required
                data-beers="#amount-beers" data-price="{{ $price }}" data-debt="{{ max(0, -$balance) }}"
                data-text="{{ __('account.amount_beers') }}" data-text-debt="{{ __('account.amount_beers_debt') }}">
            <small class="muted" id="amount-beers" aria-live="polite"></small>
        </div>
        <fieldset class="segmented">
            <legend>{{ __('account.method') }}</legend>
            @foreach (\App\Models\Transaction::METHODS as $method)
                <label><input type="radio" name="method" value="{{ $method }}" @checked(old('method', $twintEnabled ? 'twint' : 'cash') === $method)> <span>{{ __('ledger.method.'.$method) }}</span></label>
            @endforeach
        </fieldset>
        <div class="field">
            <label for="note">{{ __('account.note') }}</label>
            <input type="text" id="note" name="note" maxlength="200" value="{{ old('note') }}">
        </div>
        <button type="submit" class="btn">{{ __('account.deposit_submit') }}</button>
    </form>
    </details>
    @if ($pending->isNotEmpty())
        <h3>{{ __('account.pending_heading') }}</h3>
        @if ($pending->where('type', \App\Models\Transaction::TYPE_DEPOSIT)->isNotEmpty())
            <p class="hint">{{ __('account.pending_tap_hint') }}</p>
        @endif
        <ul class="list">
            @foreach ($pending as $tx)
                @if ($tx->type === \App\Models\Transaction::TYPE_DEPOSIT)
                    {{-- Tap to change or withdraw the own report while it is pending. --}}
                    <li class="pending-edit">
                        <details>
                            <summary>
                                <span>{{ local_time($tx->created_at) }} · {{ __('ledger.type.'.$tx->type) }} · {{ __('ledger.method.'.$tx->method) }}@if ($tx->edited_at) · <span class="muted">{{ __('account.deposit_edited') }}</span>@endif</span>
                                <strong>{{ chf($tx->amount) }}</strong>
                            </summary>
                            <form method="post" action="{{ route('account.deposit.update', $tx) }}" class="stack">
                                @csrf
                                @method('PUT')
                                @if ($suggestions)
                                    <div class="suggestions">
                                        @foreach ($suggestions as $s)
                                            <button type="button" class="suggestion" data-fill="#amount-{{ $tx->id }}" data-value="{{ \App\Support\Money::plain($s['amount']) }}">
                                                @include('partials.beer-amount', ['s' => $s])
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                                <div class="field">
                                    <label for="amount-{{ $tx->id }}">{{ __('account.amount') }}</label>
                                    <input type="text" id="amount-{{ $tx->id }}" name="amount" inputmode="decimal" value="{{ \App\Support\Money::plain($tx->amount) }}" required
                                        data-beers="#amount-beers-{{ $tx->id }}" data-price="{{ $price }}" data-debt="{{ max(0, -$balance) }}"
                                        data-text="{{ __('account.amount_beers') }}" data-text-debt="{{ __('account.amount_beers_debt') }}">
                                    <small class="muted" id="amount-beers-{{ $tx->id }}" aria-live="polite"></small>
                                </div>
                                <fieldset class="segmented">
                                    <legend>{{ __('account.method') }}</legend>
                                    @foreach (\App\Models\Transaction::METHODS as $method)
                                        <label><input type="radio" name="method" value="{{ $method }}" @checked($tx->method === $method)> <span>{{ __('ledger.method.'.$method) }}</span></label>
                                    @endforeach
                                </fieldset>
                                <button type="submit" class="btn">{{ __('account.deposit_update') }}</button>
                            </form>
                            <form method="post" action="{{ route('account.deposit.cancel', $tx) }}" data-confirm="{{ __('account.deposit_cancel_confirm') }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn ghost danger-text">{{ __('account.deposit_cancel') }}</button>
                            </form>
                        </details>
                    </li>
                @else
                    <li>
                        <span>{{ local_time($tx->created_at) }} · {{ __('ledger.type.'.$tx->type) }}@if ($tx->method) · {{ __('ledger.method.'.$tx->method) }}@endif</span>
                        <strong>{{ $tx->type === \App\Models\Transaction::TYPE_CARRYOVER ? chf($tx->amount) : chf(abs($tx->amount)) }}</strong>
                    </li>
                @endif
            @endforeach
        </ul>
    @endif
</section>

<section class="card" data-testid="bookings">
    <details>
    <summary><h2>{{ __('account.bookings_stats') }}</h2></summary>
    <div class="kpis">
        <div class="kpi"><span>{{ __('account.total_beers') }}</span><strong>{{ $stats['total_beers'] }}</strong></div>
        <div class="kpi"><span>{{ __('account.total_amount') }}</span><strong>{{ chf($stats['total_amount']) }}</strong></div>
        <div class="kpi"><span>{{ __('account.per_week') }}</span><strong>{{ number_format($stats['per_week'], 1, '.', "'") }}</strong></div>
        @if ($stats['given'] > 0)
            <div class="kpi" data-testid="round-given"><span>{{ __('round.given_kpi') }}</span><strong>{{ $stats['given'] }}</strong></div>
        @endif
        @if ($stats['gifted'] > 0)
            <div class="kpi" data-testid="gift-given"><span>{{ __('gift.given_kpi') }}</span><strong>{{ $stats['gifted'] }}</strong></div>
        @endif
        @if ($stats['gift_received'] > 0)
            <div class="kpi" data-testid="gift-received"><span>{{ __('gift.received_kpi') }}</span><strong>{{ $stats['gift_received'] }}</strong></div>
        @endif
    </div>
    {!! $chart !!}
    {{-- Latest bookings below the statistics (no own card since 1.14). --}}
    <div class="card-sep">
        @include('account.tx-list', ['transactions' => $recent])
        <p><a href="{{ route('account.history') }}">{{ __('account.history') }} →</a></p>
    </div>
    </details>
</section>

<p class="center small-link">
    @if ($canInvite)
        <a href="{{ route('account.invite') }}">{{ __('account.invite_link') }}</a> ·
    @endif
    <a href="{{ route('account.feedback') }}">{{ __('feedback.link') }}</a>
</p>
@endsection
