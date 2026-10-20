<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\Ledger;
use App\Services\LedgerException;
use App\Services\Stats;
use App\Support\AppSettings;
use App\Support\DepositSuggestions;
use App\Support\BarChart;
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
        $suggested = $suggestions[DepositSuggestions::defaultIndex($suggestions)] ?? null;

        return view('account.dashboard', [
            'user' => $user,
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
