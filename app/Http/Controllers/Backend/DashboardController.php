<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Controllers\StatsController;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BeerStock;
use App\Services\CashBook;
use App\Services\Ledger;
use App\Services\LedgerException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kassier dashboard: open deposits and payouts, users in minus, cash box overview.
 */
class DashboardController extends Controller
{
    public function index(): View
    {
        $balances = Ledger::balances();
        $minusIds = array_keys(array_filter($balances, fn ($b) => $b < 0));
        $minus = User::query()->whereIn('id', $minusIds)->get()
            ->map(fn (User $u) => ['user' => $u, 'balance' => $balances[$u->id]])
            ->sortBy('balance')->values();

        return view('backend.dashboard', [
            'deposits' => Ledger::pendingDeposits(),
            'payouts' => Ledger::pendingPayouts(),
            'minus' => $minus,
            'overview' => CashBook::overview(),
            'stock' => BeerStock::current(),
            'stockMin' => BeerStock::minimum(),
        ]);
    }

    public function confirmDeposit(Request $request, Transaction $transaction): RedirectResponse
    {
        return $this->run(fn () => Ledger::confirmDeposit($transaction, $request->user()), __('ledger.deposit_confirmed'));
    }

    public function rejectDeposit(Request $request, Transaction $transaction): RedirectResponse
    {
        return $this->run(fn () => Ledger::rejectDeposit($transaction, $request->user()), __('ledger.deposit_rejected'));
    }

    public function confirmPayout(Request $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validate(['method' => ['nullable', Rule::in(Transaction::METHODS)]]);

        return $this->run(fn () => Ledger::confirmPayout($transaction, $request->user(), $data['method'] ?? 'cash'), __('ledger.payout_confirmed'));
    }

    public function cancelPayout(Request $request, Transaction $transaction): RedirectResponse
    {
        return $this->run(fn () => Ledger::cancelPayout($transaction, $request->user()), __('ledger.payout_cancelled'));
    }

    public function stats(): View
    {
        return view('stats.index', StatsController::data() + ['backend' => true]);
    }

    private function run(callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $message);
    }
}
