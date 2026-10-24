<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AppSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * All bookings on the users' ledgers. Balance = sum of the confirmed amounts (backend accounting).
 * Available balance = balance plus pending deposits and carry-overs (what the user sees and may spend).
 */
class Ledger
{
    public const MAX_QUANTITY = 10;

    public static function balance(User $user): int
    {
        return (int) Transaction::query()->where('user_id', $user->id)->confirmed()->sum('amount');
    }

    /**
     * Balance shown to the user and used for the limit checks (decision 2026-10-23): reported deposits
     * and carry-overs count before the kassier confirms them, so beers can be booked while the kassier
     * is absent. A rejected or withdrawn deposit may leave the user below the limit (settled in person).
     * Pending payouts are not included (they block bookings instead).
     *
     * Decision 2026-10-24: while depositsOnHold() is true (rejected deposit and confirmed balance below 0),
     * pending deposits do not count until the kassier confirms them; pending carry-overs still count.
     */
    public static function availableBalance(User $user): int
    {
        return (int) self::availableRows($user)->sum('amount');
    }

    /**
     * Provisional deposits on hold (decision 2026-10-24): the user has a rejected deposit with no deposit
     * confirmed (or booked by a kassier) after it, and the confirmed balance is below 0. Released
     * automatically by a later confirmed deposit or once the confirmed balance is >= 0.
     */
    public static function depositsOnHold(User $user): bool
    {
        if (self::balance($user) >= 0) {
            return false;
        }

        // Time of the decision: confirmed_at (set by rejectDeposit); legacy rows without it fall back to updated_at.
        $decidedAt = DB::raw('COALESCE(confirmed_at, updated_at)');
        $rejected = Transaction::query()->where('user_id', $user->id)
            ->where('type', Transaction::TYPE_DEPOSIT)->where('status', Transaction::STATUS_REJECTED)
            ->select('id')->selectRaw('COALESCE(confirmed_at, updated_at) as decided_at')->get();
        if ($rejected->isEmpty()) {
            return false;
        }
        $rejectedAt = $rejected->max('decided_at');
        $deposits = Transaction::query()->where('user_id', $user->id)
            ->where('type', Transaction::TYPE_DEPOSIT)->confirmed();
        if ((clone $deposits)->where($decidedAt, '>', $rejectedAt)->exists()) {
            return false;
        }

        // Same second (timestamps have second precision): the transaction id says nothing about the order of
        // the decisions (an older report can be confirmed later), so compare the audit entries instead. They are
        // written in the same DB transaction as the status change and appended under the audit chain lock, so
        // their ids follow the commit order. Without audit entries the hold stays (conservative).
        $tied = (clone $deposits)->where($decidedAt, '=', $rejectedAt)->pluck('id')->all();
        if ($tied === []) {
            return true;
        }
        $lastAudit = fn (array $actions, array $ids) => AuditLog::query()->where('subject_type', class_basename(Transaction::class))
            ->whereIn('subject_id', $ids)->whereIn('action', $actions)->max('id');
        $rejectAudit = $lastAudit(['deposit.reject'], $rejected->where('decided_at', $rejectedAt)->pluck('id')->all());
        $confirmAudit = $lastAudit(['deposit.confirm', 'deposit.book'], $tied);

        return ! ($confirmAudit !== null && $rejectAudit !== null && $confirmAudit > $rejectAudit);
    }

    /** Rows that make up availableBalance() (pending deposits left out while on hold). */
    private static function availableRows(User $user): Builder
    {
        return Transaction::query()->where('user_id', $user->id)->available(self::depositsOnHold($user));
    }

    /** "Limit reached" error; while deposits are on hold it says that a reported deposit does not count yet. */
    private static function limitReached(User $user): LedgerException
    {
        return new LedgerException(__(self::depositsOnHold($user) ? 'ledger.limit_reached_on_hold' : 'ledger.limit_reached'));
    }

    /**
     * @return array<int, int> user id => balance
     */
    public static function balances(): array
    {
        return Transaction::query()->confirmed()
            ->selectRaw('user_id, SUM(amount) as balance')->groupBy('user_id')
            ->pluck('balance', 'user_id')->map(fn ($v) => (int) $v)->all();
    }

    /** Sum of all user balances (money the cash box owes the users, negative = debts). */
    public static function totalCredit(): int
    {
        return (int) Transaction::query()->confirmed()->sum('amount');
    }

    public static function pendingCount(): int
    {
        return Transaction::query()->pending()
            ->whereIn('type', [Transaction::TYPE_DEPOSIT, Transaction::TYPE_PAYOUT, Transaction::TYPE_CARRYOVER])->count();
    }

    public static function hasPendingPayout(User $user): bool
    {
        return Transaction::query()->where('user_id', $user->id)
            ->where('type', Transaction::TYPE_PAYOUT)->pending()->exists();
    }

    /**
     * Books beers at the current price. The price is stored on the row, later price
     * changes never alter it.
     */
    public static function consume(User $user, int $quantity): Transaction
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new LedgerException(__('ledger.quantity_invalid'));
        }

        $tx = DB::transaction(function () use ($user, $quantity) {
            self::lock($user);
            self::assertActive($user);
            self::assertBeerAccount($user);
            if (self::hasPendingPayout($user)) {
                throw new LedgerException(__('ledger.payout_pending'));
            }

            $price = AppSettings::beerPrice();
            $amount = -$quantity * $price;
            if (self::availableBalance($user) + $amount < -$user->effectiveLimit()) {
                throw self::limitReached($user);
            }

            $tx = Transaction::query()->create([
                'user_id' => $user->id,
                'type' => Transaction::TYPE_CONSUMPTION,
                'status' => Transaction::STATUS_CONFIRMED,
                'quantity' => $quantity,
                'unit_price' => $price,
                'amount' => $amount,
                'created_by' => $user->id,
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
            ]);
            Audit::log('consume', $tx, [], self::auditValues($tx), $user);

            return $tx;
        });

        // Outside the transaction: a mail never delays or blocks the booking.
        BeerStock::afterConsumption($quantity);

        return $tx;
    }

    /**
     * Round for colleagues and/or guests ("Runde spendieren"): the giver pays all beers at the
     * current price (one transaction of type "round", same limit and payout rules as consume).
     * Each named colleague gets one beer, $extra beers have no name (e.g. guests).
     *
     * With $includeSelf the giver's own beer is part of the round ("Runde inkl. mir": one item
     * with the giver as drinker; counts as his own beer in the statistics, not as "spendiert").
     *
     * @param  list<int>  $recipientIds  active colleagues (not the giver)
     */
    public static function giveRound(User $giver, array $recipientIds, int $extra, ?string $message = null, bool $includeSelf = false): Transaction
    {
        $recipientIds = array_values(array_unique(array_map('intval', $recipientIds)));
        $total = count($recipientIds) + $extra + ($includeSelf ? 1 : 0);
        if ($extra < 0 || $extra > Rounds::MAX_EXTRA) {
            throw new LedgerException(__('ledger.quantity_invalid'));
        }
        // At least one beer for somebody else; the own beer alone is normal consumption.
        if (count($recipientIds) + $extra < 1) {
            throw new LedgerException(__('round.none_selected'));
        }
        if ($total > Rounds::MAX_BEERS) {
            throw new LedgerException(__('round.too_many', ['max' => Rounds::MAX_BEERS]));
        }
        $message = $message !== null && trim($message) !== '' ? trim($message) : null;
        if ($message !== null && mb_strlen($message) > Rounds::MESSAGE_MAX) {
            throw new LedgerException(__('round.message_too_long', ['max' => Rounds::MESSAGE_MAX]));
        }

        $tx = DB::transaction(function () use ($giver, $recipientIds, $extra, $total, $message, $includeSelf) {
            self::lock($giver);
            self::assertActive($giver);
            self::assertBeerAccount($giver);
            if (self::hasPendingPayout($giver)) {
                throw new LedgerException(__('ledger.payout_pending'));
            }
            if (in_array($giver->id, $recipientIds, true)
                || User::query()->whereIn('id', $recipientIds)->where('status', User::STATUS_ACTIVE)->hasBeerAccount()->count() !== count($recipientIds)) {
                throw new LedgerException(__('round.recipient_invalid'));
            }

            $price = AppSettings::beerPrice();
            $amount = -$total * $price;
            if (self::availableBalance($giver) + $amount < -$giver->effectiveLimit()) {
                throw self::limitReached($giver);
            }

            $tx = Transaction::query()->create([
                'user_id' => $giver->id,
                'type' => Transaction::TYPE_ROUND,
                'status' => Transaction::STATUS_CONFIRMED,
                'quantity' => $total,
                'unit_price' => $price,
                'amount' => $amount,
                'note' => $message,
                'created_by' => $giver->id,
                'confirmed_by' => $giver->id,
                'confirmed_at' => now(),
            ]);
            foreach ($recipientIds as $id) {
                $tx->roundItems()->create(['user_id' => $id, 'quantity' => 1]);
            }
            if ($includeSelf) {
                $tx->roundItems()->create(['user_id' => $giver->id, 'quantity' => 1]);
            }
            if ($extra > 0) {
                $tx->roundItems()->create(['user_id' => null, 'quantity' => $extra]);
            }
            Audit::log('round.create', $tx, [], self::auditValues($tx) + ['recipients' => $recipientIds, 'extra' => $extra, 'include_self' => $includeSelf], $giver);

            return $tx;
        });

        // Outside the transaction: the stock mail never delays or blocks the booking.
        BeerStock::afterConsumption($total);

        return $tx;
    }

    /**
     * "Bier verschenken": $perRecipient beers for each recipient as CHF credit at the current price.
     * The giver pays all (same limit rules as own beers); stock and cash stay unchanged.
     *
     * @param  list<int>  $recipientIds
     */
    public static function giveBeers(User $giver, array $recipientIds, int $perRecipient, ?string $message = null): Transaction
    {
        $recipientIds = array_values(array_unique(array_map('intval', $recipientIds)));
        if ($recipientIds === []) {
            throw new LedgerException(__('gift.none_selected'));
        }
        if (count($recipientIds) > Gifts::MAX_RECIPIENTS || $perRecipient < 1 || $perRecipient > Gifts::MAX_PER_RECIPIENT) {
            throw new LedgerException(__('ledger.quantity_invalid'));
        }
        $message = $message !== null && trim($message) !== '' ? trim($message) : null;
        if ($message !== null && mb_strlen($message) > Gifts::MESSAGE_MAX) {
            throw new LedgerException(__('round.message_too_long', ['max' => Gifts::MESSAGE_MAX]));
        }

        return DB::transaction(function () use ($giver, $recipientIds, $perRecipient, $message) {
            self::lock($giver);
            self::assertActive($giver);
            self::assertBeerAccount($giver);
            if (self::hasPendingPayout($giver)) {
                throw new LedgerException(__('ledger.payout_pending'));
            }
            if (in_array($giver->id, $recipientIds, true)
                || User::query()->whereIn('id', $recipientIds)->where('status', User::STATUS_ACTIVE)->hasBeerAccount()->count() !== count($recipientIds)) {
                throw new LedgerException(__('round.recipient_invalid'));
            }

            $price = AppSettings::beerPrice();
            $total = $perRecipient * count($recipientIds);
            if (self::availableBalance($giver) - $total * $price < -$giver->effectiveLimit()) {
                throw self::limitReached($giver);
            }

            $row = fn (int $userId, int $quantity, int $amount, ?int $parentId) => Transaction::query()->create([
                'user_id' => $userId,
                'parent_id' => $parentId,
                'type' => Transaction::TYPE_GIFT,
                'status' => Transaction::STATUS_CONFIRMED,
                'quantity' => $quantity,
                'unit_price' => $price,
                'amount' => $amount,
                'note' => $message,
                'created_by' => $giver->id,
                'confirmed_by' => $giver->id,
                'confirmed_at' => now(),
            ]);
            $tx = $row($giver->id, $total, -$total * $price, null);
            foreach ($recipientIds as $id) {
                $row($id, $perRecipient, $perRecipient * $price, $tx->id);
            }
            Audit::log('gift.create', $tx, [], self::auditValues($tx) + ['recipients' => $recipientIds, 'per_recipient' => $perRecipient], $giver);

            return $tx;
        });
    }

    /** Undo of an own consumption or round within the undo window (a round with its beers). */
    public static function undo(Transaction $tx, User $user): void
    {
        if (! $tx->canBeUndoneBy($user)) {
            throw new LedgerException(__('ledger.undo_expired'));
        }
        if ($tx->type === Transaction::TYPE_GIFT) {
            DB::transaction(function () use ($tx, $user) {
                // Lock order (same as the booking paths, which lock a single users row): recipients (users, by id)
                // first, then the gift row. The recipient ids come from immutable gift parts, so reading them before
                // the locks is safe. All checks that matter run AFTER the locks as current reads: under REPEATABLE READ
                // an earlier non-locking SELECT would pin a snapshot that cannot see a booking committed while we waited.
                $recipients = Transaction::query()->where('parent_id', $tx->id)->pluck('user_id')->all();
                User::query()->whereIn('id', $recipients)->orderBy('id')->lockForUpdate()->get();
                // Re-load the gift row with a lock: a second (parallel or repeated) undo finds it gone; the
                // authoritative "recipient booked since" check inside is a locking read.
                $tx = self::lockUndoable($tx, $user);
                $old = self::auditValues($tx) + ['recipients' => $recipients];
                $tx->giftParts()->delete();
                $tx->delete();
                Audit::log('gift.undo', $tx, $old, [], $user);
            });

            return;
        }
        DB::transaction(function () use ($tx, $user) {
            $tx = self::lockUndoable($tx, $user);
            $old = self::auditValues($tx);
            if ($tx->type === Transaction::TYPE_ROUND) {
                $old['recipients'] = $tx->roundItems()->pluck('user_id')->all();
            }
            $tx->roundItems()->delete();
            $tx->delete();
            Audit::log($tx->type === Transaction::TYPE_ROUND ? 'round.undo' : 'consume.undo', $tx, $old, [], $user);
        });
        BeerStock::rearm();
    }

    /** Bookings a kassier/admin can cancel (storno): beers, rounds and given gifts, confirmed. */
    public static function canStorno(Transaction $tx): bool
    {
        return $tx->status === Transaction::STATUS_CONFIRMED
            && (in_array($tx->type, Transaction::BEER_TYPES, true) || $tx->isGiftGiven());
    }

    /**
     * Storno of a user's booking by a kassier/admin (any time, note required). Same effect as the user's
     * own undo: the row (with its round items / gift recipient rows) is deleted, so balance, statistics and
     * stock are restored; the audit entry keeps the full record and the note. Same safety rule as the gift
     * undo: blocked once a recipient has an outgoing booking after the gift (use a correction instead).
     * Not for accounts that left or have no beer account.
     */
    public static function storno(Transaction $tx, User $by, string $note): void
    {
        $note = trim($note);
        if ($note === '') {
            throw new LedgerException(__('ledger.note_required'));
        }
        if (! self::canStorno($tx)) {
            throw new LedgerException(__('ledger.storno_invalid'));
        }

        DB::transaction(function () use ($tx, $by, $note) {
            // Lock order as in the booking paths and the gift undo: users (by id), then the booking row.
            $userIds = $tx->isGiftGiven()
                ? Transaction::query()->where('parent_id', $tx->id)->pluck('user_id')->push($tx->user_id)->unique()->sort()->values()->all()
                : [$tx->user_id];
            $users = User::query()->whereIn('id', $userIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $locked = Transaction::query()->lockForUpdate()->find($tx->id);
            if ($locked === null || ! self::canStorno($locked)) {
                throw new LedgerException(__('ledger.storno_invalid'));
            }
            $owner = $users->get($locked->user_id);
            if ($owner === null) {
                throw new LedgerException(__('ledger.storno_invalid'));
            }
            self::assertNotLeft($owner);
            self::assertBeerAccount($owner);
            if ($locked->isGiftGiven() && Gifts::recipientsBookedSince($locked, true)) {
                throw new LedgerException(__('gift.storno_blocked'));
            }

            $old = self::auditValues($locked) + ['created_by' => $locked->created_by, 'created_at' => $locked->created_at?->format(DATE_ATOM)];
            if ($locked->type === Transaction::TYPE_ROUND) {
                $old['recipients'] = $locked->roundItems()->pluck('user_id')->all();
                $locked->roundItems()->delete();
            } elseif ($locked->type === Transaction::TYPE_GIFT) {
                $old['recipients'] = $locked->giftParts()->pluck('user_id')->all();
                $locked->giftParts()->delete();
            }
            $locked->delete();
            $action = match ($locked->type) {
                Transaction::TYPE_ROUND => 'round.storno',
                Transaction::TYPE_GIFT => 'gift.storno',
                default => 'consume.storno',
            };
            Audit::log($action, $locked, $old, ['note' => $note, 'user_id' => $locked->user_id], $by);
        });
        BeerStock::rearm();
    }

    /**
     * Re-loads the booking with a row lock inside the DB transaction; throws when it is gone (already undone)
     * or can no longer be undone, so an undo never writes a second audit entry. The check is a current read
     * (gifts: locking), so callers must already hold the related user locks.
     */
    private static function lockUndoable(Transaction $tx, User $user): Transaction
    {
        $locked = Transaction::query()->lockForUpdate()->find($tx->id);
        if ($locked === null || ! $locked->canBeUndoneBy($user, true)) {
            throw new LedgerException(__('ledger.undo_expired'));
        }

        return $locked;
    }

    /** User reports a deposit; counts once a kassier confirms it. */
    public static function reportDeposit(User $user, int $amount, string $method, ?string $note = null): Transaction
    {
        self::assertActive($user);
        self::assertBeerAccount($user);
        self::assertAmount($amount);
        if (self::hasPendingPayout($user)) {
            throw new LedgerException(__('ledger.payout_pending'));
        }

        $tx = DB::transaction(function () use ($user, $amount, $method, $note) {
            $tx = Transaction::query()->create([
                'user_id' => $user->id,
                'type' => Transaction::TYPE_DEPOSIT,
                'status' => Transaction::STATUS_PENDING,
                'method' => $method,
                'amount' => $amount,
                'note' => $note,
                'created_by' => $user->id,
            ]);
            Audit::log('deposit.report', $tx, [], self::auditValues($tx), $user);

            return $tx;
        });

        KassierNotifier::send(
            __('mail.deposit_subject', ['user' => $user->displayName()]),
            [__('mail.deposit_line', ['user' => $user->displayName()])],
            [
                __('mail.row_user') => $user->displayName(),
                __('mail.row_amount') => chf($amount),
                __('mail.row_method') => __('ledger.method.'.$method),
                __('mail.row_note') => (string) $note,
                __('mail.row_time') => local_time($tx->created_at ?? now()),
            ],
            buttonLabel: __('mail.button_confirm'),
            title: __('mail.deposit_title'),
        );

        return $tx;
    }

    /** Kassier books a deposit directly (confirmed). Not for accounts that left (locked ones are fine). */
    public static function bookDeposit(User $user, int $amount, string $method, User $by, ?string $note = null): Transaction
    {
        self::assertAmount($amount);

        return DB::transaction(function () use ($user, $amount, $method, $by, $note) {
            self::lock($user);
            self::assertNotLeft($user);
            self::assertBeerAccount($user);

            $tx = Transaction::query()->create([
                'user_id' => $user->id,
                'type' => Transaction::TYPE_DEPOSIT,
                'status' => Transaction::STATUS_CONFIRMED,
                'method' => $method,
                'amount' => $amount,
                'note' => $note,
                'created_by' => $by->id,
                'confirmed_by' => $by->id,
                'confirmed_at' => now(),
            ]);
            Audit::log('deposit.book', $tx, [], self::auditValues($tx), $by);

            return $tx;
        });
    }

    public static function confirmDeposit(Transaction $tx, User $by): void
    {
        DB::transaction(function () use ($tx, $by) {
            $user = User::query()->findOrFail($tx->user_id);
            self::lock($user);
            $locked = self::lockPending($tx, Transaction::TYPE_DEPOSIT);
            self::assertNotLeft($user);
            self::saveAudited($locked, ['status' => Transaction::STATUS_CONFIRMED, 'confirmed_by' => $by->id, 'confirmed_at' => now()], 'deposit.confirm', $by);
            $tx->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * The user corrects an own deposit report while it is still pending
     * (no new kassier mail; the backend list shows the new values as "geändert").
     */
    public static function updateDeposit(Transaction $tx, User $user, int $amount, string $method): void
    {
        self::assertAmount($amount);
        DB::transaction(function () use ($tx, $user, $amount, $method) {
            $locked = self::lockOwnPendingDeposit($tx, $user);
            self::saveAudited($locked, ['amount' => $amount, 'method' => $method, 'edited_at' => now()], 'deposit.edit', $user);
            $tx->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** The user withdraws an own pending deposit report ("nicht bezahlt"); the row is deleted. */
    public static function cancelDeposit(Transaction $tx, User $user): void
    {
        DB::transaction(function () use ($tx, $user) {
            $locked = self::lockOwnPendingDeposit($tx, $user);
            $locked->delete();
            Audit::log('deposit.withdraw', $locked, self::auditValues($locked), [], $user);
        });
    }

    public static function rejectDeposit(Transaction $tx, User $by): void
    {
        DB::transaction(function () use ($tx, $by) {
            $locked = self::lockPending($tx, Transaction::TYPE_DEPOSIT);
            self::saveAudited($locked, ['status' => Transaction::STATUS_REJECTED, 'confirmed_by' => $by->id, 'confirmed_at' => now()], 'deposit.reject', $by);
            $tx->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** Signed correction by kassier/admin; note is mandatory. Not for accounts that left (locked ones are fine). */
    public static function correction(User $user, int $amount, string $note, User $by): Transaction
    {
        if ($amount === 0) {
            throw new LedgerException(__('ledger.amount_invalid'));
        }
        if (trim($note) === '') {
            throw new LedgerException(__('ledger.note_required'));
        }

        return DB::transaction(function () use ($user, $amount, $note, $by) {
            self::lock($user);
            self::assertNotLeft($user);
            self::assertBeerAccount($user);

            $tx = Transaction::query()->create([
                'user_id' => $user->id,
                'type' => Transaction::TYPE_CORRECTION,
                'status' => Transaction::STATUS_CONFIRMED,
                'amount' => $amount,
                'note' => $note,
                'created_by' => $by->id,
                'confirmed_by' => $by->id,
                'confirmed_at' => now(),
            ]);
            Audit::log('correction.create', $tx, [], self::auditValues($tx), $by);

            return $tx;
        });
    }

    /**
     * Carry-over from the old paper tally list, entered by the user at registration: pending until a
     * kassier confirms it (balance and limit only count it then). Negative = debt, positive = credit.
     * With beers, quantity and the current price are stored (amount = quantity × price).
     * Neither the beer stock nor the statistics count it (the beers were drunk before the app).
     */
    public static function reportCarryover(User $user, int $amount, ?int $quantity = null, ?int $unitPrice = null): Transaction
    {
        if ($amount === 0 || abs($amount) > Transaction::CARRYOVER_MAX_AMOUNT
            || ($quantity !== null && ($quantity < 1 || $quantity > Transaction::CARRYOVER_MAX_BEERS || $quantity * (int) $unitPrice !== abs($amount)))) {
            throw new LedgerException(__('ledger.amount_invalid'));
        }

        return DB::transaction(function () use ($user, $amount, $quantity, $unitPrice) {
            $tx = Transaction::query()->create([
                'user_id' => $user->id,
                'type' => Transaction::TYPE_CARRYOVER,
                'status' => Transaction::STATUS_PENDING,
                'quantity' => $quantity,
                'unit_price' => $quantity !== null ? $unitPrice : null,
                'amount' => $amount,
                'created_by' => $user->id,
            ]);
            Audit::log('carryover.report', $tx, [], self::auditValues($tx), $user);

            return $tx;
        });
    }

    /**
     * The kassier confirms a carry-over, optionally with a corrected (signed) amount. A changed amount
     * drops the beer quantity (it no longer matches) and keeps the original value in the note.
     */
    public static function confirmCarryover(Transaction $tx, User $by, ?int $amount = null): void
    {
        if ($amount !== null && ($amount === 0 || abs($amount) > Transaction::CARRYOVER_MAX_AMOUNT)) {
            throw new LedgerException(__('ledger.amount_invalid'));
        }

        DB::transaction(function () use ($tx, $by, $amount) {
            $user = User::query()->findOrFail($tx->user_id);
            self::lock($user);
            $locked = self::lockPending($tx, Transaction::TYPE_CARRYOVER);
            self::assertNotLeft($user);
            $changes = ['status' => Transaction::STATUS_CONFIRMED, 'confirmed_by' => $by->id, 'confirmed_at' => now()];
            if ($amount !== null && $amount !== $locked->amount) {
                $changes += [
                    'note' => __('ledger.carryover_adjusted', ['text' => $locked->carryoverText()]),
                    'amount' => $amount,
                    'quantity' => null,
                    'unit_price' => null,
                ];
            }
            self::saveAudited($locked, $changes, 'carryover.confirm', $by);
            $tx->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public static function rejectCarryover(Transaction $tx, User $by): void
    {
        DB::transaction(function () use ($tx, $by) {
            $locked = self::lockPending($tx, Transaction::TYPE_CARRYOVER);
            self::saveAudited($locked, ['status' => Transaction::STATUS_REJECTED, 'confirmed_by' => $by->id, 'confirmed_at' => now()], 'carryover.reject', $by);
            $tx->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Leaving: request the payout of the balance. A kassier confirms it once paid out.
     */
    public static function requestPayout(User $user): Transaction
    {
        $tx = DB::transaction(function () use ($user) {
            self::lock($user);
            self::assertBeerAccount($user);
            self::assertCanLeave($user);

            $tx = Transaction::query()->create([
                'user_id' => $user->id,
                'type' => Transaction::TYPE_PAYOUT,
                'status' => Transaction::STATUS_PENDING,
                'method' => 'cash',
                // Informative while pending; recalculated on confirmation.
                'amount' => -self::balance($user),
                'created_by' => $user->id,
            ]);
            Audit::log('payout.request', $tx, [], self::auditValues($tx), $user);

            return $tx;
        });

        KassierNotifier::send(
            __('mail.payout_subject', ['user' => $user->displayName()]),
            [__('mail.payout_line', ['user' => $user->displayName()])],
            [
                __('mail.row_user') => $user->displayName(),
                __('mail.row_amount') => chf(-$tx->amount),
                __('mail.row_time') => local_time($tx->created_at ?? now()),
            ],
            buttonLabel: __('mail.button_confirm'),
            title: __('mail.payout_title'),
        );

        return $tx;
    }

    /**
     * Payout handed over: amount = -balance, the account becomes "left".
     * The status is re-checked under a row lock, so a second confirmation fails.
     */
    public static function confirmPayout(Transaction $tx, User $by, string $method = 'cash'): void
    {
        DB::transaction(function () use ($tx, $by, $method) {
            $user = User::query()->findOrFail($tx->user_id);
            self::lock($user);
            $locked = self::lockPending($tx, Transaction::TYPE_PAYOUT);
            self::saveAudited($locked, [
                'status' => Transaction::STATUS_CONFIRMED,
                'method' => $method,
                'amount' => -self::balance($user),
                'confirmed_by' => $by->id,
                'confirmed_at' => now(),
            ], 'payout.confirm', $by);
            self::markLeft($user);
            $tx->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public static function cancelPayout(Transaction $tx, User $by): void
    {
        DB::transaction(function () use ($tx, $by) {
            $locked = self::lockPending($tx, Transaction::TYPE_PAYOUT);
            self::saveAudited($locked, ['status' => Transaction::STATUS_REJECTED, 'confirmed_by' => $by->id, 'confirmed_at' => now()], 'payout.cancel', $by);
            $tx->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** Leaving: the balance is donated to the cash box; the account becomes "left". */
    public static function donate(User $user): ?Transaction
    {
        return DB::transaction(function () use ($user) {
            self::lock($user);
            self::assertBeerAccount($user);
            self::assertCanLeave($user);

            $balance = self::balance($user);
            $tx = null;
            if ($balance > 0) {
                $tx = Transaction::query()->create([
                    'user_id' => $user->id,
                    'type' => Transaction::TYPE_DONATION,
                    'status' => Transaction::STATUS_CONFIRMED,
                    'amount' => -$balance,
                    'created_by' => $user->id,
                    'confirmed_by' => $user->id,
                    'confirmed_at' => now(),
                ]);
            }
            self::markLeft($user);
            Audit::log('leave.donate', $user, [], ['amount' => $tx?->amount ?? 0], $user);

            return $tx;
        });
    }

    /**
     * Open (unpaid) items, oldest first paid: credits are allocated to the debits in
     * chronological order. The lines always add up to the total (= -balance):
     *  - beers: fully open beers grouped by their original price;
     *  - partial: the unpaid rest of a partly paid beer (original price);
     *  - other: uncovered non-beer debits (negative corrections etc.).
     *
     * With $available the rows of availableBalance() count (user's own view, incl. pending deposits
     * and carry-overs; pending deposits not while on hold).
     *
     * @return array{total:int, beers: list<array{unit_price:int, quantity:int, amount:int}>, partial: list<array{unit_price:int, amount:int}>, other:int}
     */
    public static function openItems(User $user, bool $available = false): array
    {
        $balance = $available ? self::availableBalance($user) : self::balance($user);
        if ($balance >= 0) {
            return ['total' => 0, 'beers' => [], 'partial' => [], 'other' => 0];
        }

        $query = $available ? self::availableRows($user) : Transaction::query()->where('user_id', $user->id)->confirmed();
        $rows = $query->orderBy('created_at')->orderBy('id')->get();
        $credit = (int) $rows->where('amount', '>', 0)->sum('amount');
        $beers = [];
        $partial = [];
        $other = 0;

        foreach ($rows->where('amount', '<', 0) as $row) {
            $debit = -$row->amount;
            $covered = min($credit, $debit);
            $credit -= $covered;
            if ($covered === $debit) {
                continue;
            }

            $price = (int) $row->unit_price;
            $beerRow = in_array($row->type, [...Transaction::BEER_TYPES, Transaction::TYPE_CARRYOVER, Transaction::TYPE_GIFT], true);
            if (! $beerRow || $price <= 0 || (int) $row->quantity * $price !== $debit) {
                $other += $debit - $covered;

                continue;
            }

            // Beers paid in full, then possibly one beer paid in part, the rest is fully open.
            $paidBeers = intdiv($covered, $price);
            $rest = $covered % $price;
            if ($rest > 0) {
                $partial[] = ['unit_price' => $price, 'amount' => $price - $rest];
            }
            $open = (int) $row->quantity - $paidBeers - ($rest > 0 ? 1 : 0);
            if ($open > 0) {
                $beers[$price] = ($beers[$price] ?? 0) + $open;
            }
        }

        ksort($beers);

        return [
            'total' => -$balance,
            'beers' => array_values(array_map(
                fn ($price, $qty) => ['unit_price' => (int) $price, 'quantity' => $qty, 'amount' => $price * $qty],
                array_keys($beers),
                $beers,
            )),
            'partial' => $partial,
            'other' => $other,
        ];
    }

    /**
     * @return Collection<int, Transaction>
     */
    public static function pendingDeposits(): Collection
    {
        return Transaction::query()->with('user')->pending()->where('type', Transaction::TYPE_DEPOSIT)->orderBy('created_at')->get();
    }

    /**
     * @return Collection<int, Transaction>
     */
    public static function pendingPayouts(): Collection
    {
        return Transaction::query()->with('user')->pending()->where('type', Transaction::TYPE_PAYOUT)->orderBy('created_at')->get();
    }

    /**
     * @return Collection<int, Transaction>
     */
    public static function pendingCarryovers(): Collection
    {
        return Transaction::query()->with('user')->pending()->where('type', Transaction::TYPE_CARRYOVER)->orderBy('created_at')->get();
    }

    public static function assertCanLeave(User $user): void
    {
        self::assertActive($user);
        if (self::hasPendingPayout($user)) {
            throw new LedgerException(__('ledger.payout_pending'));
        }
        if (Transaction::query()->where('user_id', $user->id)->where('type', Transaction::TYPE_DEPOSIT)->pending()->exists()) {
            throw new LedgerException(__('ledger.deposit_pending'));
        }
        if (Transaction::query()->where('user_id', $user->id)->where('type', Transaction::TYPE_CARRYOVER)->pending()->exists()) {
            throw new LedgerException(__('ledger.carryover_pending'));
        }
        if (self::balance($user) < 0) {
            throw new LedgerException(__('ledger.leave_negative'));
        }
    }

    private static function markLeft(User $user): void
    {
        // A left account is no longer locked (e.g. payout of a locked account confirmed): clear the lock data.
        $user->forceFill(['status' => User::STATUS_LEFT, 'left_at' => now(), 'locked_at' => null, 'locked_reason' => null, 'locked_by' => null])->save();
    }

    private static function assertActive(User $user): void
    {
        if (! $user->isActive()) {
            throw new LedgerException(__('ledger.user_left'));
        }
    }

    /** Kassier bookings (deposit, correction, confirmations, storno) also work on locked accounts, not on left ones. */
    private static function assertNotLeft(User $user): void
    {
        if (! $user->isActive() && ! $user->isLocked()) {
            throw new LedgerException(__('ledger.user_left'));
        }
    }

    /** "Nur Verwaltung" accounts have no beer account: nothing is ever booked on them. */
    private static function assertBeerAccount(User $user): void
    {
        if (! $user->hasBeerAccount()) {
            throw new LedgerException(__('account.admin_only_blocked'));
        }
    }

    /**
     * Switches "Nur Verwaltung" on or off. Only kassiers/admins; switching on needs balance 0 and
     * no pending bookings, so no money is stranded on an account without a beer account.
     */
    /** "Nur Verwaltung" can only be switched (either way) while the balance is 0 and nothing is pending. */
    public static function adminOnlySettled(User $user): bool
    {
        return self::balance($user) === 0 && ! Transaction::query()->where('user_id', $user->id)->pending()->exists();
    }

    public static function setAdminOnly(User $user, bool $on): void
    {
        DB::transaction(function () use ($user, $on) {
            self::lock($user);
            if ($on && ! $user->isKassier()) {
                throw new LedgerException(__('account.admin_only_role'));
            }
            if (! self::adminOnlySettled($user)) {
                throw new LedgerException(__('account.admin_only_settle_first'));
            }
            $was = (bool) $user->admin_only;
            $user->forceFill(['admin_only' => $on])->save();
            if ($was !== $on) {
                Audit::log('user.admin_only', $user, ['admin_only' => $was], ['admin_only' => $on]);
            }
        });
    }

    /** Booking fields for the audit log. */
    private static function auditValues(Transaction $tx): array
    {
        return array_filter([
            'user_id' => $tx->user_id,
            'type' => $tx->type,
            'status' => $tx->status,
            'quantity' => $tx->quantity,
            'unit_price' => $tx->unit_price,
            'amount' => $tx->amount,
            'method' => $tx->method,
            'note' => $tx->note,
        ], fn ($v) => $v !== null);
    }

    /** Saves the changes of a locked transaction and logs them (same DB transaction). */
    private static function saveAudited(Transaction $tx, array $changes, string $action, User $by): void
    {
        $before = $tx->only(array_keys($changes));
        $tx->forceFill($changes)->save();
        $after = $tx->only(array_keys($changes));
        [$old, $new] = Audit::diff(
            array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : $v, $before),
            array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : $v, $after),
        );
        Audit::log($action, $tx, $old + ['user_id' => $tx->user_id], $new + ['user_id' => $tx->user_id], $by);
    }

    private static function assertAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new LedgerException(__('ledger.amount_invalid'));
        }
    }

    /**
     * Locks an own deposit report; a report the kassier handled meanwhile cannot be changed.
     */
    private static function lockOwnPendingDeposit(Transaction $tx, User $user): Transaction
    {
        $locked = Transaction::query()->whereKey($tx->id)->lockForUpdate()->first();
        if (! $locked || $locked->user_id !== $user->id || $locked->type !== Transaction::TYPE_DEPOSIT) {
            throw new LedgerException(__('ledger.not_pending'));
        }
        if ($locked->status === Transaction::STATUS_CONFIRMED) {
            throw new LedgerException(__('ledger.deposit_already_confirmed'));
        }
        if (! $locked->isPending()) {
            throw new LedgerException(__('ledger.not_pending'));
        }

        return $locked;
    }

    /**
     * Locks the transaction row and re-reads it; it must still be pending and of the given type.
     */
    private static function lockPending(Transaction $tx, string $type): Transaction
    {
        $locked = Transaction::query()->whereKey($tx->id)->lockForUpdate()->first();
        if (! $locked || $locked->type !== $type || ! $locked->isPending()) {
            throw new LedgerException(__('ledger.not_pending'));
        }

        return $locked;
    }

    /**
     * Serialises bookings of one user (row lock on MySQL/MariaDB/PostgreSQL) and
     * refreshes the status, so checks inside the lock see the current value.
     */
    private static function lock(User $user): User
    {
        $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        $user->forceFill(['status' => $fresh->status, 'role' => $fresh->role, 'admin_only' => $fresh->admin_only])
            ->syncOriginalAttributes(['status', 'role', 'admin_only']);

        return $user;
    }
}
