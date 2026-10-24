<?php

namespace App\Models;

use App\Support\LegalTexts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One published revision ("Fassung") of the privacy policy or terms of use: rendered plain text
 * (placeholders replaced) with the "Stand" in effect. Immutable: created by App\Services\LegalArchive,
 * never updated or deleted (no route, model events refuse it).
 *
 * @property int $id
 * @property string $kind
 * @property string $version
 * @property string $body
 * @property string $hash
 * @property string $reason
 * @property int|null $created_by
 */
class LegalRevision extends Model
{
    public const UPDATED_AT = null;

    /** Saved in the backend with this announcement mode. */
    public const REASON_REACCEPT = 'reaccept';

    public const REASON_UPDATE = 'update';

    public const REASON_SILENT = 'silent';

    /** Beer price, default limit or undo minutes changed (automatic notice). */
    public const REASON_SETTINGS = 'settings';

    /** Detected lazily (e.g. default text changed by a deploy). */
    public const REASON_AUTO = 'auto';

    /** First revision of a kind. */
    public const REASON_INITIAL = 'initial';

    public const REASONS = [self::REASON_REACCEPT, self::REASON_UPDATE, self::REASON_SILENT, self::REASON_SETTINGS, self::REASON_AUTO, self::REASON_INITIAL];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Legal revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Legal revisions are immutable.'));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Newest revision of a kind or null. */
    public static function latestOf(string $kind, bool $lock = false): ?self
    {
        $query = static::query()->where('kind', $kind)->orderByDesc('id');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function matches(string $hash, string $version): bool
    {
        return hash_equals($this->hash, $hash) && $this->version === $version;
    }

    /** Title (first line) and escaped HTML of the stored text. */
    public function rendered(): array
    {
        return LegalTexts::renderSource($this->body);
    }

    /** "24.10.2026 14:05" (local time of creation). */
    public function label(): string
    {
        return local_time($this->created_at, 'd.m.Y H:i');
    }
}
