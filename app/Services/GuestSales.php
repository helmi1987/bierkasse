<?php

namespace App\Services;

use App\Models\GuestSale;
use App\Support\AppSettings;

/**
 * Guest sales: booked immediately (no confirmation), at the current beer price.
 */
class GuestSales
{
    public const MAX_BEERS = 10;

    public static function book(int $beers, string $method, ?string $name): GuestSale
    {
        if ($beers < 1 || $beers > self::MAX_BEERS) {
            throw new LedgerException(__('ledger.quantity_invalid'));
        }

        $price = AppSettings::beerPrice();
        $sale = GuestSale::query()->create([
            'name' => $name !== null && trim($name) !== '' ? trim($name) : null,
            'beers' => $beers,
            'unit_price' => $price,
            'amount' => $beers * $price,
            'method' => $method,
        ]);

        BeerStock::afterConsumption($beers);

        return $sale;
    }

    /** Storno by kassier/admin. */
    public static function cancel(GuestSale $sale): void
    {
        $sale->delete();
        BeerStock::rearm();
    }
}
