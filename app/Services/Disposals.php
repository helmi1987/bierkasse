<?php

namespace App\Services;

use App\Http\Controllers\Backend\DisposalController;
use App\Models\Disposal;
use App\Models\GuestSale;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AppSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Entsorgungsbonus" (1.32.0): a user reports that the empties were taken out (or a kassier/admin reports it
 * for a user). After the withdrawal window (UNDO_MINUTES['disposal']) the bonus is drawn (fixed number or
 * random 1..max) and booked as a credit row of type "disposal" at the current beer price. Like a received
 * gift: never drunk, not in the stock, not in the consumption statistics.
 *
 * Rules for a new report (global, for all users): the first one after switching on (DISPOSAL_ENABLED_AT,
 * "restart") is always possible; afterwards the lock days since the last active disposal must have passed AND
 * at least the minimum of beers must have left the stock since then (confirmed beers and rounds plus guest
 * sales; stock corrections never count). Users only ever see whether they can report, never why not, and
 * nobody sees the number before it is booked.
 */
class Disposals
{
    /** Cache lock for reports and bookings (one at a time, no double booking). */
    public const LOCK = 'disposals';

    /** Booked bonuses of the last day are shown as a notice on the recipient's dashboard (until dismissed). */
    public const NOTICE_DAYS = 1;

    public const NOTICE_MAX = 3;

    public static function isEnabled(): bool
    {
        return AppSettings::disposalEnabled();
    }

    public static function enabledAt(): ?Carbon
    {
        return AppSettings::disposalEnabledAt();
    }

    /** Newest non-withdrawn disposal since the last switch-on; null when none (or never switched on). */
    public static function lastActive(): ?Disposal
    {
        $since = self::enabledAt();
        if ($since === null) {
            return null;
        }

        return Disposal::query()->active()->where('reported_at', '>=', $since)
            ->latest('reported_at')->latest('id')->first();
    }

    /**
     * Beers that left the stock after $since: confirmed beers and rounds plus guest sales (stock corrections never
     * count). $since null = no disposal since switching on: PHP_INT_MAX (the minimum is always reached).
     */
    public static function beersSince(?Carbon $since): int
    {
        if ($since === null) {
            return PHP_INT_MAX;
        }
        $beers = (int) Transaction::query()->confirmed()->whereIn('type', Transaction::BEER_TYPES)
            ->where('created_at', '>', $since)->sum('quantity');

        return $beers + (int) GuestSale::query()->where('created_at', '>', $since)->sum('beers');
    }

    /** End of the global lock after the last disposal; null without a disposal or without lock days. */
    public static function lockedUntil(?Disposal $last = null): ?Carbon
    {
        $last ??= self::lastActive();
        $days = AppSettings::disposalLockDays();

        return $last !== null && $days > 0 ? $last->reported_at->copy()->addDays($days) : null;
    }

    /**
     * Why no report is possible right now: null = allowed, else 'disabled' | 'locked' | 'minimum'
     * (internal keys, never shown to users).
     */
    public static function blockReason(): ?string
    {
        if (! self::isEnabled()) {
            return 'disabled';
        }
        $last = self::lastActive();
        if ($last === null) {
            return null;
        }
        $until = self::lockedUntil($last);
        if ($until !== null && $until->isFuture()) {
            return 'locked';
        }
        $min = AppSettings::disposalMinBeers();
        if ($min > 0 && self::beersSince($last->reported_at) < $min) {
            return 'minimum';
        }

        return null;
    }

    /** The user can report a disposal on the dashboard right now (not while a payout is requested). */
    public static function canReport(User $user): bool
    {
        return self::isEnabled()
            && $user->isActive() && $user->hasBeerAccount()
            && ! Ledger::hasPendingPayout($user)
            && self::ownPending($user) === null
            && self::blockReason() === null;
    }

    /** The user's own pending (not yet booked) disposal, reported by the user or for the user. */
    public static function ownPending(User $user): ?Disposal
    {
        return Disposal::query()->reported()->where('user_id', $user->id)->latest('id')->first();
    }

    /**
     * Reports a disposal for $recipient. $by is the recipient or a kassier/admin. While blocked only a
     * kassier/admin reporting for someone else can override it, with a reason. All checks run again here,
     * under the lock. The quantity is not drawn now (see bookDue()).
     */
    public static function report(User $recipient, User $by, bool $override = false, ?string $reason = null): Disposal
    {
        $reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null;

        $disposal = Cache::lock(self::LOCK, 10)->block(5, fn () => DB::transaction(function () use ($recipient, $by, $override, $reason) {
            $recipient = User::query()->whereKey($recipient->id)->lockForUpdate()->firstOrFail();
            $by = $by->id === $recipient->id ? $recipient : (User::query()->find($by->id) ?? $by);
            $forUser = $by->id !== $recipient->id;

            if (! self::isEnabled()) {
                throw new LedgerException(__('disposal.disabled'));
            }
            if ($forUser && (! $by->isKassier() || ! $by->isActive())) {
                throw new LedgerException(__('disposal.blocked'));
            }
            // A requested payout is final: no new credit on a leaving account.
            if (! $recipient->isActive() || ! $recipient->hasBeerAccount() || Ledger::hasPendingPayout($recipient)) {
                throw new LedgerException(__('disposal.recipient_invalid'));
            }
            if (self::ownPending($recipient) !== null) {
                throw new LedgerException(__('disposal.blocked'));
            }

            $overridden = false;
            if (self::blockReason() !== null) {
                if (! ($override && $forUser && $reason !== null)) {
                    throw new LedgerException(__('disposal.blocked'));
                }
                $overridden = true;
            }

            $disposal = Disposal::query()->create([
                'user_id' => $recipient->id,
                'reported_by' => $by->id,
                'reported_at' => now(),
                'status' => Disposal::STATUS_REPORTED,
                'override_reason' => $overridden ? $reason : null,
            ]);
            Audit::log('disposal.report', $disposal, [], array_filter([
                'user_id' => $recipient->id,
                'reported_by' => $by->id,
                'override_reason' => $disposal->override_reason,
            ], fn ($v) => $v !== null), $by);

            return $disposal;
        }));

        // Never the quantity: it is drawn only when booked.
        $lines = [__('mail.disposal_line', ['user' => $recipient->displayName()])];
        if ($disposal->reported_by !== $disposal->user_id) {
            $lines[] = __('mail.disposal_line_by', ['by' => $by->displayName()]);
        }
        KassierNotifier::send(
            __('mail.disposal_subject', ['user' => $recipient->displayName()]),
            $lines,
            [
                __('mail.row_user') => $recipient->displayName(),
                __('mail.disposal_row_booking') => local_time($disposal->bookingAt()),
                __('mail.disposal_row_override') => (string) $disposal->override_reason,
            ],
            url: DisposalController::url(),
            buttonLabel: __('mail.disposal_button'),
            title: __('mail.disposal_title'),
        );

        return $disposal;
    }

    /** Withdraws a pending disposal inside the window (see Disposal::isWithdrawable()). */
    public static function withdraw(Disposal $disposal, User $by): void
    {
        Cache::lock(self::LOCK, 10)->block(5, fn () => DB::transaction(function () use ($disposal, $by) {
            $locked = Disposal::query()->whereKey($disposal->id)->lockForUpdate()->first();
            if ($locked === null || ! $locked->isWithdrawable($by)) {
                throw new LedgerException(__('disposal.withdraw_expired'));
            }
            $locked->forceFill([
                'status' => Disposal::STATUS_WITHDRAWN,
                'withdrawn_by' => $by->id,
                'withdrawn_at' => now(),
            ])->save();
            Audit::log('disposal.withdraw', $locked, ['status' => Disposal::STATUS_REPORTED], [
                'status' => Disposal::STATUS_WITHDRAWN, 'user_id' => $locked->user_id,
            ], $by);
            $disposal->setRawAttributes($locked->getAttributes(), true);
        }));
    }

    /** Due = reported and the withdrawal window is over. */
    private static function dueQuery(): Builder
    {
        return Disposal::query()->reported()->where('reported_at', '<=', now()->subMinutes(AppSettings::undoMinutes('disposal')));
    }

    /** At least one disposal is due (cheap check for the page-view catch-up). */
    public static function hasDue(): bool
    {
        return self::dueQuery()->exists();
    }

    /**
     * Books all due disposals: draws the quantity now and creates the credit row. Idempotent and safe to run
     * concurrently (cache lock plus a status re-check under a row lock). Recipients that are no longer active or
     * have no beer account get nothing (disposal withdrawn by the system, audit "disposal.skip").
     * Returns the number of bonuses booked.
     */
    public static function bookDue(): int
    {
        return Cache::lock(self::LOCK, 30)->block(5, function () {
            $booked = 0;
            foreach (self::dueQuery()->orderBy('id')->pluck('id') as $id) {
                $booked += DB::transaction(function () use ($id) {
                    $disposal = Disposal::query()->whereKey($id)->lockForUpdate()->first();
                    if ($disposal === null || ! $disposal->isReported()) {
                        return 0;
                    }
                    $user = User::query()->whereKey($disposal->user_id)->lockForUpdate()->first();
                    if ($user === null || ! $user->isActive() || ! $user->hasBeerAccount()) {
                        $disposal->forceFill(['status' => Disposal::STATUS_WITHDRAWN, 'withdrawn_by' => null, 'withdrawn_at' => now()])->save();
                        Audit::log('disposal.skip', $disposal, ['status' => Disposal::STATUS_REPORTED], [
                            'status' => Disposal::STATUS_WITHDRAWN, 'user_id' => $disposal->user_id,
                        ], null, 'System');

                        return 0;
                    }

                    $quantity = AppSettings::disposalMode() === AppSettings::DISPOSAL_MODE_RANDOM
                        ? random_int(1, AppSettings::disposalRandomMax())
                        : AppSettings::disposalFixed();
                    $price = AppSettings::beerPrice();
                    $tx = Transaction::query()->create([
                        'user_id' => $user->id,
                        'type' => Transaction::TYPE_DISPOSAL,
                        'status' => Transaction::STATUS_CONFIRMED,
                        'quantity' => $quantity,
                        'unit_price' => $price,
                        'amount' => $quantity * $price,
                        'note' => null,
                        'created_by' => $disposal->reported_by,
                        'confirmed_by' => $disposal->reported_by,
                        'confirmed_at' => now(),
                    ]);
                    $disposal->forceFill([
                        'status' => Disposal::STATUS_BOOKED,
                        'quantity' => $quantity,
                        'transaction_id' => $tx->id,
                        'booked_at' => now(),
                    ])->save();
                    Audit::log('disposal.book', $disposal, [], [
                        'user_id' => $user->id, 'quantity' => $quantity, 'amount' => $tx->amount, 'transaction_id' => $tx->id,
                    ], null, 'System');

                    return 1;
                });
            }

            return $booked;
        });
    }

    /**
     * Booked disposals of the user and the sum of their bonus beers (statistics).
     *
     * @return array{count: int, beers: int}
     */
    public static function countFor(User $user): array
    {
        $row = Disposal::query()->booked()->where('user_id', $user->id)
            ->selectRaw('count(*) as c, coalesce(sum(quantity), 0) as b')->first();

        return ['count' => (int) ($row->c ?? 0), 'beers' => (int) ($row->b ?? 0)];
    }

    /**
     * Ranking of the booked disposals per recipient (all time, independent of any statistics period): sorted by
     * the number of disposals (not by beers, the bonus may be random), then beers, then user id; competition
     * ranks via Stats::ranked() ("1224", every row tied with place $top is kept). Only existing users with a
     * beer account. Users with disposal_stats_opt_out (never stats_opt_out) are listed as "Anonym n"
     * (Stats::numberAnonymous()); user_id stays for the own-row highlight (never rendered).
     *
     * @return list<array{rank:int, user_id:int, name:string, count:int, beers:int, anonymous:bool}>
     */
    public static function ranking(int $top = 10): array
    {
        $sums = Disposal::query()->booked()->groupBy('user_id')
            ->selectRaw('user_id, count(*) as c, coalesce(sum(quantity), 0) as b')->toBase()->get();
        $users = User::query()->whereIn('id', $sums->pluck('user_id'))->hasBeerAccount()->get()->keyBy('id');

        $rows = $sums->filter(fn ($row) => $users->has($row->user_id))
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'name' => $users[$row->user_id]->displayName(),
                'count' => (int) $row->c,
                'beers' => (int) $row->b,
                'anonymous' => (bool) $users[$row->user_id]->disposal_stats_opt_out,
            ])
            ->sort(fn (array $a, array $b) => [$b['count'], $b['beers'], $a['user_id']] <=> [$a['count'], $a['beers'], $b['user_id']])
            ->values();

        return Stats::numberAnonymous(Stats::ranked($rows, 'count', $top));
    }

    /**
     * All booked disposals and the sum of their bonus beers.
     *
     * @return array{count: int, beers: int}
     */
    public static function totals(): array
    {
        $row = Disposal::query()->booked()->selectRaw('count(*) as c, coalesce(sum(quantity), 0) as b')->toBase()->first();

        return ['count' => (int) ($row->c ?? 0), 'beers' => (int) ($row->b ?? 0)];
    }

    /**
     * Bonus rows of the user booked in the last NOTICE_DAYS days, not dismissed (newest first).
     *
     * @return Collection<int, Transaction>
     */
    public static function bookedRecently(User $user): Collection
    {
        return Transaction::query()->confirmed()->where('type', Transaction::TYPE_DISPOSAL)
            ->where('user_id', $user->id)
            ->where('created_at', '>', now()->subDays(self::NOTICE_DAYS))
            ->whereNotIn('id', NoticeDismissals::query($user))
            ->latest('id')->limit(self::NOTICE_MAX)->get();
    }
}
