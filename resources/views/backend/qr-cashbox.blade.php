@extends('layouts.app')

@section('title', __('guest.cashbox_heading'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('guest.cashbox_heading') }}</h1>
<p><a href="{{ route('backend.qr-codes.index') }}">← {{ __('qr.title') }}</a></p>

<section class="card stack" id="kassen-qr">
    <p class="muted">{{ __('guest.cashbox_intro_backend') }}</p>
    @unless ($cashboxEnabled)
        <p class="hint">{{ __('guest.cashbox_off') }}</p>
    @endunless
    @unless ($twintEnabled)
        <p class="hint">{{ __('guest.cashbox_twint_off') }}</p>
    @endunless
    @if ($admin)
        <p><a class="btn small" href="{{ route('backend.qr-codes.index').'#verwaltung' }}">{{ __('qr.manage_link') }} →</a></p>
    @endif
    @if ($cashboxActive->isEmpty())
        <p class="muted">{{ $admin ? __('qr.none_active') : __('qr.none_kassier') }}</p>
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
</section>
@endsection
