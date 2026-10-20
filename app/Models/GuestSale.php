<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Beers sold to a guest (no account). Amount is the positive income in Rappen.
 * booked_by: user who booked it on the dashboard; null = public guest page.
 */
class GuestSale extends Model
{
    protected $fillable = ['name', 'beers', 'unit_price', 'amount', 'method', 'booked_by'];

    protected function casts(): array
    {
        return ['beers' => 'integer', 'unit_price' => 'integer', 'amount' => 'integer', 'booked_by' => 'integer'];
    }

    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    /** The booking user may undo the own guest sale within the consumption undo window. */
    public function canBeUndoneBy(User $user): bool
    {
        return $this->booked_by !== null
            && $this->booked_by === $user->id
            && $this->created_at !== null
            && $this->created_at->gt(now()->subMinutes(Transaction::UNDO_MINUTES));
    }
}
