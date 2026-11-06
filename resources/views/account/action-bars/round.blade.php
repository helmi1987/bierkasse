{{-- Total and buttons of the round form; position set by AppSettings::roundsBarTop(). --}}
<div @class(['action-bar', 'is-sticky' => $roundBarTop])>
    <p class="action-total center" aria-live="polite">{{ __('round.total') }}: <strong data-round-count>{{ trans_choice('round.beers', $roundCount, ['count' => $roundCount]) }}</strong> · <strong data-round-amount>{{ chf($roundCount * $price) }}</strong></p>
    <div class="action-buttons">
        <button type="submit" class="btn primary big" data-round-submit data-label="{{ __('round.submit') }}" @disabled($roundCount < 1)>{{ __('round.submit', ['count' => $roundCount]) }}</button>
        <button type="submit" name="include_self" value="1" class="btn big" data-round-submit-self data-label="{{ __('round.submit_self') }}" @disabled($roundCount < 1)>{{ __('round.submit_self', ['count' => $roundCount + 1]) }}</button>
    </div>
</div>
