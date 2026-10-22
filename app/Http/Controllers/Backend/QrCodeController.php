<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\QrCode;
use App\Models\RegistrationCode;
use App\Services\Audit;
use App\Support\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * QR codes: overview of all kinds (kassiers and admins, kassiers only count active ones);
 * management (admins): new code with optional name, deactivate / reactivate. Nothing is deleted,
 * deactivated codes stay as history. Shown on the guest and registration QR pages.
 */
class QrCodeController extends Controller
{
    /** Overview: active (and for admins deactivated) codes per kind with the page that shows them. */
    public function index(Request $request): View
    {
        $admin = $request->user()->isAdmin();
        $count = fn (string $kind, bool $active) => QrCode::query()->where('kind', $kind)
            ->when($active, fn ($q) => $q->whereNull('deactivated_at'), fn ($q) => $q->whereNotNull('deactivated_at'))->count();
        $row = fn (string $key, string $url, int $active, int $inactive) => compact('key', 'url', 'active', 'inactive');

        return view('backend.qr-codes', [
            'admin' => $admin,
            'rows' => [
                $row('cashbox_cash', route('backend.guests').'#kassen-qr', $count(QrCode::KIND_CASH, true), $count(QrCode::KIND_CASH, false)),
                $row('cashbox_twint', route('backend.guests').'#kassen-qr', $count(QrCode::KIND_TWINT, true), $count(QrCode::KIND_TWINT, false)),
                $row('guest', route('backend.guests.qr'), $count(QrCode::KIND_GUEST, true), $count(QrCode::KIND_GUEST, false)),
                $row('registration', route('backend.registration.qr'), RegistrationCode::query()->whereNull('locked_at')->count(), RegistrationCode::query()->whereNotNull('locked_at')->count()),
                $row('flyer', route('backend.registration.qr'), $count(QrCode::KIND_FLYER, true), $count(QrCode::KIND_FLYER, false)),
            ],
            'cashboxEnabled' => (bool) AppSettings::get(AppSettings::CASHBOX_QR_ENABLED, false),
            'guestOpen' => AppSettings::guestOpen(),
            'registrationOpen' => AppSettings::registrationOpen(),
        ]);
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
        $active = $request->boolean('active');
        DB::transaction(function () use ($qrCode, $active) {
            $old = ['active' => $qrCode->isActive()];
            $qrCode->forceFill(['deactivated_at' => $active ? null : now()])->save();
            Audit::log($active ? 'qr_code.activate' : 'qr_code.deactivate', $qrCode, $old, ['active' => $active, 'kind' => $qrCode->kind]);
        });

        return back()->with('status', $active ? __('qr.activated') : __('qr.deactivated'));
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
