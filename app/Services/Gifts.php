<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * "Bier verschenken": a user gives beers as CHF credit (beers × current price) to one or more
 * colleagues, the same number each. The booking itself is Ledger::giveBeers (type "gift").
 * No beer is drunk: stock and cash stay unchanged.
 */
class Gifts
{
    public const MAX_PER_RECIPIENT = 10;

    public const MAX_RECIPIENTS = 30;

    public const MESSAGE_MAX = 140;

    /** Recipients see gifts of the last days on their dashboard. */
    public const NOTICE_DAYS = 7;

    public const NOTICE_MAX = 3;

    /**
     * Gifts the user received in the last NOTICE_DAYS days (recipient rows, newest first).
     *
     * @return Collection<int, Transaction>
     */
    public static function receivedRecently(User $user): Collection
    {
        return Transaction::query()->with('giftParent.user')->confirmed()->where('type', Transaction::TYPE_GIFT)
            ->where('user_id', $user->id)->whereNotNull('parent_id')
            ->where('created_at', '>', now()->subDays(self::NOTICE_DAYS))
            ->latest('id')->limit(self::NOTICE_MAX)->get();
    }

    /** Beers the user gave away (KPI "Verschenkt"). */
    public static function beersGiven(User $user): int
    {
        return (int) Transaction::query()->confirmed()->where('user_id', $user->id)
            ->where('type', Transaction::TYPE_GIFT)->whereNull('parent_id')->sum('quantity');
    }

    /** Beers the user got as a gift (KPI "Geschenkt bekommen"). */
    public static function beersReceived(User $user): int
    {
        return (int) Transaction::query()->confirmed()->where('user_id', $user->id)
            ->where('type', Transaction::TYPE_GIFT)->whereNotNull('parent_id')->sum('quantity');
    }

    /** A recipient booked a beer (own consumption or a round he paid) after the gift (giver's row). */
    public static function recipientsBookedSince(Transaction $gift): bool
    {
        return Transaction::query()->whereIn('user_id', $gift->giftParts()->select('user_id'))
            ->whereIn('type', Transaction::BEER_TYPES)->where('id', '>', $gift->id)->exists();
    }

    /** Recipients of a gift (giver's row), e.g. "Anna, Beat". */
    public static function recipientsText(Transaction $gift): string
    {
        return $gift->giftParts->map(fn (Transaction $t) => $t->user?->displayName() ?? '?')
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)->implode(', ');
    }
}
