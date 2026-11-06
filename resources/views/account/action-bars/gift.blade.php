{{-- Total and button of the gift form; position set by AppSettings::giftsBarTop(). --}}
<div @class(['action-bar', 'is-sticky' => $giftBarTop])>
    <p class="action-total center" aria-live="polite">{{ __('gift.total') }}: <strong data-gift-count>{{ trans_choice('round.beers', $giftCount, ['count' => $giftCount]) }}</strong> · <strong data-gift-amount>{{ chf($giftCount * $price) }}</strong></p>
    <button type="submit" class="btn primary big" data-gift-submit @disabled($giftOld === [])>{{ __('gift.submit') }}</button>
</div>
