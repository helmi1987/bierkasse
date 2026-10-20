<?php

namespace App\Services;

use App\Models\GuestSale;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Cache;

/**
 * Guest sales: booked immediately (no confirmation), at the current beer price.
 * Unlike user consumption, guests can never take more beers than the stock holds.
 */
class GuestSales
{
    public const MAX_BEERS = 10;

    public static function book(int $beers, string $method, ?string $name): GuestSale
    {
        if ($beers < 1 || $beers > self::MAX_BEERS) {
            throw new LedgerException(__('ledger.quantity_invalid'));
        }

        // Lock so two guests cannot both take the last beers.
        $sale = Cache::lock('guest-sales', 10)->block(5, function () use ($beers, $method, $name) {
            $available = max(0, BeerStock::current());
            if ($beers > $available) {
                throw new LedgerException(trans_choice('guest.stock_short', $available));
            }

            $price = AppSettings::beerPrice();

            return GuestSale::query()->create([
                'name' => $name !== null && trim($name) !== '' ? trim($name) : null,
                'beers' => $beers,
                'unit_price' => $price,
                'amount' => $beers * $price,
                'method' => $method,
            ]);
        });

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
