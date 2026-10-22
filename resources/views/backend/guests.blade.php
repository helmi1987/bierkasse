@extends('layouts.app')

@section('title', __('guest.backend_title'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('guest.backend_title') }}</h1>
<p class="muted">{{ __('guest.backend_intro') }}</p>

<form method="post" action="{{ route('backend.guests.update') }}" class="card stack" autocomplete="off">
    @csrf
    @method('PUT')
    <label class="switch"><input type="checkbox" name="guest_user_booking" value="1" @checked(old('guest_user_booking', $userBooking))> <span>{{ __('guest.user_booking') }}</span></label>
    <div class="hint">{{ __('guest.user_booking_hint') }}</div>
    {{-- QR related settings: admins only (kassiers see the state). --}}
    <label class="switch"><input type="checkbox" name="cashbox_qr_enabled" value="1" @checked(old('cashbox_qr_enabled', $cashboxEnabled)) @disabled(! $admin)> <span>{{ __('guest.cashbox_enabled') }}</span></label>
    <label class="switch"><input type="checkbox" name="guest_enabled" value="1" @checked(old('guest_enabled', $enabled)) @disabled(! $admin)> <span>{{ __('guest.enabled') }}</span></label>
    @if ($admin)
        <div class="field">
            <label for="guest_password">{{ __('guest.password') }}</label>
            <input type="text" id="guest_password" name="guest_password" value="{{ old('guest_password', $password) }}" maxlength="100" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false">
            <div class="hint">{{ __('guest.password_hint') }}</div>
        </div>
    @else
        <p class="hint">{{ __('qr.admin_only') }}</p>
    @endif
    <div class="row-buttons">
        <button type="submit" class="btn primary">{{ __('guest.save') }}</button>
        <a class="btn" href="{{ route('backend.guests.qr') }}">{{ __('guest.qr') }} →</a>
    </div>
    @unless ($open)
        <p class="hint">{{ __('guest.qr_closed') }}</p>
    @endunless
</form>

<section class="card stack" id="kassen-qr">
    <h2>{{ __('guest.cashbox_heading') }}</h2>
    <p class="muted">{{ __('guest.cashbox_intro_backend') }}</p>
    @unless ($cashboxEnabled)
        <p class="hint">{{ __('guest.cashbox_off') }}</p>
    @endunless
    @unless ($twintEnabled)
        <p class="hint">{{ __('guest.cashbox_twint_off') }}</p>
    @endunless
    @if ($admin)
        <div class="row-buttons">
            @include('partials.qr.new', ['kind' => \App\Models\QrCode::KIND_CASH, 'button' => __('guest.cashbox_new_cash')])
            @include('partials.qr.new', ['kind' => \App\Models\QrCode::KIND_TWINT, 'button' => __('guest.cashbox_new_twint')])
        </div>
    @endif
    @if ($cashboxActive->isEmpty())
        <p class="muted">{{ __('qr.none_active') }}</p>
    @endif
    <div class="row-buttons">
        @foreach ($cashboxActive as $code)
            @php($file = 'kassen-qr-'.($code->method() === 'cash' ? 'bar' : 'twint').'-'.$code->id)
            <div class="stack center" data-testid="cashbox-code">
                <strong>{{ __('guest.cashbox_title_'.$code->method()) }} · {{ $code->displayName() }}</strong>
                <img src="{{ route('backend.guests.cashbox', $code) }}" alt="{{ __('guest.cashbox_title_'.$code->method()) }}" width="200" height="275">
                <div class="row-buttons">
                    {{-- 80 x 110 mm at 300 dpi. --}}
                    <button type="button" class="btn small" data-png-download="{{ route('backend.guests.cashbox', $code) }}" data-png-width="945" data-png-height="1299" data-png-filename="{{ $file }}.png" data-png-error="#cashbox-png-error">{{ __('guest.cashbox_png') }}</button>
                    <a class="btn small" href="{{ route('backend.guests.cashbox', ['qrCode' => $code, 'download' => 1]) }}">{{ __('guest.cashbox_download') }}</a>
                </div>
                <div class="row-buttons">
                    <a class="btn small" href="{{ route('backend.guests.cashbox.sheet', $code) }}" target="_blank" rel="noopener">{{ __('guest.cashbox_sheet_open') }}</a>
                    <button type="button" class="btn small" data-png-download="{{ route('backend.guests.cashbox.sheet', $code) }}" data-png-filename="{{ $file }}-a4.png" data-png-error="#cashbox-png-error">{{ __('guest.cashbox_sheet_png') }}</button>
                    <a class="btn small" href="{{ route('backend.guests.cashbox.sheet', ['qrCode' => $code, 'download' => 1]) }}">{{ __('guest.cashbox_sheet_svg') }}</a>
                </div>
                @if ($admin)
                    @include('partials.qr.manage', ['code' => $code])
                @endif
            </div>
        @endforeach
    </div>
    @if ($cashboxActive->contains(fn ($c) => $c->kind === \App\Models\QrCode::KIND_CASH) && $cashboxActive->contains(fn ($c) => $c->kind === \App\Models\QrCode::KIND_TWINT))
        <p class="hint">{{ __('guest.cashbox_sheets_hint') }}</p>
        <div class="row-buttons">
            <span>{{ __('guest.cashbox_sheet_mixed') }}:</span>
            <a class="btn small" href="{{ route('backend.guests.cashbox.mixed') }}" target="_blank" rel="noopener">{{ __('guest.cashbox_sheet_open') }}</a>
            <button type="button" class="btn small" data-png-download="{{ route('backend.guests.cashbox.mixed') }}" data-png-filename="kassen-qr-a4-bar-twint.png" data-png-error="#cashbox-png-error">{{ __('guest.cashbox_png') }}</button>
            <a class="btn small" href="{{ route('backend.guests.cashbox.mixed', ['download' => 1]) }}">{{ __('guest.cashbox_download') }}</a>
        </div>
    @endif
    <p class="alert bad" id="cashbox-png-error" role="alert" hidden>{{ __('settings.flyer_png_error') }}</p>
    @if ($admin)
        @include('partials.qr.history', ['history' => $cashboxHistory, 'kindLabel' => fn ($c) => __('guest.cashbox_title_'.$c->method())])
    @endif
</section>

<section class="card">
    <h2>{{ __('guest.sales') }}</h2>
    <p class="muted">{{ __('guest.sales_total', ['beers' => $total['beers'], 'amount' => chf($total['amount'])]) }}</p>
    @if ($sales->isEmpty())
        <p class="muted">{{ __('guest.sales_none') }}</p>
    @else
        <ul class="list">
            @foreach ($sales as $sale)
                <li class="wrap-row">
                    <span>
                        <span class="tx-date">{{ local_time($sale->created_at) }} · {{ __('ledger.method.'.$sale->method) }}</span><br>
                        {{ $sale->name ?? __('guest.anonymous') }} · {{ trans_choice('stock.beers', $sale->beers, ['count' => $sale->beers]) }}<br>
                        <small class="muted">{{ $sale->source === \App\Models\GuestSale::SOURCE_CASHBOX ? __('guest.via_cashbox') : ($sale->booked_by === null ? __('guest.public') : __('guest.booked_by', ['user' => $sale->bookedBy?->username ?? '?'])) }}</small>
                    </span>
                    <strong class="amount pos">{{ chf($sale->amount) }}</strong>
                    <form method="post" action="{{ route('backend.guests.destroy', $sale) }}" class="inline" data-confirm="{{ __('guest.cancel_confirm') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn small ghost">{{ __('guest.cancel') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
        {{ $sales->links('partials.pagination') }}
    @endif
</section>
@endsection
