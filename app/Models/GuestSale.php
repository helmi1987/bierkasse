<?php

namespace App\Models;

use App\Support\AppSettings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Beers sold to a guest (no account). Amount is the positive income in Rappen.
 * booked_by: user who booked it on the dashboard; null = public guest page or cashbox QR.
 * source: 'cashbox' = cashbox QR sticker, otherwise null.
 */
class GuestSale extends Model
{
    protected $fillable = ['name', 'beers', 'unit_price', 'amount', 'method', 'booked_by', 'source'];

    public const SOURCE_CASHBOX = 'cashbox';

    protected function casts(): array
    {
        return ['beers' => 'integer', 'unit_price' => 'integer', 'amount' => 'integer', 'booked_by' => 'integer'];
    }

    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    /** End of the undo window of a guest sale booked on the dashboard; null for public/cashbox sales. */
    public function undoExpiresAt(): ?CarbonInterface
    {
        return $this->booked_by !== null && $this->created_at !== null
            ? $this->created_at->copy()->addMinutes(AppSettings::undoMinutes('guest'))
            : null;
    }

    /** The booking user may undo the own guest sale within the consumption undo window. */
    public function canBeUndoneBy(User $user): bool
    {
        return $this->booked_by !== null
            && $this->booked_by === $user->id
            && $this->undoExpiresAt()?->isFuture() === true;
    }
}
