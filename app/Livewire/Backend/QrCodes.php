<?php

namespace App\Livewire\Backend;

use App\Livewire\Concerns\AuthorizesAdminArea;
use App\Models\QrCode;
use App\Models\RegistrationCode;
use App\Support\AppSettings;
use Illuminate\View\View;
use Livewire\Component;

/**
 * "QR-Codes" (kassier and admin, merged in 1.20.0): overview of the active codes per kind with the pages that
 * show and print them; admins additionally see the management parts (new code, rename, deactivate,
 * history with reactivation, registration codes). The management forms post to the admin-only routes
 * (role:admin), so the permissions are exactly the ones of the former page "QR-Codes (Verwaltung)".
 */
class QrCodes extends Component
{
    use AuthorizesAdminArea;

    public function mount(): void
    {
        // Admin path: the only place where missing first codes are created on a GET (never for kassiers).
        if ($this->isAdmin()) {
            QrCode::ensureFirstForAdmin(auth()->user());
        }
    }

    public function render(): View
    {
        $admin = $this->isAdmin();
        $active = fn (string $kind) => QrCode::query()->active()->where('kind', $kind)->count();
        $row = fn (string $key, string $url, int $active) => compact('key', 'url', 'active');
        $data = [
            'admin' => $admin,
            'rows' => [
                $row('cashbox_cash', route('backend.qr-codes.cashbox'), $active(QrCode::KIND_CASH)),
                $row('cashbox_twint', route('backend.qr-codes.cashbox'), $active(QrCode::KIND_TWINT)),
                $row('guest', route('backend.guests.qr'), $active(QrCode::KIND_GUEST)),
                $row('registration', route('backend.registration.qr'), RegistrationCode::query()->whereNull('locked_at')->count()),
                $row('flyer', route('backend.registration.qr'), $active(QrCode::KIND_FLYER)),
            ],
            'cashboxEnabled' => (bool) AppSettings::get(AppSettings::CASHBOX_QR_ENABLED, false),
            'guestOpen' => AppSettings::guestOpen(),
            'registrationOpen' => AppSettings::registrationOpen(),
            'twintEnabled' => AppSettings::twintEnabled(),
        ];

        if ($admin) {
            $codes = QrCode::query()->with('creator')->orderBy('id')->get()->groupBy('kind');
            $data['kinds'] = array_map(fn (string $kind) => [
                'kind' => $kind,
                'active' => ($codes[$kind] ?? collect())->filter->isActive()->values(),
                'history' => ($codes[$kind] ?? collect())->reject->isActive()->values(),
            ], QrCode::KINDS);
            $data['registrationCodes'] = RegistrationCode::query()->with('creator')->withCount('users')->orderBy('label')->get();
        }

        return view('livewire.backend.qr-codes', $data);
    }
}
