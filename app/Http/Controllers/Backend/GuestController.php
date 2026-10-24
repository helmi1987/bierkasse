<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\GuestSale;
use App\Models\QrCode;
use App\Models\Setting;
use App\Services\GuestSales;
use App\Support\AppSettings;
use App\Support\CashboxQrSvg;
use App\Support\Qr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Guests (kassier and admin): booking by logged-in users on/off, guest sales with storno.
 * QR codes (public guest page, cashbox stickers cash / TWINT): view of the active codes only;
 * switches, guest password and codes are managed by admins (QrCodeController).
 */
class GuestController extends Controller
{
    public function index(Request $request): View
    {
        return view('backend.guests', [
            'admin' => $request->user()->isAdmin(),
            'userBooking' => AppSettings::guestUserBooking(),
            'open' => AppSettings::guestOpen(),
            'cashboxEnabled' => (bool) AppSettings::get(AppSettings::CASHBOX_QR_ENABLED, false),
            'twintEnabled' => AppSettings::twintEnabled(),
            'cashboxActive' => QrCode::query()->active()->whereIn('kind', [QrCode::KIND_CASH, QrCode::KIND_TWINT])->orderBy('id')->get(),
            'sales' => GuestSale::query()->with('bookedBy')->latest('id')->paginate(30),
            'total' => ['beers' => (int) GuestSale::query()->sum('beers'), 'amount' => (int) GuestSale::query()->sum('amount')],
        ]);
    }

    /** Booking by users on/off; the QR switches and the guest password are on the QR management page. */
    public function update(Request $request): RedirectResponse
    {
        Setting::put(AppSettings::GUEST_USER_BOOKING, $request->boolean('guest_user_booking'));

        return back()->with('status', __('settings.saved'));
    }

    public function destroy(GuestSale $sale): RedirectResponse
    {
        GuestSales::cancel($sale);

        return back()->with('status', __('guest.cancelled'));
    }

    /**
     * Printable guest QR page: the selected active guest code (?qr=<id>) or the plain guest URL
     * without code (?qr=0, password typed by hand). The guest password is never put into a QR code.
     */
    public function qr(Request $request): View
    {
        $active = QrCode::query()->with('creator')->active()->where('kind', QrCode::KIND_GUEST)->orderBy('id')->get();
        $selected = $request->query('qr') === '0' ? null : ($active->firstWhere('id', (int) $request->query('qr')) ?? $active->first());
        $url = $selected?->url() ?? route('guest');

        return view('backend.guests-qr', [
            'admin' => $request->user()->isAdmin(),
            'active' => $active,
            'selected' => $selected,
            'url' => $url,
            'qr' => Qr::dataUri($url, 480),
            'open' => AppSettings::guestOpen(),
            'appName' => AppSettings::appName(),
        ]);
    }

    /** Cashbox QR sticker as SVG (?download=1 as attachment). */
    public function cashboxSvg(Request $request, QrCode $qrCode): Response
    {
        $method = $this->cashboxMethod($request, $qrCode);

        return $this->svg($request, CashboxQrSvg::sticker(AppSettings::appName(), $method, $qrCode->url()),
            'kassen-qr-'.($method === 'cash' ? 'bar' : 'twint').'-'.$qrCode->id.'.svg');
    }

    /** A4 sheet with 4 stickers of one cashbox code. */
    public function cashboxSheet(Request $request, QrCode $qrCode): Response
    {
        $method = $this->cashboxMethod($request, $qrCode);

        return $this->svg($request, CashboxQrSvg::sheet(AppSettings::appName(), array_fill(0, 4, [$method, $qrCode->url()])),
            'kassen-qr-a4-'.($method === 'cash' ? 'bar' : 'twint').'-'.$qrCode->id.'.svg');
    }

    /** A4 sheet with 2 cash + 2 TWINT stickers (newest active code of each). */
    public function cashboxMixedSheet(Request $request): Response
    {
        $cash = QrCode::newestActive(QrCode::KIND_CASH);
        $twint = QrCode::newestActive(QrCode::KIND_TWINT);
        abort_if($cash === null || $twint === null, 404);

        return $this->svg($request, CashboxQrSvg::sheet(AppSettings::appName(), [
            ['cash', $cash->url()], ['cash', $cash->url()], ['twint', $twint->url()], ['twint', $twint->url()],
        ]), 'kassen-qr-a4-bar-twint.svg');
    }

    /** Method of a cashbox code; deactivated codes only for admins (management page). */
    private function cashboxMethod(Request $request, QrCode $qrCode): string
    {
        $method = $qrCode->method();
        abort_if($method === null || (! $qrCode->isActive() && ! $request->user()->isAdmin()), 404);

        return $method;
    }

    private function svg(Request $request, string $svg, string $filename): Response
    {
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
            // Contains the code: never stored by shared caches.
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
