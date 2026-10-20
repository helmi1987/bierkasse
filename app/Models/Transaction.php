<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of a user's ledger. The balance is the sum of the confirmed amounts.
 */
class Transaction extends Model
{
    public const TYPE_CONSUMPTION = 'consumption';

    public const TYPE_DEPOSIT = 'deposit';

    public const TYPE_PAYOUT = 'payout';

    public const TYPE_DONATION = 'donation';

    public const TYPE_CORRECTION = 'correction';

    public const TYPES = [self::TYPE_CONSUMPTION, self::TYPE_DEPOSIT, self::TYPE_PAYOUT, self::TYPE_DONATION, self::TYPE_CORRECTION];

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    public const METHODS = ['cash', 'twint'];

    /** Minutes a user can undo an own consumption. */
    public const UNDO_MINUTES = 10;

    protected $fillable = [
        'user_id', 'type', 'status', 'method', 'quantity', 'unit_price', 'amount', 'note',
        'created_by', 'confirmed_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'amount' => 'integer',
            'confirmed_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function canBeUndoneBy(User $user): bool
    {
        return $this->type === self::TYPE_CONSUMPTION
            && $this->user_id === $user->id
            && $this->created_by === $user->id
            && $this->created_at !== null
            && $this->created_at->gt(now()->subMinutes(self::UNDO_MINUTES));
    }
}
