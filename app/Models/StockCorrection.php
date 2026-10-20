<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inventory correction of the beer stock: delta to the counted stock.
 */
class StockCorrection extends Model
{
    protected $fillable = ['delta', 'counted', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['delta' => 'integer', 'counted' => 'integer'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
