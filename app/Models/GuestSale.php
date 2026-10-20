<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Beers sold to a guest (no account). Amount is the positive income in Rappen.
 */
class GuestSale extends Model
{
    protected $fillable = ['name', 'beers', 'unit_price', 'amount', 'method'];

    protected function casts(): array
    {
        return ['beers' => 'integer', 'unit_price' => 'integer', 'amount' => 'integer'];
    }
}
