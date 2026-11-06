<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Ledger;
use App\Services\LedgerException;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kassier dashboard: open deposits, payouts and carry-overs, users in minus, cash box overview.
 * The page is the Livewire component App\Livewire\Backend\Dashboard (1.36.0); the forms post here.
 */
class DashboardController extends Controller
{
    /** Host page of the Livewire component App\Livewire\Backend\Dashboard (1.36.0). */
    public function index(): View
    {
        return view('backend.dashboard');
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

    /** Carry-over from the old tally list; the kassier may correct the signed amount (CHF) before confirming. */
    public function confirmCarryover(Request $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validate(['amount' => ['nullable', 'string', 'max:20']]);
        $amount = null;
        if (trim((string) ($data['amount'] ?? '')) !== '') {
            $amount = Money::parse($data['amount']);
            if ($amount === null || $amount === 0) {
                return back()->with('error', __('ledger.amount_invalid'));
            }
        }

        return $this->run(fn () => Ledger::confirmCarryover($transaction, $request->user(), $amount), __('ledger.carryover_confirmed'));
    }

    public function rejectCarryover(Request $request, Transaction $transaction): RedirectResponse
    {
        return $this->run(fn () => Ledger::rejectCarryover($transaction, $request->user()), __('ledger.carryover_rejected'));
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
