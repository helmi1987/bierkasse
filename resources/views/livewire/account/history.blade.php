{{-- Own booking history (Livewire since 1.35.0; see App\Livewire\Account\History). Polls every 10 s while visible;
     re-rendered only when something changed. Page buttons without reload, page in the URL. --}}
<div wire:poll.10s.visible="tick" data-testid="history">
<h1>{{ __('account.history_title') }}</h1>
<p class="lead">{{ __('account.balance') }}: <strong data-testid="history-balance">{{ chf($balance) }}</strong></p>
<section class="card">
    @include('account.tx-list', ['transactions' => $transactions])
    {{ $transactions->links('livewire.pagination') }}
</section>
</div>
