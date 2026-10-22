<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\GuestSales;
use App\Services\LedgerException;
use App\Support\AppSettings;
use App\Support\FormToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Public guest sale (no login), only with the guest password. The guest QR code in
 * the backend may carry the password as ?code=… to prefill it. Booked immediately.
 */
class GuestController extends Controller
{
    /** Attempts per IP per minute (also wrong codes). */
    private const MAX_PER_IP = 10;

    public function show(Request $request): View
    {
        abort_unless(AppSettings::guestOpen(), 404);

        return view('guest.form', [
            'code' => (string) $request->query('code', ''),
            'price' => AppSettings::beerPrice(),
            'twintEnabled' => AppSettings::twintEnabled(),
            'twintRecipients' => AppSettings::twintRecipients(),
            'twintHtml' => AppSettings::twintRecipientText(true),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(AppSettings::guestOpen(), 404);
        $back = fn (string $field, string $message) => back()
            ->withInput($request->only(['name', 'quantity', 'method']))->withErrors([$field => $message]);

        $key = 'guest-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_IP)) {
            return $back('code', __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]));
        }
        RateLimiter::hit($key, 60);

        // The guest password is checked first; nothing else is processed without it.
        $code = $request->input('code');
        if (! is_string($code) || $code === '' || ! hash_equals(AppSettings::guestPassword(), $code)) {
            return $back('code', __('guest.code_wrong'));
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'between:1,'.GuestSales::MAX_BEERS],
            'method' => ['required', Rule::in(AppSettings::twintEnabled() ? Transaction::METHODS : array_values(array_diff(Transaction::METHODS, ['twint'])))],
        ]);
        if (! FormToken::consume($request, 'guest')) {
            return back()->withInput($request->only(['name', 'quantity', 'method']))->with('warning', __('ledger.duplicate'));
        }

        try {
            $sale = GuestSales::book((int) $data['quantity'], $data['method'], $data['name'] ?? null);
        } catch (LedgerException $e) {
            return $back('quantity', $e->getMessage());
        }

        return redirect()->route('guest.thanks')->with('guest.sale', [
            'beers' => $sale->beers,
            'amount' => $sale->amount,
            'method' => $sale->method,
        ]);
    }

    public function thanks(Request $request): View|RedirectResponse
    {
        $sale = $request->session()->get('guest.sale');
        if (! is_array($sale)) {
            return redirect()->route('guest');
        }

        return view('guest.thanks', [
            'sale' => $sale,
            'twintEnabled' => AppSettings::twintEnabled(),
            'twintRecipients' => AppSettings::twintRecipients(),
            'twintHtml' => AppSettings::twintRecipientText(true),
        ]);
    }
}
