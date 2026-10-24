{{-- Einzahlungsvorschläge (tab of "Kasse"; Livewire, preview refreshed every 10 s while visible). --}}
<div wire:poll.10s.visible>
    <p class="muted">{{ __('deposit_suggestions.intro') }}</p>
    @include('livewire.partials.notice')
    @if ($errors->any())
        <div class="alert bad" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form wire:submit="save" class="card stack" autocomplete="off" data-testid="deposit-suggestions-form">
        <label class="check"><input type="radio" wire:model="mode" name="mode" value="auto"> {{ __('deposit_suggestions.auto') }}</label>
        <p class="hint">{{ __('deposit_suggestions.auto_hint') }}</p>
        <label class="check"><input type="radio" wire:model="mode" name="mode" value="manual"> {{ __('deposit_suggestions.manual') }}</label>
        <div class="field">
            <label for="amounts">{{ __('deposit_suggestions.amounts') }}</label>
            <input type="text" id="amounts" wire:model="amounts" maxlength="200" inputmode="decimal">
            <div class="hint">{{ __('deposit_suggestions.amounts_hint', ['max' => \App\Support\DepositSuggestions::MAX, 'price' => chf($price)]) }}</div>
        </div>
        <div class="field">
            <label for="default">{{ __('deposit_suggestions.default') }}</label>
            <input type="text" id="default" wire:model="default" maxlength="20" inputmode="decimal">
            <div class="hint">{{ __('deposit_suggestions.default_hint') }}</div>
        </div>
        <button type="submit" class="btn primary" wire:loading.attr="disabled">{{ __('deposit_suggestions.save') }}</button>
    </form>

    <section class="card">
        <h2>{{ __('deposit_suggestions.preview') }} ({{ chf($price) }})</h2>
        <ul class="list">
            @foreach ($preview as $p)
                <li><span>{{ trans_choice('account.suggestion_beers', $p['beers'], ['count' => $p['beers']]) }} · {{ chf($p['amount']) }}</span>
                    @if ($p['beers'] === ($defaultBeers ?? $preview[0]['beers']))
                        <span class="badge">{{ __('deposit_suggestions.preselected') }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
</div>
