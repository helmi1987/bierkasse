<?php

namespace App\Http\Controllers;

use App\Models\GuestSale;
use App\Services\GuestSales;
use App\Services\LedgerException;
use App\Support\AppSettings;
use App\Support\FormToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Cashbox QR sticker (no login): a guest confirms the number of beers already paid cash or by TWINT
 * (trust based). The secret code in the URL selects the method and opens only this page;
 * the cash page never shows TWINT data. Booked immediately as a guest sale.
 */
class CashboxQrController extends Controller
{
    /** Attempts per IP per minute (also wrong codes). */
    private const MAX_PER_IP = 10;

    public function show(Request $request, string $code): View
    {
        $method = $this->method($request, $code);

        return view('guest.cashbox', [
            'code' => $code,
            'method' => $method,
            'price' => AppSettings::beerPrice(),
            'sale' => $request->session()->get('cashbox.sale'),
            'twintRecipients' => $method === 'twint' ? AppSettings::twintRecipients() : [],
            'twintHtml' => $method === 'twint' ? AppSettings::twintRecipientText(true) : '',
        ]);
    }

    public function store(Request $request, string $code): RedirectResponse
    {
        $method = $this->method($request, $code);
        $data = $request->validate(['quantity' => ['required', 'integer', 'between:1,'.GuestSales::MAX_BEERS]]);
        if (! FormToken::consume($request, 'cashbox')) {
            return back()->withInput()->with('warning', __('ledger.duplicate'));
        }

        try {
            $sale = GuestSales::book((int) $data['quantity'], $method, null, null, GuestSale::SOURCE_CASHBOX);
        } catch (LedgerException $e) {
            return back()->withInput()->withErrors(['quantity' => $e->getMessage()]);
        }

        return redirect()->route('cashbox', $code)->with('cashbox.sale', ['beers' => $sale->beers, 'amount' => $sale->amount]);
    }

    /** Method of the code; 404 for invalid codes (counted per IP, no hint whether a code exists). */
    private function method(Request $request, string $code): string
    {
        $key = 'cashbox-ip:'.$request->ip();
        abort_if(RateLimiter::tooManyAttempts($key, self::MAX_PER_IP), 429);
        $method = AppSettings::cashboxQrMethod($code);
        if ($method === null) {
            RateLimiter::hit($key, 60);
            abort(404);
        }

        return $method;
    }
}
