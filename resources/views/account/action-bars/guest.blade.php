{{-- Total and button of the guest booking form; position set by AppSettings::guestBarTop(). --}}
<div @class(['action-bar', 'is-sticky' => $guestBarTop])>
    <p class="action-total center" aria-live="polite">{{ __('guest.total') }}: <strong data-total>{{ chf($guestQty * $price) }}</strong></p>
    <button type="submit" class="btn primary big">{{ __('guest.book_submit') }}</button>
</div>
