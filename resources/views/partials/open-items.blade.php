{{-- Open (unpaid) items; the lines add up to the total. --}}
<div class="open-box">
    <strong>{{ __('account.open', ['amount' => chf($open['total'])]) }}</strong>
    @if ($open['beers'] || $open['partial'] || $open['other'] > 0)
        <ul class="plain">
            @foreach ($open['beers'] as $item)
                <li>{{ __('account.open_beers', ['count' => $item['quantity'], 'price' => chf($item['unit_price'])]) }} = {{ chf($item['amount']) }}</li>
            @endforeach
            @foreach ($open['partial'] as $item)
                <li>{{ __('account.open_partial', ['price' => chf($item['unit_price']), 'amount' => chf($item['amount'])]) }}</li>
            @endforeach
            @if ($open['other'] > 0)
                <li>{{ __('account.open_other', ['amount' => chf($open['other'])]) }}</li>
            @endif
        </ul>
    @endif
</div>
