<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Beers of a round ("Runde spendieren", transaction type "round"): one row per named colleague
 * (quantity 1) and at most one row without user for the beers without a name.
 */
class RoundItem extends Model
{
    protected $fillable = ['transaction_id', 'user_id', 'quantity'];

    protected function casts(): array
    {
        return ['transaction_id' => 'integer', 'user_id' => 'integer', 'quantity' => 'integer'];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
