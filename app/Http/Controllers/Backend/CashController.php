<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\CashEntry;
use App\Models\Transaction;
use App\Services\CashBook;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Cash book "Kasse": expenses, other income and adjustments; balance split cash / TWINT.
 */
class CashController extends Controller
{
    public function index(): View
    {
        return view('backend.cash', [
            'overview' => CashBook::overview(),
            'entries' => CashEntry::query()->with('creator')->orderByDesc('date')->orderByDesc('id')->paginate(30),
            'deposits' => (int) Transaction::query()->confirmed()->where('type', Transaction::TYPE_DEPOSIT)->sum('amount'),
            'payouts' => (int) -Transaction::query()->confirmed()->where('type', Transaction::TYPE_PAYOUT)->sum('amount'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(CashEntry::TYPES)],
            'date' => ['required', 'date'],
            'amount' => ['required', 'string', 'max:20'],
            'method' => ['required', Rule::in(Transaction::METHODS)],
            'note' => ['required', 'string', 'max:500'],
        ]);

        $amount = Money::parse($data['amount']);
        if ($amount === null || $amount === 0 || ($data['type'] !== 'adjustment' && $amount < 0)) {
            throw ValidationException::withMessages(['amount' => __('ledger.amount_invalid')]);
        }

        CashEntry::query()->create([
            'type' => $data['type'],
            'date' => $data['date'],
            'amount' => CashBook::signedAmount($data['type'], $amount),
            'method' => $data['method'],
            'note' => $data['note'],
            'created_by' => $request->user()->id,
        ]);

        return back()->with('status', __('cash.saved'));
    }

    public function destroy(CashEntry $entry): RedirectResponse
    {
        $entry->delete();

        return back()->with('status', __('cash.deleted'));
    }
}
