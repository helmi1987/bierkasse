<?php

namespace App\Models;

use App\Support\AppSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** Debt (negative) or credit (positive) carried over from the old paper tally list at registration. */
    public const TYPE_CARRYOVER = 'carryover';

    /** A round paid by the user for colleagues and/or guests ("Runde spendieren"); beers in round_items. */
    public const TYPE_ROUND = 'round';

    /**
     * "Bier verschenken": the giver's row is negative (parent_id null, quantity = all beers), each recipient
     * gets a positive row (parent_id = giver's row, quantity = beers for this recipient). Credit in CHF.
     */
    public const TYPE_GIFT = 'gift';

    public const TYPES = [self::TYPE_CONSUMPTION, self::TYPE_DEPOSIT, self::TYPE_PAYOUT, self::TYPE_DONATION, self::TYPE_CORRECTION, self::TYPE_CARRYOVER, self::TYPE_ROUND, self::TYPE_GIFT];

    /** Types that are beers paid by the user at the stored unit price (stock, open items). */
    public const BEER_TYPES = [self::TYPE_CONSUMPTION, self::TYPE_ROUND];

    /** Limits of a carry-over entered at registration. */
    public const CARRYOVER_MAX_BEERS = 500;

    public const CARRYOVER_MAX_AMOUNT = 50000;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    public const METHODS = ['cash', 'twint'];

    /** Minutes a user can undo an own consumption or round. */
    public const UNDO_MINUTES = 10;

    protected $fillable = [
        'user_id', 'parent_id', 'type', 'status', 'method', 'quantity', 'unit_price', 'amount', 'note',
        'created_by', 'confirmed_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
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

    /** Beers of a round (type "round"). */
    public function roundItems(): HasMany
    {
        return $this->hasMany(RoundItem::class);
    }

    /** Recipient rows of a gift (giver's row). */
    public function giftParts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Giver's row of a gift (recipient row). */
    public function giftParent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function isGiftGiven(): bool
    {
        return $this->type === self::TYPE_GIFT && $this->parent_id === null;
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

    /**
     * Carry-over in the third person, e.g. "schuldet 12 Biere à CHF 1.50 (CHF 18.00)" or "hat Guthaben CHF 20.00".
     */
    public function carryoverText(): string
    {
        $key = $this->amount < 0 ? 'debt' : 'credit';
        $amount = chf(abs($this->amount));
        if ($this->quantity && $this->unit_price && $this->quantity * $this->unit_price === abs($this->amount)) {
            return trans_choice('ledger.carryover_beers.'.$key, $this->quantity, [
                'count' => $this->quantity, 'price' => chf($this->unit_price), 'amount' => $amount,
            ]);
        }

        return __('ledger.carryover_chf.'.$key, ['amount' => $amount]);
    }

    public function canBeUndoneBy(User $user): bool
    {
        if ($this->type === self::TYPE_GIFT) {
            return $this->isGiftGiven()
                && $this->user_id === $user->id
                && $this->created_at !== null
                && $this->created_at->gt(now()->subMinutes(AppSettings::giftUndoMinutes()));
        }

        return in_array($this->type, self::BEER_TYPES, true)
            && $this->user_id === $user->id
            && $this->created_by === $user->id
            && $this->created_at !== null
            && $this->created_at->gt(now()->subMinutes(self::UNDO_MINUTES));
    }
}
