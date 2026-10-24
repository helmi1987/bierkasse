<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\QrCode;
use App\Models\RegistrationCode;
use App\Models\Setting;
use App\Services\Audit;
use App\Support\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * QR codes. View (Kassier bar, kassiers and admins): overview of the active codes per kind with the
 * pages that show and print them. Management (Backend bar, admins): switches and guest password,
 * new code with optional name, rename, deactivate / reactivate. Nothing is deleted, deactivated
 * codes stay as history.
 */
class QrCodeController extends Controller
{
    /** View: active codes per kind with the page that shows them. */
    public function index(Request $request): View
    {
        $active = fn (string $kind) => QrCode::query()->active()->where('kind', $kind)->count();
        $row = fn (string $key, string $url, int $active) => compact('key', 'url', 'active');

        return view('backend.qr-codes', [
            'admin' => $request->user()->isAdmin(),
            'rows' => [
                $row('cashbox_cash', route('backend.guests').'#kassen-qr', $active(QrCode::KIND_CASH)),
                $row('cashbox_twint', route('backend.guests').'#kassen-qr', $active(QrCode::KIND_TWINT)),
                $row('guest', route('backend.guests.qr'), $active(QrCode::KIND_GUEST)),
                $row('registration', route('backend.registration.qr'), RegistrationCode::query()->whereNull('locked_at')->count()),
                $row('flyer', route('backend.registration.qr'), $active(QrCode::KIND_FLYER)),
            ],
            'cashboxEnabled' => (bool) AppSettings::get(AppSettings::CASHBOX_QR_ENABLED, false),
            'guestOpen' => AppSettings::guestOpen(),
            'registrationOpen' => AppSettings::registrationOpen(),
        ]);
    }

    /** Management (admins): settings and all codes per kind, deactivated ones as history. */
    public function manage(Request $request): View
    {
        // Admin path: the only place where missing first codes are created on a GET.
        QrCode::ensureFirstForAdmin($request->user());
        $codes = QrCode::query()->with('creator')->orderBy('id')->get()->groupBy('kind');

        return view('backend.qr-admin', [
            'kinds' => array_map(fn (string $kind) => [
                'kind' => $kind,
                'active' => ($codes[$kind] ?? collect())->filter->isActive()->values(),
                'history' => ($codes[$kind] ?? collect())->reject->isActive()->values(),
            ], QrCode::KINDS),
            'registrationCodes' => RegistrationCode::query()->with('creator')->withCount('users')->orderBy('label')->get(),
            'cashboxEnabled' => (bool) AppSettings::get(AppSettings::CASHBOX_QR_ENABLED, false),
            'guestEnabled' => (bool) AppSettings::get(AppSettings::GUEST_ENABLED, false),
            'guestPassword' => AppSettings::guestPassword(),
            'twintEnabled' => AppSettings::twintEnabled(),
            'registrationOpen' => AppSettings::registrationOpen(),
        ]);
    }

    /** Switches of the QR pages and the guest password (admins). */
    public function settings(Request $request): RedirectResponse
    {
        // The public guest page can only be enabled together with a guest password.
        $data = $request->validate(['guest_password' => ['nullable', 'required_if_accepted:guest_enabled', 'string', 'min:4', 'max:100']],
            ['guest_password.required_if_accepted' => __('guest.password_required')]);
        Setting::putMany([
            AppSettings::CASHBOX_QR_ENABLED => $request->boolean('cashbox_qr_enabled'),
            AppSettings::GUEST_ENABLED => $request->boolean('guest_enabled'),
            AppSettings::GUEST_PASSWORD => (string) ($data['guest_password'] ?? ''),
        ]);
        QrCode::ensureFirstForAdmin($request->user());

        return back()->with('status', __('settings.saved'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(QrCode::KINDS)],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        DB::transaction(function () use ($data, $request) {
            $code = QrCode::generate($data['kind'], $data['label'] ?? null, $request->user());
            Audit::log('qr_code.create', $code, [], ['kind' => $code->kind, 'label' => $code->label]);
        });

        return back()->with('status', __('qr.created'));
    }

    public function active(Request $request, QrCode $qrCode): RedirectResponse
    {
        $active = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];
        $changed = false;
        $lastActive = false;
        DB::transaction(function () use ($qrCode, $active, &$changed, &$lastActive) {
            // Re-load with a row lock so two parallel requests cannot both audit the same change.
            $locked = QrCode::query()->lockForUpdate()->findOrFail($qrCode->id);
            if ($locked->isActive() === $active) {
                return;
            }
            $changed = true;
            $locked->forceFill(['deactivated_at' => $active ? null : now()])->save();
            Audit::log($active ? 'qr_code.activate' : 'qr_code.deactivate', $locked, ['active' => ! $active], ['active' => $active, 'kind' => $locked->kind]);
            $lastActive = ! $active && ! QrCode::query()->active()->where('kind', $locked->kind)->exists();
        });
        if (! $changed) {
            return back();
        }

        $redirect = back()->with('status', $active ? __('qr.activated') : __('qr.deactivated'));

        return $lastActive ? $redirect->with('warning', __('qr.last_deactivated.'.$qrCode->kind)) : $redirect;
    }

    public function label(Request $request, QrCode $qrCode): RedirectResponse
    {
        $data = $request->validate(['label' => ['nullable', 'string', 'max:100']]);
        $label = trim((string) ($data['label'] ?? '')) ?: null;
        DB::transaction(function () use ($qrCode, $label) {
            $old = ['label' => $qrCode->label];
            $qrCode->forceFill(['label' => $label])->save();
            Audit::log('qr_code.label', $qrCode, $old, ['label' => $label]);
        });

        return back()->with('status', __('settings.saved'));
    }
}
