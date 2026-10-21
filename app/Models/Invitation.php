<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One-time, time-limited invitation link (/registrieren?einladung=<token>).
 * Only the SHA-256 hash of the token is stored; the plain token is shown once to its creator.
 */
class Invitation extends Model
{
    /** Validity of a new invitation. */
    public const VALID_DAYS = 7;

    /** Maximum open (unused, unexpired) invitations per user. */
    public const MAX_OPEN_PER_USER = 10;

    /** Length of the plain token (alphanumeric, URL safe, about 285 bits). */
    public const TOKEN_LENGTH = 48;

    public const QUERY = 'einladung';

    public const STATUS_OPEN = 'open';

    public const STATUS_USED = 'used';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = ['token_hash', 'created_by', 'expires_at', 'used_at', 'used_by'];

    protected function casts(): array
    {
        return [
            'created_by' => 'integer',
            'used_by' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Creates an invitation for the user and returns it together with the plain token.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(User $creator): array
    {
        $token = Str::random(self::TOKEN_LENGTH);
        $invitation = self::query()->create([
            'token_hash' => self::hashToken($token),
            'created_by' => $creator->id,
            'expires_at' => now()->addDays(self::VALID_DAYS),
        ]);

        return [$invitation, $token];
    }

    public static function url(string $token): string
    {
        return route('register', [self::QUERY => $token]);
    }

    /** Unused and not expired. */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    /**
     * The open invitation for a plain token whose creator is still an active user; null otherwise.
     */
    public static function findValid(string $token): ?self
    {
        if ($token === '' || strlen($token) > 255) {
            return null;
        }

        $invitation = self::query()->open()->with('creator')
            ->where('token_hash', self::hashToken($token))->first();

        return $invitation && $invitation->creator?->isActive() ? $invitation : null;
    }

    /**
     * Marks the invitation as used by the new user. Atomic: only one registration can win,
     * a second (parallel) one gets false.
     */
    public function consume(User $user): bool
    {
        $updated = self::query()->whereKey($this->id)->open()
            ->update(['used_at' => now(), 'used_by' => $user->id, 'updated_at' => now()]);
        if ($updated !== 1) {
            return false;
        }
        $this->refresh();

        return true;
    }

    public function status(): string
    {
        if ($this->used_at !== null) {
            return self::STATUS_USED;
        }

        return $this->expires_at->isFuture() ? self::STATUS_OPEN : self::STATUS_EXPIRED;
    }

    public static function openCountFor(User $user): int
    {
        return self::query()->open()->where('created_by', $user->id)->count();
    }
}
