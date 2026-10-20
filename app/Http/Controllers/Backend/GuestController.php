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
 * Guests (kassier and admin): booking by logged-in users on/off, public guest page on/off with
 * guest password and printable QR code, guest sales with storno.
 */
class GuestController extends Controller
{
    public function index(): View
    {
        return view('backend.guests', [
            'userBooking' => AppSettings::guestUserBooking(),
            'enabled' => (bool) AppSettings::get(AppSettings::GUEST_ENABLED, false),
            'password' => AppSettings::guestPassword(),
            'open' => AppSettings::guestOpen(),
            'sales' => GuestSale::query()->with('bookedBy')->latest('id')->paginate(30),
            'total' => ['beers' => (int) GuestSale::query()->sum('beers'), 'amount' => (int) GuestSale::query()->sum('amount')],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        // The public guest page can only be enabled together with a guest password.
        $data = $request->validate(['guest_password' => ['nullable', 'required_if_accepted:guest_enabled', 'string', 'min:4', 'max:100']],
            ['guest_password.required_if_accepted' => __('guest.password_required')]);

        Setting::putMany([
            AppSettings::GUEST_USER_BOOKING => $request->boolean('guest_user_booking'),
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
