<?php

namespace App\Models;

use App\Services\Ledger;
use App\Support\AppSettings;
use App\Support\LegalTexts;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_KASSIER = 'kassier';

    public const ROLE_USER = 'user';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_KASSIER, self::ROLE_USER];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LEFT = 'left';

    /** Locked by a kassier/admin: no login, balance and bookings stay (kassier can still book deposits/corrections). */
    public const STATUS_LOCKED = 'locked';

    protected $fillable = [
        'username', 'first_name', 'last_name', 'email', 'password', 'role', 'status', 'registration_code_id',
        'overdraft_limit', 'stats_opt_out', 'must_change_password', 'last_login_at', 'left_at',
    ];

    protected $hidden = ['password', 'remember_token', 'totp_secret', 'totp_recovery_codes', 'staff_note'];

    protected $attributes = [
        'role' => self::ROLE_USER,
        'status' => self::STATUS_ACTIVE,
        'stats_opt_out' => false,
        'must_change_password' => false,
        'admin_only' => false,
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'overdraft_limit' => 'integer',
            'stats_opt_out' => 'boolean',
            'must_change_password' => 'boolean',
            'admin_only' => 'boolean',
            'nav_order' => 'array',
            'last_login_at' => 'datetime',
            'left_at' => 'datetime',
            'consent_accepted_at' => 'datetime',
            'privacy_notice_seen_at' => 'datetime',
            'terms_notice_seen_at' => 'datetime',
            'locked_at' => 'datetime',
            'totp_secret' => 'encrypted',
            'totp_confirmed_at' => 'datetime',
            'totp_last_step' => 'integer',
            'totp_recovery_codes' => 'encrypted:array',
            'staff_note' => 'encrypted',
        ];
    }

    /**
     * Personal SSO provider (at most one).
     */
    public function personalSsoProvider(): HasOne
    {
        return $this->hasOne(SsoProvider::class, 'owner_user_id');
    }

    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    /** The invitation this user registered with (null = password, backend or setup). */
    public function invitation(): HasOne
    {
        return $this->hasOne(Invitation::class, 'used_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }

    /** Kassier/admin who locked the account. */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'locked_by');
    }

    /** Accepted revision of the privacy policy (null for consents before 1.19.0). */
    public function privacyRevision(): BelongsTo
    {
        return $this->belongsTo(LegalRevision::class, 'privacy_revision_id');
    }

    /** Accepted revision of the terms of use (null for consents before 1.19.0). */
    public function termsRevision(): BelongsTo
    {
        return $this->belongsTo(LegalRevision::class, 'terms_revision_id');
    }

    /** Accepted versions differ from the current privacy policy / terms of use (consent page). */
    public function needsConsent(): bool
    {
        return ! LegalTexts::accepted($this);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /** Kassier rights (admins have them too). */
    public function isKassier(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_KASSIER], true);
    }

    /**
     * "Nur Verwaltung": backend account without a beer account (no balance, no beers, no rounds).
     * Only kassiers and admins can have it; never set by registration (not fillable).
     */
    public function isAdminOnly(): bool
    {
        return $this->admin_only && $this->isKassier();
    }

    /** Has a beer account (dashboard /konto, beers, deposits, rounds, leaving). */
    public function hasBeerAccount(): bool
    {
        return ! $this->isAdminOnly();
    }

    /**
     * Accounts with a beer account (excludes "Nur Verwaltung").
     *
     * @param  Builder<User>  $query
     */
    public function scopeHasBeerAccount(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('admin_only', false)->orWhere('role', self::ROLE_USER));
    }

    /** Start page after login and target of the logo: backend for "Nur Verwaltung", else the dashboard. */
    public function homeUrl(): string
    {
        return route($this->isAdminOnly() ? 'backend.dashboard' : 'account.dashboard');
    }

    public function displayName(): string
    {
        $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        return $name !== '' ? $name : (string) $this->username;
    }

    /** Effective overdraft limit in Rappen (individual or default). */
    public function effectiveLimit(): int
    {
        return $this->overdraft_limit ?? AppSettings::defaultLimit();
    }

    /** Confirmed balance (backend accounting). */
    public function balance(): int
    {
        return Ledger::balance($this);
    }

    /** Balance the user sees and may spend (incl. pending deposits/carry-overs unless on hold), see Ledger::availableBalance(). */
    public function availableBalance(): int
    {
        return Ledger::availableBalance($this);
    }

    public function hasTotp(): bool
    {
        return $this->totp_confirmed_at !== null && (string) $this->totp_secret !== '';
    }

    public function disableTotp(): void
    {
        $this->forceFill([
            'totp_secret' => null,
            'totp_confirmed_at' => null,
            'totp_last_step' => null,
            'totp_recovery_codes' => null,
        ])->save();
    }

    /** Password reset only for accounts with an e-mail address. */
    public function getEmailForPasswordReset(): string
    {
        return (string) $this->email;
    }
}
