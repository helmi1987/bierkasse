<?php

namespace App\Services;

use App\Models\GuestSale;
use App\Models\User;
use App\Support\AppSettings;
use Illuminate\Support\Facades\DB;

/**
 * Guest sales: booked immediately (no confirmation), at the current beer price.
 * Like user consumption, never limited by the stock (the stock may go negative).
 */
class GuestSales
{
    public const MAX_BEERS = 10;

    /**
     * @param  User|null  $by  user booking on the dashboard; null = public guest page / cashbox QR
     * @param  string|null  $source  GuestSale::SOURCE_CASHBOX for the cashbox QR sticker
     */
    public static function book(int $beers, string $method, ?string $name, ?User $by = null, ?string $source = null): GuestSale
    {
        if ($beers < 1 || $beers > self::MAX_BEERS) {
            throw new LedgerException(__('ledger.quantity_invalid'));
        }

        $price = AppSettings::beerPrice();
        $sale = DB::transaction(function () use ($beers, $method, $name, $by, $source, $price) {
            $sale = GuestSale::query()->create([
                'name' => $name !== null && trim($name) !== '' ? trim($name) : null,
                'beers' => $beers,
                'unit_price' => $price,
                'amount' => $beers * $price,
                'method' => $method,
                'booked_by' => $by?->id,
                'source' => $source,
            ]);
            Audit::log('guest_sale.create', $sale, [], self::auditValues($sale), $by, $by === null ? ($source === null ? 'Gast' : 'Gast (Kassen-QR)') : null);

            return $sale;
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
        DB::transaction(function () use ($sale, $user) {
            // Re-load with a row lock: a second undo finds the sale gone and writes no second audit entry.
            $sale = GuestSale::query()->lockForUpdate()->find($sale->id);
            if ($sale === null || ! $sale->canBeUndoneBy($user)) {
                throw new LedgerException(__('ledger.undo_expired'));
            }
            $sale->delete();
            Audit::log('guest_sale.undo', $sale, self::auditValues($sale), [], $user);
        });
        BeerStock::rearm();
    }

    /** Storno by kassier/admin. */
    public static function cancel(GuestSale $sale): void
    {
        DB::transaction(function () use ($sale) {
            $sale->delete();
            Audit::log('guest_sale.cancel', $sale, self::auditValues($sale));
        });
        BeerStock::rearm();
    }

    private static function auditValues(GuestSale $sale): array
    {
        return array_filter($sale->only(['name', 'beers', 'unit_price', 'amount', 'method', 'booked_by', 'source']), fn ($v) => $v !== null);
    }
}
