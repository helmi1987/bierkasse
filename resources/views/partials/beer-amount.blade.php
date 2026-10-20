{{-- Deposit proposal: beer count prominent, amount (and covered minus) small below. --}}
<span class="beer-amount">
    <strong>{{ trans_choice('account.suggestion_beers', $s['beers'], ['count' => $s['beers']]) }}</strong>
    <small>{{ chf($s['amount']) }}@if ($s['debt'] > 0) · {{ __('account.suggestion_debt') }}@endif</small>
</span>
