<?php

namespace App\Services;

use App\Models\GuestSale;
use App\Models\User;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Cache;

/**
 * Guest sales: booked immediately (no confirmation), at the current beer price.
 * Unlike user consumption, guests can never take more beers than the stock holds.
 */
class GuestSales
{
    public const MAX_BEERS = 10;

    /**
     * @param  User|null  $by  user booking on the dashboard; null = public guest page
     */
    public static function book(int $beers, string $method, ?string $name, ?User $by = null): GuestSale
    {
        if ($beers < 1 || $beers > self::MAX_BEERS) {
            throw new LedgerException(__('ledger.quantity_invalid'));
        }

        // Lock so two guests cannot both take the last beers.
        $sale = Cache::lock(BeerStock::LOCK, 10)->block(5, function () use ($beers, $method, $name, $by) {
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
                'booked_by' => $by?->id,
            ]);
        });

        BeerStock::afterConsumption($beers);

        return $sale;
    }

    /** Undo by the booking user within the undo window. */
    public static function undo(GuestSale $sale, User $user): void
    {
        if (! $sale->canBeUndoneBy($user)) {
            throw new LedgerException(__('ledger.undo_expired'));
        }
        self::cancel($sale);
    }

    /** Storno by kassier/admin. */
    public static function cancel(GuestSale $sale): void
    {
        $sale->delete();
        BeerStock::rearm();
    }
}
