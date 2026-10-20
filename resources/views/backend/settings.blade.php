@extends('layouts.app')

@section('title', __('settings.title'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('settings.title') }}</h1>

<form method="post" action="{{ route('backend.settings.update') }}" class="stack" autocomplete="off">
    @csrf
    @method('PUT')

    <section class="card stack">
        <h2>{{ __('settings.general') }}</h2>
        <div class="grid-2">
            <div class="field">
                <label for="app_name">{{ __('settings.app_name') }}</label>
                <input type="text" id="app_name" name="app_name" value="{{ old('app_name', $appName) }}" maxlength="60">
                <div class="hint">{{ __('settings.app_name_hint', ['default' => config('app.name')]) }}</div>
            </div>
            <div class="field">
                <label for="beer_price">{{ __('settings.beer_price') }}</label>
                <input type="text" id="beer_price" name="beer_price" inputmode="decimal" value="{{ old('beer_price', $beerPrice) }}" required>
                <div class="hint">{{ __('settings.beer_price_hint') }}</div>
            </div>
            <div class="field">
                <label for="default_limit">{{ __('settings.default_limit') }}</label>
                <input type="text" id="default_limit" name="default_limit" inputmode="decimal" value="{{ old('default_limit', $defaultLimit) }}" required>
                <div class="hint">{{ __('settings.default_limit_hint') }}</div>
            </div>
        </div>
    </section>

    <section class="card stack">
        <h2>{{ __('settings.twint') }}</h2>
        <div class="grid-2">
            <div class="field">
                <label for="twint_phone">{{ __('settings.twint_phone') }}</label>
                <input type="tel" id="twint_phone" name="twint_phone" value="{{ old('twint_phone', $twintPhone) }}">
            </div>
            <div class="field">
                <label for="twint_name">{{ __('settings.twint_name') }}</label>
                <input type="text" id="twint_name" name="twint_name" value="{{ old('twint_name', $twintName) }}">
            </div>
        </div>
    </section>

    <section class="card stack">
        <h2>{{ __('settings.registration') }}</h2>
        <label class="switch"><input type="checkbox" name="registration_enabled" value="1" @checked(old('registration_enabled', $registrationEnabled))> <span>{{ __('settings.registration_enabled') }}</span></label>
        <div class="field">
            <label for="registration_password">{{ __('settings.registration_password') }}</label>
            <input type="text" id="registration_password" name="registration_password" value="{{ old('registration_password', $registrationPassword) }}" maxlength="100">
            <div class="hint">{{ __('settings.registration_password_hint') }}</div>
        </div>
        <p><a href="{{ route('backend.registration.qr') }}">{{ __('settings.registration_qr') }} →</a></p>
    </section>

    <section class="card stack">
        <h2>{{ __('settings.notify') }}</h2>
        <label class="switch"><input type="checkbox" name="notify_kassier" value="1" @checked(old('notify_kassier', $notifyKassier))> <span>{{ __('settings.notify_kassier') }}</span></label>
        <p class="hint">{{ __('settings.notify_hint') }}</p>
    </section>

    <section class="card stack">
        <h2>{{ __('settings.mail') }}</h2>
        <div class="grid-2">
            <div class="field">
                <label for="mail_transport">{{ __('settings.mail_transport') }}</label>
                <select id="mail_transport" name="mail_transport">
                    @foreach (\App\Support\AppSettings::MAIL_TRANSPORTS as $t)
                        <option value="{{ $t }}" @selected(old('mail_transport', $mail['transport']) === $t)>{{ __('settings.mail_transports.'.$t) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="mail_from_address">{{ __('settings.mail_from_address') }}</label>
                <input type="email" id="mail_from_address" name="mail_from_address" value="{{ old('mail_from_address', $mail['from_address']) }}">
                <div class="hint">{{ __('settings.mail_from_address_hint') }}</div>
            </div>
            <div class="field">
                <label for="mail_from_name">{{ __('settings.mail_from_name') }}</label>
                <input type="text" id="mail_from_name" name="mail_from_name" value="{{ old('mail_from_name', $mail['from_name']) }}">
            </div>
            <div class="field">
                <label for="smtp_host">{{ __('settings.smtp_host') }}</label>
                <input type="text" id="smtp_host" name="smtp_host" value="{{ old('smtp_host', $mail['smtp_host']) }}">
            </div>
            <div class="field">
                <label for="smtp_port">{{ __('settings.smtp_port') }}</label>
                <input type="number" id="smtp_port" name="smtp_port" value="{{ old('smtp_port', $mail['smtp_port']) }}" min="1" max="65535">
            </div>
            <div class="field">
                <label for="smtp_encryption">{{ __('settings.smtp_encryption') }}</label>
                <select id="smtp_encryption" name="smtp_encryption">
                    @foreach (\App\Support\AppSettings::MAIL_ENCRYPTIONS as $enc)
                        <option value="{{ $enc }}" @selected(old('smtp_encryption', $mail['smtp_encryption']) === $enc)>{{ __('settings.smtp_encryptions.'.($enc === '' ? 'none' : $enc)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="smtp_user">{{ __('settings.smtp_user') }}</label>
                <input type="text" id="smtp_user" name="smtp_user" value="{{ old('smtp_user', $mail['smtp_user']) }}" autocomplete="off">
            </div>
            <div class="field">
                <label for="smtp_password">{{ __('settings.smtp_password') }}</label>
                <input type="password" id="smtp_password" name="smtp_password" autocomplete="new-password" placeholder="{{ $mail['smtp_password'] !== '' ? __('settings.smtp_password_stored') : '' }}">
                @if ($mail['smtp_password'] !== '')
                    <label class="check"><input type="checkbox" name="smtp_password_clear" value="1"> {{ __('settings.smtp_password_clear') }}</label>
                @endif
            </div>
        </div>
    </section>

    <button type="submit" class="btn primary big">{{ __('settings.save') }}</button>
</form>
@endsection
