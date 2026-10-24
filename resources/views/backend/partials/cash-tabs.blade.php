{{-- Tabs of "Kasse": cash book and deposit proposals ($cashTab = 'book'|'vorschlaege'). --}}
<nav class="tabs" aria-label="{{ __('cash.title') }}">
    <a href="{{ route('backend.cash') }}" @class(['active' => $cashTab === 'book'])>{{ __('backend.tabs.cash_book') }}</a>
    <a href="{{ route('backend.cash', ['tab' => 'vorschlaege']) }}" @class(['active' => $cashTab === 'vorschlaege'])>{{ __('backend.tabs.deposit_suggestions') }}</a>
</nav>
