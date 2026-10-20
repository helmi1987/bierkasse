<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash book entry outside the user ledger: beer purchases, other expenses,
 * other income and adjustments. Amount is the signed effect on the cash box.
 */
class CashEntry extends Model
{
    public const TYPES = ['expense_beer', 'expense_other', 'income', 'adjustment'];

    protected $fillable = ['type', 'date', 'amount', 'beers', 'method', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'integer', 'beers' => 'integer'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
