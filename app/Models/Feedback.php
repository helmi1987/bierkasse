<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * Feedback from a user (idea, bug report, general feedback).
 */
class Feedback extends Model
{
    public const TYPE_FEEDBACK = 'feedback';

    public const TYPE_IDEA = 'idea';

    public const TYPE_BUG = 'bug';

    public const TYPES = [self::TYPE_FEEDBACK, self::TYPE_IDEA, self::TYPE_BUG];

    public const MESSAGE_MIN = 5;

    public const MESSAGE_MAX = 5000;

    protected $table = 'feedback';

    protected $fillable = ['user_id', 'type', 'message', 'wants_reply', 'page', 'user_agent', 'read_at'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'wants_reply' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<Feedback>  $query */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    /** Unread feedback for the backend badge (0 when the table is not reachable yet). */
    public static function unreadCount(): int
    {
        try {
            return static::query()->unread()->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
