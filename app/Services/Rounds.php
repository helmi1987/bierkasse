<?php

namespace App\Services;

use App\Models\RoundItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * "Runde spendieren": a user pays beers for colleagues (one each) and beers without a name
 * (e.g. guests). The booking itself is Ledger::giveRound (transaction type "round").
 * Statistics count the beers at the drinker (see Stats).
 */
class Rounds
{
    /** Beers per round in total. */
    public const MAX_BEERS = 30;

    /** Beers without a name per round. */
    public const MAX_EXTRA = 20;

    public const MESSAGE_MAX = 140;

    /** Recipients see rounds of the last day on their dashboard (until dismissed). */
    public const NOTICE_DAYS = 1;

    public const NOTICE_MAX = 3;

    /**
     * Active colleagues who can get a beer from $giver, most recent beer drinkers first, then by display name.
     *
     * @return Collection<int, User>
     */
    public static function colleagues(User $giver): Collection
    {
        // Most recent beer drinkers first (own consumption), then the others alphabetically.
        // "Nur Verwaltung" accounts have no beer account and get no beers.
        $users = User::query()->where('status', User::STATUS_ACTIVE)->hasBeerAccount()->whereKeyNot($giver->id)
            ->addSelect(['last_beer_at' => Transaction::query()->select('created_at')
                ->whereColumn('transactions.user_id', 'users.id')
                ->where('type', Transaction::TYPE_CONSUMPTION)->confirmed()
                ->latest('created_at')->limit(1)])
            ->get();

        return $users->sort(function (User $a, User $b) {
            if ($a->last_beer_at !== $b->last_beer_at) {
                return strcmp((string) $b->last_beer_at, (string) $a->last_beer_at);
            }

            return strnatcasecmp($a->displayName(), $b->displayName());
        })->values();
    }

    /**
     * Rounds the user got a beer from in the last NOTICE_DAYS days, not dismissed (newest first).
     *
     * @return Collection<int, Transaction>
     */
    public static function receivedRecently(User $user): Collection
    {
        return Transaction::query()->with('user')->confirmed()->where('type', Transaction::TYPE_ROUND)
            ->where('created_at', '>', now()->subDays(self::NOTICE_DAYS))
            ->whereNotIn('id', NoticeDismissals::query($user))
            ->where('user_id', '!=', $user->id) // own beer in an own round ("inkl. mir")
            ->whereHas('roundItems', fn ($q) => $q->where('user_id', $user->id))
            ->latest('id')->limit(self::NOTICE_MAX)->get();
    }

    /** Beers the user paid for others in rounds (KPI "spendiert"; the own beer of "inkl. mir" not counted). */
    public static function beersGiven(User $user): int
    {
        return (int) Transaction::query()->confirmed()->where('user_id', $user->id)
            ->where('type', Transaction::TYPE_ROUND)->sum('quantity') - self::ownBeers($user);
    }

    /** Own beers of the user in own rounds ("Runde inkl. mir"). */
    public static function ownBeers(User $user): int
    {
        return (int) RoundItem::query()->where('user_id', $user->id)
            ->whereHas('round', fn ($q) => $q->confirmed()->where('user_id', $user->id))->sum('quantity');
    }

    /**
     * Recipients of a round for the ledger row, e.g. "Anna, Beat + 2 Gäste".
     */
    public static function recipientsText(Transaction $round): string
    {
        $items = $round->roundItems;
        $names = $items->whereNotNull('user_id')
            ->map(fn (RoundItem $i) => $i->user?->displayName() ?? '?')
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
        $extra = (int) $items->whereNull('user_id')->sum('quantity');
        $text = implode(', ', $names);
        if ($extra > 0) {
            $guests = trans_choice('round.guests_count', $extra, ['count' => $extra]);
            $text = $text === '' ? $guests : $text.' + '.$guests;
        }

        return $text;
    }
}
