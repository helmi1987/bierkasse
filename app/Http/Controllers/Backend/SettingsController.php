<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\AppSettings;
use App\Support\Money;
use App\Support\Qr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin settings: price, default limit, TWINT, app name, registration, mail, notifications.
 * All values are stored encrypted (Setting), so the admin can view the registration password.
 */
class SettingsController extends Controller
{
    public function edit(): View
    {
        return view('backend.settings', [
            'beerPrice' => Money::plain(AppSettings::beerPrice()),
            'defaultLimit' => Money::plain(AppSettings::defaultLimit()),
            'appName' => (string) AppSettings::get(AppSettings::APP_NAME, ''),
            'twintPhone' => AppSettings::twintPhone(),
            'twintName' => AppSettings::twintName(),
            'registrationEnabled' => (bool) AppSettings::get(AppSettings::REGISTRATION_ENABLED, true),
            'registrationPassword' => AppSettings::registrationPassword(),
            'notifyKassier' => AppSettings::notifyKassier(),
            'personalSso' => AppSettings::personalSsoAllowed(),
            'mail' => AppSettings::mail(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'beer_price' => ['required', 'string', 'max:10'],
            'default_limit' => ['required', 'string', 'max:10'],
            'app_name' => ['nullable', 'string', 'max:60'],
            'twint_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+ ()\/-]*$/'],
            'twint_name' => ['nullable', 'string', 'max:100'],
            'registration_password' => ['nullable', 'string', 'min:4', 'max:100'],
            'mail_transport' => ['required', Rule::in(AppSettings::MAIL_TRANSPORTS)],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:100'],
            'smtp_host' => ['nullable', 'required_if:mail_transport,smtp', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'integer', 'between:1,65535'],
            'smtp_user' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:255'],
            'smtp_encryption' => ['nullable', Rule::in(AppSettings::MAIL_ENCRYPTIONS)],
        ]);

        $price = Money::parse($data['beer_price']);
        if ($price === null || $price <= 0) {
            throw ValidationException::withMessages(['beer_price' => __('ledger.amount_invalid')]);
        }
        $limit = Money::parse($data['default_limit']);
        if ($limit === null || $limit < 0) {
            throw ValidationException::withMessages(['default_limit' => __('ledger.amount_invalid')]);
        }

        $old = AppSettings::mail();
        $smtpPassword = (string) ($data['smtp_password'] ?? '');
        if ($smtpPassword === '' && ! $request->boolean('smtp_password_clear')) {
            $smtpPassword = $old['smtp_password'];
        }

        Setting::putMany([
            AppSettings::BEER_PRICE => $price,
            AppSettings::DEFAULT_LIMIT => $limit,
            AppSettings::APP_NAME => trim((string) ($data['app_name'] ?? '')),
            AppSettings::TWINT_PHONE => trim((string) ($data['twint_phone'] ?? '')),
            AppSettings::TWINT_NAME => trim((string) ($data['twint_name'] ?? '')),
            AppSettings::REGISTRATION_ENABLED => $request->boolean('registration_enabled'),
            AppSettings::REGISTRATION_PASSWORD => (string) ($data['registration_password'] ?? ''),
            AppSettings::NOTIFY_KASSIER => $request->boolean('notify_kassier'),
            AppSettings::PERSONAL_SSO => $request->boolean('personal_sso'),
            AppSettings::MAIL => [
                'transport' => $data['mail_transport'],
                'from_address' => (string) ($data['mail_from_address'] ?? ''),
                'from_name' => (string) ($data['mail_from_name'] ?? ''),
                'smtp_host' => (string) ($data['smtp_host'] ?? ''),
                'smtp_port' => $data['smtp_port'] ?? null,
                'smtp_user' => (string) ($data['smtp_user'] ?? ''),
                'smtp_password' => $smtpPassword,
                'smtp_encryption' => (string) ($data['smtp_encryption'] ?? ''),
            ],
        ]);

        return back()->with('status', __('settings.saved'));
    }

    /**
     * Printable page with the QR code of the registration URL; includes the password unless ?code=0.
     */
    public function registrationQr(Request $request): View
    {
        $withCode = $request->boolean('code', true) && AppSettings::registrationPassword() !== '';
        $url = route('register');
        if ($withCode) {
            $url .= '?'.http_build_query(['code' => AppSettings::registrationPassword()]);
        }

        return view('backend.registration-qr', [
            'view' => $request->query('view') === 'flyer' ? 'flyer' : 'qr',
            'url' => $url,
            'qr' => Qr::dataUri($url, 480),
            'withCode' => $withCode,
            'open' => AppSettings::registrationOpen(),
            'appName' => AppSettings::appName(),
        ]);
    }
}
