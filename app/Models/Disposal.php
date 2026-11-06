<?php

namespace App\Models;

use App\Support\AppSettings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Entsorgungsbonus": a report that the empties were taken out (see App\Services\Disposals).
 * The quantity is drawn and booked only after the withdrawal window; nobody sees it before.
 */
class Disposal extends Model
{
    public const STATUS_REPORTED = 'reported';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_BOOKED = 'booked';

    public const STATUSES = [self::STATUS_REPORTED, self::STATUS_WITHDRAWN, self::STATUS_BOOKED];

    protected $fillable = [
        'user_id', 'reported_by', 'reported_at', 'status', 'withdrawn_by', 'withdrawn_at', 'booked_at',
        'quantity', 'transaction_id', 'override_reason',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'reported_by' => 'integer',
            'withdrawn_by' => 'integer',
            'transaction_id' => 'integer',
            'quantity' => 'integer',
            'reported_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'booked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function withdrawer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** Not withdrawn (reported or booked): counts for the lock and the minimum. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_WITHDRAWN);
    }

    public function scopeReported(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REPORTED);
    }

    public function scopeBooked(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_BOOKED);
    }

    public function isReported(): bool
    {
        return $this->status === self::STATUS_REPORTED;
    }

    public function isBooked(): bool
    {
        return $this->status === self::STATUS_BOOKED;
    }

    /** Reported by a kassier/admin for someone else (the recipient cannot withdraw it). */
    public function isReportedForUser(): bool
    {
        return $this->reported_by !== $this->user_id;
    }

    /** End of the withdrawal window = time of the booking and the draw. */
    public function bookingAt(): CarbonInterface
    {
        return $this->reported_at->copy()->addMinutes(AppSettings::undoMinutes('disposal'));
    }

    public function inWithdrawalWindow(): bool
    {
        return $this->isReported() && $this->bookingAt()->isFuture();
    }

    /**
     * $by may withdraw: while reported and inside the window; the recipient only an own report, any other
     * kassier/admin every report. A report made by a kassier/admin for the user cannot be withdrawn by the
     * user (not even when the recipient is a kassier too).
     */
    public function isWithdrawable(User $by): bool
    {
        if (! $this->inWithdrawalWindow()) {
            return false;
        }
        if ($by->id === $this->user_id) {
            return $this->reported_by === $this->user_id;
        }

        return $by->isKassier() && $by->isActive();
    }
}
