<?php

namespace App\Http\Controllers;

use App\Models\GuestSale;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BeerStock;
use App\Services\Favorites;
use App\Services\Gifts;
use App\Services\GuestSales;
use App\Services\Ledger;
use App\Services\LedgerException;
use App\Services\NoticeDismissals;
use App\Services\Rounds;
use App\Services\Stats;
use App\Support\AppSettings;
use App\Support\BarChart;
use App\Support\DepositSuggestions;
use App\Support\FormToken;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        // Includes reported deposits/carry-overs not yet confirmed (decision 2026-10-23): one number for the user.
        $balance = $user->availableBalance();
        $price = AppSettings::beerPrice();
        $stats = Stats::forUser($user);

        $undoable = Transaction::query()->where('user_id', $user->id)
            ->where('type', Transaction::TYPE_CONSUMPTION)
            ->where('created_by', $user->id)
            ->where('created_at', '>', now()->subMinutes(AppSettings::undoMinutes('consume')))
            ->latest('id')->get();

        $suggestions = DepositSuggestions::for($balance, $price, AppSettings::depositSuggestions());
        $suggested = $suggestions[DepositSuggestions::defaultIndex($suggestions, AppSettings::depositDefault(), $price)] ?? null;

        // Booking for guests (own guest sales within the undo window can be undone).
        $guestBooking = AppSettings::guestUserBooking();
        $guestUndoable = GuestSale::query()->where('booked_by', $user->id)
            ->where('created_at', '>', now()->subMinutes(AppSettings::undoMinutes('guest')))
            ->latest('id')->get();

        // "Runde spendieren": colleagues to choose from, own rounds within the undo window, rounds received.
        $rounds = AppSettings::roundsAllowed();
        $roundUndoable = $rounds ? Transaction::query()->with('roundItems.user')->where('user_id', $user->id)
            ->where('type', Transaction::TYPE_ROUND)->where('created_by', $user->id)
            ->where('created_at', '>', now()->subMinutes(AppSettings::undoMinutes('round')))
            ->latest('id')->get() : collect();

        // "Bier verschenken": own gifts within the undo window, gifts received.
        $gifts = AppSettings::giftsAllowed();
        $giftUndoable = $gifts ? Transaction::query()->with('giftParts.user')->where('user_id', $user->id)
            ->where('type', Transaction::TYPE_GIFT)->whereNull('parent_id')
            ->where('created_at', '>', now()->subMinutes(AppSettings::undoMinutes('gift')))
            ->latest('id')->get()->reject(fn (Transaction $tx) => Gifts::recipientsBookedSince($tx))->values() : collect();

        // Beers left in the cellar (only once the kassier maintains the stock).
        $stock = BeerStock::isTracked() ? BeerStock::current() : null;

        return view('account.dashboard', [
            'user' => $user,
            'stock' => $stock,
            'stockLow' => $stock !== null && BeerStock::isLow($stock),
            'guestBooking' => $guestBooking,
            'guestUndoable' => $guestUndoable,
            'rounds' => $rounds,
            'colleagues' => $rounds || $gifts ? Favorites::arrange(Rounds::colleagues($user), Favorites::ids($user)) : collect(),
            'gifts' => $gifts,
            'giftUndoable' => $giftUndoable,
            'giftsReceived' => Gifts::receivedRecently($user),
            'roundUndoable' => $roundUndoable,
            'roundsReceived' => $rounds ? Rounds::receivedRecently($user) : collect(),
            'balance' => $balance,
            'price' => $price,
            'open' => Ledger::openItems($user, true),
            'undoable' => $undoable,
            'pending' => Transaction::query()->where('user_id', $user->id)->pending()->latest('id')->get(),
            'twintEnabled' => AppSettings::twintEnabled(),
            'twintRecipients' => AppSettings::twintRecipients(),
            'twintHtml' => AppSettings::twintRecipientText(true),
            'suggestions' => $suggestions,
            'suggested' => $suggested,
            'recent' => Transaction::query()->with(['roundItems.user', 'giftParts.user', 'giftParent.user'])->where('user_id', $user->id)->latest('id')->limit(5)->get(),
            'stats' => $stats,
            'canInvite' => AppSettings::invitationsAllowed(),
            'chart' => BarChart::render(
                array_column($stats['months'], 'label'),
                array_column($stats['months'], 'beers'),
                __('stats.chart_my_months'),
            ),
        ]);
    }

    /** Hides a "Runde/Bier erhalten" notice for good (stored per user, works on every device). */
    public function dismissNotice(Request $request, Transaction $transaction): RedirectResponse
    {
        abort_unless(NoticeDismissals::dismiss($request->user(), $transaction), 404);

        return back();
    }

    /**
     * Pins or unpins a colleague (top of the "Runde spendieren" / "Bier verschenken" lists).
     * JSON answer for the script (keeps the form selection), redirect without JavaScript.
     * Only active colleagues with a beer account can be pinned; unpinning always works.
     */
    public function toggleFavorite(Request $request, int $colleague): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        $target = User::query()->find($colleague);
        $isFavorite = in_array($colleague, Favorites::ids($user), true);
        if (! $isFavorite) {
            abort_unless($target !== null && Rounds::colleagues($user)->contains('id', $colleague), 404);
        }
        $pinned = $target !== null ? Favorites::toggle($user, $target) : false;
        if ($target === null) {
            DB::table('user_favorites')->where(['user_id' => $user->id, 'favorite_user_id' => $colleague])->delete();
        }

        return $request->expectsJson() ? response()->json(['pinned' => $pinned]) : back();
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

        return back()->with('status', match ($transaction->type) {
            Transaction::TYPE_ROUND => __('round.undone'),
            Transaction::TYPE_GIFT => __('gift.undone'),
            default => __('ledger.undone'),
        });
    }

    /**
     * "Runde spendieren": one beer for each chosen colleague plus beers without a name,
     * paid by the logged-in user (same limit rules as own beers).
     */
    public function round(Request $request): RedirectResponse
    {
        abort_unless(AppSettings::roundsAllowed(), 404);
        $data = $request->validate([
            'recipients' => ['nullable', 'array', 'max:'.Rounds::MAX_BEERS],
            'recipients.*' => ['integer', 'distinct'],
            'extra' => ['nullable', 'integer', 'between:0,'.Rounds::MAX_EXTRA],
            'message' => ['nullable', 'string', 'max:'.Rounds::MESSAGE_MAX],
            'include_self' => ['nullable', 'boolean'],
        ]);
        $input = ['recipients', 'extra', 'message'];
        if (! FormToken::consume($request, 'round')) {
            return back()->with('warning', __('ledger.duplicate'));
        }

        try {
            $tx = Ledger::giveRound($request->user(), array_map('intval', $data['recipients'] ?? []), (int) ($data['extra'] ?? 0), $data['message'] ?? null, $request->boolean('include_self'));
        } catch (LedgerException $e) {
            return back()->withInput($request->only($input))->with('error', $e->getMessage());
        }

        return back()->with('status', trans_choice('round.booked', $tx->quantity, ['count' => $tx->quantity, 'amount' => chf(-$tx->amount)]));
    }

    /**
     * "Bier verschenken": the same number of beers as CHF credit for each chosen colleague,
     * paid by the logged-in user (same limit rules as own beers).
     */
    public function gift(Request $request): RedirectResponse
    {
        abort_unless(AppSettings::giftsAllowed(), 404);
        $data = $request->validate([
            'gift_recipients' => ['required', 'array', 'max:'.Gifts::MAX_RECIPIENTS],
            'gift_recipients.*' => ['integer', 'distinct'],
            'gift_quantity' => ['required', 'integer', 'between:1,'.Gifts::MAX_PER_RECIPIENT],
            'gift_message' => ['nullable', 'string', 'max:'.Gifts::MESSAGE_MAX],
        ], ['gift_recipients.required' => __('gift.none_selected')]);
        $input = ['gift_recipients', 'gift_quantity', 'gift_message'];
        if (! FormToken::consume($request, 'gift')) {
            return back()->with('warning', __('ledger.duplicate'));
        }

        try {
            $tx = Ledger::giveBeers($request->user(), array_map('intval', $data['gift_recipients']), (int) $data['gift_quantity'], $data['gift_message'] ?? null);
        } catch (LedgerException $e) {
            return back()->withInput($request->only($input))->with('error', $e->getMessage());
        }

        return back()->with('status', trans_choice('gift.booked', $tx->quantity, ['count' => $tx->quantity, 'amount' => chf(-$tx->amount)]));
    }

    /**
     * Beers for a guest, booked by the logged-in user: paid immediately by the guest
     * (cash or TWINT), never touches the user's balance or limit.
     */
    public function guest(Request $request): RedirectResponse
    {
        abort_unless(AppSettings::guestUserBooking(), 404);
        $methods = AppSettings::twintEnabled() ? Transaction::METHODS : array_values(array_diff(Transaction::METHODS, ['twint']));
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

        $replace = ['count' => $sale->beers, 'amount' => chf($sale->amount), 'phone' => AppSettings::twintRecipientText()];

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
            'balance' => $user->availableBalance(),
            'transactions' => Transaction::query()->with(['roundItems.user', 'giftParts.user', 'giftParent.user'])->where('user_id', $user->id)->latest('id')->paginate(25),
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
            'balance' => $user->availableBalance(),
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
