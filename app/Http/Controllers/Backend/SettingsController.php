<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\AppSettings;
use App\Support\FlyerSvg;
use App\Support\Money;
use App\Support\Qr;
use App\Support\RegistrationLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
            'twintEnabled' => (bool) AppSettings::get(AppSettings::TWINT_ENABLED, AppSettings::twintPhone() !== ''),
            'registrationMode' => AppSettings::registrationMode(),
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
            'registration_mode' => ['required', Rule::in(AppSettings::REGISTRATION_MODES)],
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

        if ($request->boolean('twint_enabled') && trim((string) ($data['twint_phone'] ?? '')) === '' && trim((string) ($data['twint_name'] ?? '')) === '') {
            throw ValidationException::withMessages(['twint_phone' => __('settings.twint_missing')]);
        }

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
            AppSettings::TWINT_ENABLED => $request->boolean('twint_enabled'),
            AppSettings::REGISTRATION_MODE => $data['registration_mode'],
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
     * Printable page with the QR code of the registration URL; includes the flyer code unless
     * ?code=0. Only in mode "password" (the flyer code is not accepted otherwise). The registration
     * password is never put into the QR code, and the URL with the flyer code is not printed.
     */
    public function registrationQr(Request $request): View
    {
        $mode = AppSettings::registrationMode();
        $withCode = $request->boolean('code', true) && AppSettings::registrationOpen();

        return view('backend.registration-qr', [
            'view' => $request->query('view') === 'flyer' ? 'flyer' : 'qr',
            'url' => route('register'),
            'qr' => Qr::dataUri(RegistrationLink::url($withCode), 480),
            'withCode' => $withCode,
            'mode' => $mode,
            'open' => AppSettings::registrationOpen(),
            'appName' => AppSettings::appName(),
        ]);
    }

    /** Flyer as A4 SVG image (preview, print, download); same options as the QR page (?code=0). */
    public function flyerSvg(Request $request): Response
    {
        $mode = AppSettings::registrationMode();
        $open = AppSettings::registrationOpen();
        $withCode = $request->boolean('code', true) && $open;
        $step2 = $withCode ? __('settings.flyer_step2_text')
            : ($mode === AppSettings::MODE_INVITE ? __('settings.flyer_step2_text_invite') : __('settings.flyer_step2_text_no_code'));

        $svg = FlyerSvg::render(AppSettings::appName(), RegistrationLink::url($withCode), route('register'), $step2, $open);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Content-Disposition' => $disposition.'; filename="bierkasse-flyer.svg"',
            // Contains the flyer code: never stored by shared caches.
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** New flyer code: every printed flyer/QR code with the old code stops working. */
    public function regenerateFlyerCode(Request $request): RedirectResponse
    {
        AppSettings::regenerateFlyerCode();

        return redirect()->route('backend.registration.qr', $request->query('view') === 'flyer' ? ['view' => 'flyer'] : [])
            ->with('status', __('settings.flyer_code_regenerated'));
    }
}
