<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Audit log entry. Append-only: written by App\Services\Audit, never updated or deleted.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        // Written only by App\Services\Audit (query builder, with the hash chain).
        static::creating(fn () => throw new LogicException('Audit log entries are written by App\Services\Audit only.'));
        static::updating(fn () => throw new LogicException('Audit log entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted.'));
    }

    /** Mass updates/deletes through Eloquent are refused as well. */
    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            public function update(array $values)
            {
                throw new LogicException('Audit log entries cannot be changed.');
            }

            public function delete()
            {
                throw new LogicException('Audit log entries cannot be deleted.');
            }

            public function forceDelete()
            {
                throw new LogicException('Audit log entries cannot be deleted.');
            }
        };
    }

    /** Time of the entry (stored as UTC text, part of the hash). */
    public function at(): Carbon
    {
        return Carbon::parse((string) $this->created_at, 'UTC');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return array<string, mixed> */
    public function oldValues(): array
    {
        return json_decode((string) $this->old_values, true) ?: [];
    }

    /** @return array<string, mixed> */
    public function newValues(): array
    {
        return json_decode((string) $this->new_values, true) ?: [];
    }
}
