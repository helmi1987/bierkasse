<?php

namespace App\Services;

use App\Models\CashEntry;
use App\Models\Setting;
use App\Models\StockCorrection;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AppSettings;

/**
 * Beer stock = purchased beers (cash book) + inventory corrections - confirmed consumption.
 * Consumption is never blocked by the stock (it may become negative).
 *
 * Low-stock mail: once when a consumption takes the stock from >= minimum to below it.
 * A flag in the settings suppresses further mails until the stock is back >= minimum.
 */
class BeerStock
{
    public static function current(): int
    {
        $purchased = (int) CashEntry::query()->where('type', 'expense_beer')->sum('beers');
        $corrections = (int) StockCorrection::query()->sum('delta');
        $consumed = (int) Transaction::query()->confirmed()->where('type', Transaction::TYPE_CONSUMPTION)->sum('quantity');

        return $purchased + $corrections - $consumed;
    }

    /** Minimum stock; 0 = no warning. */
    public static function minimum(): int
    {
        return AppSettings::stockMinimum();
    }

    public static function isLow(?int $stock = null): bool
    {
        $min = self::minimum();

        return $min > 0 && ($stock ?? self::current()) < $min;
    }

    /**
     * Inventory: stores the difference between the counted and the computed stock.
     */
    public static function correct(int $counted, ?string $note, User $by): StockCorrection
    {
        $correction = StockCorrection::query()->create([
            'delta' => $counted - self::current(),
            'counted' => $counted,
            'note' => $note,
            'created_by' => $by->id,
        ]);
        self::rearm();

        return $correction;
    }

    /**
     * After a consumption of $quantity beers: mail once when crossing below the minimum.
     */
    public static function afterConsumption(int $quantity): void
    {
        $min = self::minimum();
        if ($min <= 0) {
            return;
        }

        $after = self::current();
        $before = $after + $quantity;
        if ($before >= $min && $after < $min && ! AppSettings::stockLowWarned()) {
            Setting::put(AppSettings::STOCK_LOW_WARNED, true);
            KassierNotifier::send(
                __('stock.mail_subject', ['stock' => $after]),
                [__('stock.mail_line', ['stock' => $after, 'min' => $min])],
            );
        }
    }

    /**
     * Allows the next mail once the stock is back at or above the minimum
     * (after purchases, corrections, undo, deleted entries, minimum changes).
     */
    public static function rearm(): void
    {
        if (AppSettings::stockLowWarned() && ! self::isLow()) {
            Setting::put(AppSettings::STOCK_LOW_WARNED, false);
        }
    }
}
