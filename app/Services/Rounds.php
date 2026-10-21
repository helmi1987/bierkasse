<?php

namespace App\Services;

use App\Mail\KassierNotice;
use App\Models\RoundItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

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

    /** Recipients see rounds of the last days on their dashboard. */
    public const NOTICE_DAYS = 7;

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
     * Rounds the user got a beer from in the last NOTICE_DAYS days (newest first).
     *
     * @return Collection<int, Transaction>
     */
    public static function receivedRecently(User $user): Collection
    {
        return Transaction::query()->with('user')->confirmed()->where('type', Transaction::TYPE_ROUND)
            ->where('created_at', '>', now()->subDays(self::NOTICE_DAYS))
            ->whereHas('roundItems', fn ($q) => $q->where('user_id', $user->id))
            ->latest('id')->limit(self::NOTICE_MAX)->get();
    }

    /** Beers the user paid for others in rounds (KPI "spendiert"). */
    public static function beersGiven(User $user): int
    {
        return (int) Transaction::query()->confirmed()->where('user_id', $user->id)
            ->where('type', Transaction::TYPE_ROUND)->sum('quantity');
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

    /**
     * Mail to the named recipients with an e-mail address and the mail switch on.
     * Failures are logged and never break the booking.
     */
    public static function notifyRecipients(Transaction $round, User $giver): int
    {
        try {
            $recipients = User::query()
                ->whereIn('id', $round->roundItems()->whereNotNull('user_id')->pluck('user_id'))
                ->where('status', User::STATUS_ACTIVE)->where('round_mail', true)
                ->whereNotNull('email')->where('email', '!=', '')
                ->get();
            if ($recipients->isEmpty()) {
                return 0;
            }
            MailSettings::apply();
        } catch (Throwable $e) {
            Log::warning('Round notification failed', ['round' => $round->id, 'error' => $e->getMessage()]);

            return 0;
        }

        $name = $giver->displayName();
        $sent = 0;
        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient->email)->send(new KassierNotice(
                    __('round.mail_subject', ['giver' => $name]),
                    [__('round.mail_line', ['giver' => $name])],
                    url: route('account.dashboard'),
                    buttonLabel: __('round.mail_button'),
                    rows: [
                        __('round.mail_row_giver') => $name,
                        __('round.mail_row_message') => (string) $round->note,
                        __('round.mail_row_beers') => trans_choice('round.beers', (int) $round->quantity, ['count' => $round->quantity]),
                        __('round.mail_row_time') => local_time($round->created_at ?? now()),
                    ],
                    title: __('round.mail_title', ['giver' => $name]),
                    outro: [__('round.mail_hint')],
                ));
                $sent++;
            } catch (Throwable $e) {
                Log::warning('Round notification failed', ['round' => $round->id, 'to' => $recipient->id, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }
}
