<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\RegistrationCode;
use App\Models\Setting;
use App\Models\User;
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
            'twintRecipients' => AppSettings::twintRecipients(),
            'twintEnabled' => AppSettings::twintSwitch(),
            // Proposals for the name; free text stays possible (datalist).
            'twintNames' => User::query()->whereIn('role', [User::ROLE_ADMIN, User::ROLE_KASSIER])
                ->where('status', User::STATUS_ACTIVE)->orderBy('first_name')->orderBy('last_name')->get()
                ->map(fn (User $u) => $u->displayName())->unique()->values(),
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
            'twint' => ['nullable', 'array', 'max:'.AppSettings::TWINT_MAX],
            'twint.*.name' => ['nullable', 'string', 'max:100'],
            'twint.*.phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+ ()\/-]*$/'],
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

        $twint = [];
        foreach ($data['twint'] ?? [] as $r) {
            $r = ['name' => trim((string) ($r['name'] ?? '')), 'phone' => trim((string) ($r['phone'] ?? ''))];
            if ($r['name'] !== '' || $r['phone'] !== '') {
                $twint[] = $r;
            }
        }
        if ($request->boolean('twint_enabled') && $twint === []) {
            throw ValidationException::withMessages(['twint' => __('settings.twint_missing')]);
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
            AppSettings::TWINT_RECIPIENTS => $twint,
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
     * Registration QR page: list of the multi-use registration codes (valid in every mode) and, in
     * mode "password", the flyer code. QR and flyer of the selected one (?qr=<id>|flyer); there is
     * no QR without a code. The registration password is never put into a QR code.
     */
    public function registrationQr(Request $request): View
    {
        $target = $this->qrTarget($request);

        return view('backend.registration-qr', [
            'view' => $request->query('view') === 'flyer' ? 'flyer' : 'qr',
            'codes' => RegistrationCode::query()->with('creator')->withCount('users')->orderBy('label')->get(),
            'target' => $target,
            'qr' => $target ? Qr::dataUri($target['url'], 480) : null,
            'open' => AppSettings::registrationOpen(),
            'appName' => AppSettings::appName(),
        ]);
    }

    /** Flyer as A4 SVG image (preview, print, download) of the selected code (?qr=…). */
    public function flyerSvg(Request $request): Response
    {
        $target = $this->qrTarget($request);
        abort_if($target === null, 404);

        $svg = FlyerSvg::render(AppSettings::appName(), $target['url'], $target['printed_url'], __('settings.flyer_step2_text'), $target['key'] === 'flyer');
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Content-Disposition' => $disposition.'; filename="bierkasse-flyer.svg"',
            // Contains the code: never stored by shared caches.
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Selected QR target: an unlocked registration code or the flyer code (only in mode "password");
     * default the first available one. Null when none is available.
     *
     * @return array{key: string, label: string, url: string, printed_url: string}|null
     */
    private function qrTarget(Request $request): ?array
    {
        $targets = RegistrationCode::query()->whereNull('locked_at')->orderBy('label')->get()
            ->map(fn (RegistrationCode $c) => ['key' => (string) $c->id, 'label' => $c->label, 'url' => $c->url(), 'printed_url' => $c->url()])
            ->all();
        if (AppSettings::registrationOpen()) {
            // The URL with the flyer code is not printed (only the plain registration URL).
            $targets[] = ['key' => 'flyer', 'label' => __('settings.flyer_code_label'), 'url' => RegistrationLink::url(true), 'printed_url' => route('register')];
        }
        $selected = (string) $request->query('qr', '');

        return collect($targets)->firstWhere('key', $selected) ?? ($targets[0] ?? null);
    }

    /** New flyer code: every printed flyer/QR code with the old code stops working. */
    public function regenerateFlyerCode(Request $request): RedirectResponse
    {
        AppSettings::regenerateFlyerCode();

        return redirect()->route('backend.registration.qr', ['qr' => 'flyer'] + ($request->query('view') === 'flyer' ? ['view' => 'flyer'] : []))
            ->with('status', __('settings.flyer_code_regenerated'));
    }
}
