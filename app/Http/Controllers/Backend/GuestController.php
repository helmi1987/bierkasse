<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\GuestSale;
use App\Models\Setting;
use App\Services\GuestSales;
use App\Support\AppSettings;
use App\Support\Qr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Guests (kassier and admin): guest password, on/off, printable QR code, guest sales with storno.
 */
class GuestController extends Controller
{
    public function index(): View
    {
        return view('backend.guests', [
            'enabled' => (bool) AppSettings::get(AppSettings::GUEST_ENABLED, false),
            'password' => AppSettings::guestPassword(),
            'open' => AppSettings::guestOpen(),
            'sales' => GuestSale::query()->latest('id')->paginate(30),
            'total' => ['beers' => (int) GuestSale::query()->sum('beers'), 'amount' => (int) GuestSale::query()->sum('amount')],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['guest_password' => ['nullable', 'string', 'min:4', 'max:100']]);

        Setting::putMany([
            AppSettings::GUEST_ENABLED => $request->boolean('guest_enabled'),
            AppSettings::GUEST_PASSWORD => (string) ($data['guest_password'] ?? ''),
        ]);

        return back()->with('status', __('settings.saved'));
    }

    public function destroy(GuestSale $sale): RedirectResponse
    {
        GuestSales::cancel($sale);

        return back()->with('status', __('guest.cancelled'));
    }

    /**
     * Printable page with the QR code of the guest URL; includes the password unless ?code=0.
     */
    public function qr(Request $request): View
    {
        $withCode = $request->boolean('code', true) && AppSettings::guestPassword() !== '';
        $url = route('guest');
        if ($withCode) {
            $url .= '?'.http_build_query(['code' => AppSettings::guestPassword()]);
        }

        return view('backend.guests-qr', [
            'url' => $url,
            'qr' => Qr::dataUri($url, 480),
            'withCode' => $withCode,
            'open' => AppSettings::guestOpen(),
            'appName' => AppSettings::appName(),
        ]);
    }
}
