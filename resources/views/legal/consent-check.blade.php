{{-- Required consent checkbox (registration, consent page); both texts open in a new tab. --}}
<label class="check consent-check">
    <input type="checkbox" name="consent" value="1" required @checked(old('consent'))>
    <span>{!! __('legal.consent_label', [
        'terms' => '<a href="'.e(route('legal.terms')).'" target="_blank" rel="noopener">'.e(__('legal.consent_label_terms')).'</a>',
        'privacy' => '<a href="'.e(route('legal.privacy')).'" target="_blank" rel="noopener">'.e(__('legal.consent_label_privacy')).'</a>',
    ]) !!}</span>
</label>
