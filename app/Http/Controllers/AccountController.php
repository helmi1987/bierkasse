<?php

namespace App\Http\Controllers;

use App\Models\GuestSale;
use App\Models\Transaction;
use App\Services\BeerStock;
use App\Services\GuestSales;
use App\Services\Ledger;
use App\Services\LedgerException;
use App\Services\Stats;
use App\Support\AppSettings;
use App\Support\BarChart;
use App\Support\DepositSuggestions;
use App\Support\FormToken;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * User dashboard "/konto": balance, beers, deposits, history, own statistics, leaving.
 */
class AccountController extends Controller
{
    public function dashboard(Request $request): View
    {
        $user = $request->user();
        $balance = $user->balance();
        $price = AppSettings::beerPrice();
        $stats = Stats::forUser($user);

        $undoable = Transaction::query()->where('user_id', $user->id)
            ->where('type', Transaction::TYPE_CONSUMPTION)
            ->where('created_by', $user->id)
            ->where('created_at', '>', now()->subMinutes(Transaction::UNDO_MINUTES))
            ->latest('id')->get();

        $suggestions = DepositSuggestions::for($balance, $price, AppSettings::depositSuggestions());
        $suggested = $suggestions[DepositSuggestions::defaultIndex($suggestions, AppSettings::depositDefault(), $price)] ?? null;

        // Reported deposits waiting for the kassier: shown only, never part of balance or limit.
        $pendingDeposit = (int) Transaction::query()->where('user_id', $user->id)->pending()
            ->where('type', Transaction::TYPE_DEPOSIT)->sum('amount');

        // Carry-over from the old tally list waiting for the kassier (not editable by the user).
        $pendingCarryover = Transaction::query()->where('user_id', $user->id)->pending()
            ->where('type', Transaction::TYPE_CARRYOVER)->latest('id')->first();

        // Booking for guests (own guest sales within the undo window can be undone).
        $guestBooking = AppSettings::guestUserBooking();
        $guestUndoable = GuestSale::query()->where('booked_by', $user->id)
            ->where('created_at', '>', now()->subMinutes(Transaction::UNDO_MINUTES))
            ->latest('id')->get();

        // Beers left in the cellar (only once the kassier maintains the stock).
        $stock = BeerStock::isTracked() ? BeerStock::current() : null;

        return view('account.dashboard', [
            'user' => $user,
            'stock' => $stock,
            'stockLow' => $stock !== null && BeerStock::isLow($stock),
            'guestBooking' => $guestBooking,
            'guestAvailable' => $guestBooking ? min(GuestSales::MAX_BEERS, max(0, $stock ?? BeerStock::current())) : 0,
            'guestUndoable' => $guestUndoable,
            'pendingDeposit' => $pendingDeposit,
            'pendingCarryover' => $pendingCarryover,
            'balance' => $balance,
            'price' => $price,
            'open' => Ledger::openItems($user),
            'undoable' => $undoable,
            'pending' => Transaction::query()->where('user_id', $user->id)->pending()->latest('id')->get(),
            'twintPhone' => AppSettings::twintPhone(),
            'twintName' => AppSettings::twintName(),
            'suggestions' => $suggestions,
            'suggested' => $suggested,
            'recent' => Transaction::query()->where('user_id', $user->id)->latest('id')->limit(5)->get(),
            'stats' => $stats,
            'canInvite' => AppSettings::invitationsAllowed(),
            'chart' => BarChart::render(
                array_column($stats['months'], 'label'),
                array_column($stats['months'], 'beers'),
                __('stats.chart_my_months'),
            ),
        ]);
    }

    public function consume(Request $request): RedirectResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'between:1,'.Ledger::MAX_QUANTITY]]);
        if (! FormToken::consume($request, 'consume')) {
            return back()->with('warning', __('ledger.duplicate'));
        }

        try {
            $tx = Ledger::consume($request->user(), (int) $data['quantity']);
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', trans_choice('ledger.consumed', $tx->quantity, ['count' => $tx->quantity, 'amount' => chf(-$tx->amount)]));
    }

    public function undo(Request $request, Transaction $transaction): RedirectResponse
    {
        try {
            Ledger::undo($transaction, $request->user());
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', __('ledger.undone'));
    }

    /**
     * Beers for a guest, booked by the logged-in user: paid immediately by the guest
     * (cash or TWINT), never touches the user's balance or limit.
     */
    public function guest(Request $request): RedirectResponse
    {
        abort_unless(AppSettings::guestUserBooking(), 404);
        $twintPhone = AppSettings::twintPhone();
        $methods = $twintPhone !== '' ? Transaction::METHODS : array_values(array_diff(Transaction::METHODS, ['twint']));
        $data = $request->validate([
            'guest_quantity' => ['required', 'integer', 'between:1,'.GuestSales::MAX_BEERS],
            'guest_method' => ['required', Rule::in($methods)],
            'guest_name' => ['nullable', 'string', 'max:100'],
        ]);
        if (! FormToken::consume($request, 'guest-booking')) {
            return back()->with('warning', __('ledger.duplicate'));
        }

        try {
            $sale = GuestSales::book((int) $data['guest_quantity'], $data['guest_method'], $data['guest_name'] ?? null, $request->user());
        } catch (LedgerException $e) {
            return back()->withInput($request->only(['guest_quantity', 'guest_method', 'guest_name']))->with('error', $e->getMessage());
        }

        $replace = ['count' => $sale->beers, 'amount' => chf($sale->amount), 'phone' => $twintPhone];

        return back()->with('status', trans_choice($sale->method === 'twint' ? 'guest.booked_twint' : 'guest.booked_cash', $sale->beers, $replace));
    }

    public function undoGuest(Request $request, GuestSale $sale): RedirectResponse
    {
        try {
            GuestSales::undo($sale, $request->user());
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', __('guest.undone'));
    }

    public function deposit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:20'],
            'method' => ['required', Rule::in(Transaction::METHODS)],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $amount = Money::parse($data['amount']);
        if ($amount === null || $amount <= 0 || $amount > 100000) {
            throw ValidationException::withMessages(['amount' => __('ledger.amount_invalid')]);
        }
        if (! FormToken::consume($request, 'deposit')) {
            return back()->with('warning', __('ledger.duplicate'));
        }

        try {
            Ledger::reportDeposit($request->user(), $amount, $data['method'], $data['note'] ?? null);
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', __('ledger.deposit_reported'));
    }

    /**
     * Own pending deposit report: change amount/method.
     */
    public function updateDeposit(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->guardOwnDeposit($request, $transaction);
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:20'],
            'method' => ['required', Rule::in(Transaction::METHODS)],
        ]);
        $amount = Money::parse($data['amount']);
        if ($amount === null || $amount <= 0 || $amount > 100000) {
            throw ValidationException::withMessages(['amount' => __('ledger.amount_invalid')]);
        }

        try {
            Ledger::updateDeposit($transaction, $request->user(), $amount, $data['method']);
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', __('ledger.deposit_updated'));
    }

    /**
     * Own pending deposit report: withdraw ("nicht bezahlt").
     */
    public function cancelDeposit(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->guardOwnDeposit($request, $transaction);

        try {
            Ledger::cancelDeposit($transaction, $request->user());
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', __('ledger.deposit_cancelled'));
    }

    private function guardOwnDeposit(Request $request, Transaction $transaction): void
    {
        abort_unless($transaction->user_id === $request->user()->id && $transaction->type === Transaction::TYPE_DEPOSIT, 404);
    }

    public function history(Request $request): View
    {
        $user = $request->user();

        return view('account.history', [
            'user' => $user,
            'balance' => $user->balance(),
            'transactions' => Transaction::query()->where('user_id', $user->id)->latest('id')->paginate(25),
        ]);
    }

    public function leaveForm(Request $request): View
    {
        $user = $request->user();
        $blocked = null;
        try {
            Ledger::assertCanLeave($user);
        } catch (LedgerException $e) {
            $blocked = $e->getMessage();
        }

        return view('account.leave', [
            'balance' => $user->balance(),
            'blocked' => $blocked,
            'pendingPayout' => Ledger::hasPendingPayout($user),
        ]);
    }

    public function leave(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'choice' => ['required', Rule::in(['payout', 'donate'])],
            'confirm' => ['accepted'],
        ]);
        $user = $request->user();

        try {
            if ($data['choice'] === 'payout') {
                Ledger::requestPayout($user);

                return redirect()->route('account.dashboard')->with('status', __('ledger.payout_requested'));
            }

            Ledger::donate($user);
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __('ledger.left_thanks'));
    }
}
