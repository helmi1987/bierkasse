<?php

namespace App\Support;

use App\Models\User;
use InvalidArgumentException;

/**
 * Collapsible help texts in the user views (1.33.0): each text is a hidden block (<x-hint>) opened by an info
 * button at the end of its title or field label (<x-hint-toggle>, since 1.33.1), closed by default. The keys a user has opened are remembered in users.open_hints (display preference,
 * no audit entry). Guests never get an open hint.
 */
final class Hints
{
    /** Fixed list of all hint keys used in the views (a typo throws in the component). */
    public const KEYS = [
        'consume.undo',
        'round.intro',
        'round.extra',
        'round.total',
        'round.undo',
        'gift.intro',
        'gift.undo',
        'guest.intro',
        'guest.twint',
        'guest.undo',
        'disposal.intro',
        'deposit.intro',
        'deposit.pending',
        'deposit.twint_message',
        'settings.email',
        'settings.admin_only',
        'settings.nav',
        'settings.stats',
        'settings.disposal_stats',
        'settings.identities',
        'settings.personal_sso',
        'settings.personal_sso_discovery',
        'settings.personal_sso_secret',
        'settings.personal_sso_manual',
        'settings.feedback',
        'settings.leave',
        'stats.ranking',
        'stats.disposal_ranking',
        'stats.period_custom',
        'feedback.message',
        'feedback.reply',
        'invite.flyer',
        'invite.hash',
    ];

    public const MAX_KEY_LENGTH = 40;

    public static function assertKey(string $key): void
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new InvalidArgumentException("Unknown hint key [{$key}].");
        }
    }

    /** HTML id of the hint text ("round.intro" → "hint-round-intro"). */
    public static function domId(string $key): string
    {
        return 'hint-'.preg_replace('/[^a-z0-9_-]/i', '-', $key);
    }

    public static function isOpen(?User $user, string $key): bool
    {
        if ($user === null) {
            return false;
        }

        return in_array($key, (array) ($user->open_hints ?? []), true);
    }

    public static function set(User $user, string $key, bool $open): void
    {
        self::assertKey($key);

        // Only known keys survive (drops keys removed from KEYS since they were saved).
        $keys = array_values(array_intersect((array) ($user->open_hints ?? []), self::KEYS));
        $keys = $open ? [...$keys, $key] : array_diff($keys, [$key]);
        $keys = array_values(array_unique($keys));
        sort($keys);
        $keys = array_slice($keys, 0, count(self::KEYS));

        // Display preference only: saved without an audit entry.
        $user->forceFill(['open_hints' => $keys === [] ? null : $keys])->save();
    }
}
