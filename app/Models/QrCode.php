<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Secret code behind a printed QR code (cashbox cash/TWINT sticker, registration flyer, guest page).
 * Several codes of a kind can be active; deactivated ones stay as history and can be reactivated.
 * Managed by admins, kassiers only see the active ones.
 */
class QrCode extends Model
{
    public const KIND_CASH = 'cashbox_cash';

    public const KIND_TWINT = 'cashbox_twint';

    public const KIND_FLYER = 'flyer';

    public const KIND_GUEST = 'guest';

    public const KINDS = [self::KIND_CASH, self::KIND_TWINT, self::KIND_FLYER, self::KIND_GUEST];

    /** Query parameter of the guest page code. */
    public const GUEST_QUERY = 'zugang';

    public const CODE_LENGTH = 32;

    protected $fillable = ['kind', 'label', 'code_hash', 'code', 'created_by', 'deactivated_at'];

    protected $hidden = ['code', 'code_hash'];

    protected function casts(): array
    {
        return [
            'code' => 'encrypted',
            'created_by' => 'integer',
            'deactivated_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deactivated_at');
    }

    public static function generate(string $kind, ?string $label = null, ?User $creator = null): self
    {
        $code = Str::random(self::CODE_LENGTH);

        return self::query()->create([
            'kind' => $kind,
            'label' => $label !== null && trim($label) !== '' ? trim($label) : null,
            'code_hash' => hash('sha256', $code),
            'code' => $code,
            'created_by' => $creator?->id,
        ]);
    }

    /** First code of a kind, created automatically only when the kind never had one. */
    public static function ensureFirst(string $kind): void
    {
        if (! self::query()->where('kind', $kind)->exists()) {
            self::generate($kind);
        }
    }

    /**
     * Active code of one of the kinds by its plain value; null when unknown or deactivated.
     *
     * @param  string|list<string>  $kinds
     */
    public static function findActive(string|array $kinds, string $code): ?self
    {
        if ($code === '') {
            return null;
        }

        return self::query()->active()->whereIn('kind', (array) $kinds)->where('code_hash', hash('sha256', $code))->first();
    }

    /** Newest active code of a kind. */
    public static function newestActive(string $kind): ?self
    {
        return self::query()->active()->where('kind', $kind)->latest('id')->first();
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /** Payment method of a cashbox code ('cash'|'twint'), null for other kinds. */
    public function method(): ?string
    {
        return match ($this->kind) {
            self::KIND_CASH => 'cash',
            self::KIND_TWINT => 'twint',
            default => null,
        };
    }

    /** URL in the QR code. */
    public function url(): string
    {
        return match ($this->kind) {
            self::KIND_CASH, self::KIND_TWINT => route('cashbox', $this->code),
            self::KIND_FLYER => route('register', ['flyer' => $this->code]),
            default => route('guest', [self::GUEST_QUERY => $this->code]),
        };
    }

    /** Name shown in the backend: label or "#id". */
    public function displayName(): string
    {
        return $this->label ?? '#'.$this->id;
    }
}
