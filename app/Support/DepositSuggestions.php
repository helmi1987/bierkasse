<?php

namespace App\Support;

/**
 * Deposit proposals that buy a whole number of beers at the current price. Each proposal covers an open
 * minus first; the total is rounded to whole francs where the price allows it (e.g. 14 beers = CHF 21.00).
 */
class DepositSuggestions
{
    /** Approximate beer value of the proposals, in Rappen. */
    public const TARGETS = [1000, 2000, 3000, 5000];

    /**
     * @return list<array{beers: int, amount: int, debt: int}>
     */
    public static function for(int $balance, int $price): array
    {
        if ($price <= 0) {
            return [];
        }

        $debt = max(0, -$balance);
        $suggestions = [];
        foreach (self::TARGETS as $target) {
            $beers = self::beersFor($target, $debt, $price);
            $suggestions[$beers] = ['beers' => $beers, 'amount' => $debt + $beers * $price, 'debt' => $debt];
        }

        return array_values($suggestions);
    }

    /** Index of the proposal used as default amount (the one around CHF 20). */
    public static function defaultIndex(array $suggestions): int
    {
        return min(1, max(0, count($suggestions) - 1));
    }

    /** Smallest beer count worth at least $target whose total with the debt is whole francs, else plain. */
    private static function beersFor(int $target, int $debt, int $price): int
    {
        $min = max(1, intdiv($target + $price - 1, $price));
        $period = intdiv(100, self::gcd($price, 100));
        for ($beers = $min; $beers < $min + $period; $beers++) {
            if (($debt + $beers * $price) % 100 === 0) {
                return $beers;
            }
        }

        return $min;
    }

    private static function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : self::gcd($b, $a % $b);
    }
}
