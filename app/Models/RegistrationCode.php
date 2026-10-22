<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Multi-use registration code for QR codes and flyers (/registrieren?zugang=<code>).
 * Valid in every registration mode until it is locked or deleted; accounts are active at once.
 */
class RegistrationCode extends Model
{
    public const QUERY = 'zugang';

    /** Length of the code (alphanumeric, URL safe). */
    public const CODE_LENGTH = 32;

    public const MAX = 50;

    protected $fillable = ['label', 'code_hash', 'code', 'created_by', 'locked_at'];

    protected $hidden = ['code', 'code_hash'];

    protected function casts(): array
    {
        return [
            'code' => 'encrypted',
            'created_by' => 'integer',
            'locked_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public static function hashCode(string $code): string
    {
        return hash('sha256', $code);
    }

    public static function generate(string $label, ?User $creator): self
    {
        $code = Str::random(self::CODE_LENGTH);

        return self::query()->create([
            'label' => $label,
            'code_hash' => self::hashCode($code),
            'code' => $code,
            'created_by' => $creator?->id,
        ]);
    }

    /** Code by its plain value (locked ones included); null when unknown or deleted. */
    public static function findByCode(string $code): ?self
    {
        return $code === '' ? null : self::query()->where('code_hash', self::hashCode($code))->first();
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function url(): string
    {
        return route('register', [self::QUERY => $this->code]);
    }
}
