<?php

namespace App\Services;

use App\Models\CashEntry;
use App\Models\Transaction;

/**
 * Cash box balance: confirmed deposits - confirmed payouts - expenses + income +/- adjustments,
 * split by method (cash / TWINT).
 */
class CashBook
{
    /**
     * @return array{cash:int, twint:int, total:int}
     */
    public static function balance(): array
    {
        $sums = ['cash' => 0, 'twint' => 0];

        $ledger = Transaction::query()->confirmed()
            ->whereIn('type', [Transaction::TYPE_DEPOSIT, Transaction::TYPE_PAYOUT])
            ->selectRaw('method, SUM(amount) as total')->groupBy('method')->pluck('total', 'method');
        $book = CashEntry::query()->selectRaw('method, SUM(amount) as total')->groupBy('method')->pluck('total', 'method');

        foreach ([$ledger, $book] as $rows) {
            foreach ($rows as $method => $total) {
                $key = $method === 'twint' ? 'twint' : 'cash';
                $sums[$key] += (int) $total;
            }
        }

        return $sums + ['total' => $sums['cash'] + $sums['twint']];
    }

    /**
     * @return array{cash:array{cash:int,twint:int,total:int}, credit:int, surplus:int}
     */
    public static function overview(): array
    {
        $cash = self::balance();
        $credit = Ledger::totalCredit();

        return ['cash' => $cash, 'credit' => $credit, 'surplus' => $cash['total'] - $credit];
    }

    /** Signed amount for a cash book entry: expenses negative, income positive, adjustments as entered. */
    public static function signedAmount(string $type, int $amount): int
    {
        return match ($type) {
            'expense_beer', 'expense_other' => -abs($amount),
            'income' => abs($amount),
            default => $amount,
        };
    }
}
