<?php

namespace App\Support;

/**
 * Deposit proposals in whole beers. The amounts are configured in the backend
 * (setting deposit_suggestions, CHF in Rappen) or computed automatically:
 * the smallest multiple of the beer price that is divisible by CHF 10, then
 * 2x, 3x and 5x of it (CHF 30/60/90/150 at CHF 1.50). Each proposal covers an
 * open minus first (total = debt + amount).
 */
class DepositSuggestions
{
    /** Factors of the automatic proposals. */
    public const FACTORS = [1, 2, 3, 5];

    /** Automatic base is a multiple of this (CHF 10). */
    public const STEP = 1000;

    public const MAX = 6;

    /**
     * @param  list<int>|null  $configured  Amounts from the settings; null/empty = automatic.
     * @return list<array{beers: int, amount: int, debt: int}>
     */
    public static function for(int $balance, int $price, ?array $configured = null): array
    {
        if ($price <= 0) {
            return [];
        }

        $debt = max(0, -$balance);
        $suggestions = [];
        foreach (self::amounts($price, $configured) as $amount) {
            $beers = self::beers($amount, $price);
            $suggestions[$beers] = ['beers' => $beers, 'amount' => $debt + $beers * $price, 'debt' => $debt];
        }

        return array_values($suggestions);
    }

    /**
     * Effective amounts (without debt): configured ones rounded up to whole beers, or the automatic ones.
     *
     * @param  list<int>|null  $configured
     * @return list<int>
     */
    public static function amounts(int $price, ?array $configured = null): array
    {
        if ($price <= 0) {
            return [];
        }

        $configured = array_values(array_filter(array_map('intval', (array) $configured), fn (int $a) => $a > 0));
        if ($configured === []) {
            return self::defaults($price);
        }

        // A later price change never fails: round up to the next whole beer count.
        return array_values(array_unique(array_map(fn (int $a) => self::beers($a, $price) * $price, $configured)));
    }

    /**
     * @return list<int>
     */
    public static function defaults(int $price): array
    {
        if ($price <= 0) {
            return [];
        }
        $base = intdiv($price * self::STEP, self::gcd($price, self::STEP));

        return array_map(fn (int $f) => $base * $f, self::FACTORS);
    }

    /**
     * Index of the preselected proposal: the one with as many beers as the configured default amount,
     * else the first.
     */
    public static function defaultIndex(array $suggestions, ?int $default = null, int $price = 0): int
    {
        if ($default === null || $price <= 0) {
            return 0;
        }
        $beers = self::beers($default, $price);
        foreach ($suggestions as $i => $suggestion) {
            if ($suggestion['beers'] === $beers) {
                return $i;
            }
        }

        return 0;
    }

    /** Whole beers needed for an amount (rounded up). */
    public static function beers(int $amount, int $price): int
    {
        return max(1, intdiv($amount + $price - 1, $price));
    }

    /**
     * Valid amounts around an invalid one (multiples of the price below and above).
     *
     * @return list<int>
     */
    public static function nearest(int $amount, int $price): array
    {
        $below = intdiv($amount, $price) * $price;

        return array_values(array_filter([$below, $below + $price], fn (int $a) => $a > 0));
    }

    private static function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : self::gcd($b, $a % $b);
    }
}
