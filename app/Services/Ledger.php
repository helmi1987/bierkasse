<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use App\Support\AppSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * All bookings on the users' ledgers. Balance = sum of the confirmed amounts.
 */
class Ledger
{
    public const MAX_QUANTITY = 10;

    public static function balance(User $user): int
    {
        return (int) Transaction::query()->where('user_id', $user->id)->confirmed()->sum('amount');
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
            if (self::balance($user) + $amount < -$user->effectiveLimit()) {
                throw new LedgerException(__('ledger.limit_reached'));
            }

            return Transaction::query()->create([
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
     * @param  list<int>  $recipientIds  active colleagues (not the giver)
     */
    public static function giveRound(User $giver, array $recipientIds, int $extra, ?string $message = null): Transaction
    {
        $recipientIds = array_values(array_unique(array_map('intval', $recipientIds)));
        $total = count($recipientIds) + $extra;
        if ($extra < 0 || $extra > Rounds::MAX_EXTRA) {
            throw new LedgerException(__('ledger.quantity_invalid'));
        }
        if ($total < 1) {
            throw new LedgerException(__('round.none_selected'));
        }
        if ($total > Rounds::MAX_BEERS) {
            throw new LedgerException(__('round.too_many', ['max' => Rounds::MAX_BEERS]));
        }
        $message = $message !== null && trim($message) !== '' ? trim($message) : null;
        if ($message !== null && mb_strlen($message) > Rounds::MESSAGE_MAX) {
            throw new LedgerException(__('round.message_too_long', ['max' => Rounds::MESSAGE_MAX]));
        }

        $tx = DB::transaction(function () use ($giver, $recipientIds, $extra, $total, $message) {
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
            if (self::balance($giver) + $amount < -$giver->effectiveLimit()) {
                throw new LedgerException(__('ledger.limit_reached'));
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
            if ($extra > 0) {
                $tx->roundItems()->create(['user_id' => null, 'quantity' => $extra]);
            }

            return $tx;
        });

        // Outside the transaction: mails never delay or block the booking.
        BeerStock::afterConsumption($total);
        Rounds::notifyRecipients($tx, $giver);

        return $tx;
    }

    /** Undo of an own consumption or round within the undo window (a round with its beers). */
    public static function undo(Transaction $tx, User $user): void
    {
        if (! $tx->canBeUndoneBy($user)) {
            throw new LedgerException(__('ledger.undo_expired'));
        }
        DB::transaction(function () use ($tx) {
            $tx->roundItems()->delete();
            $tx->delete();
        });
        BeerStock::rearm();
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

        $tx = Transaction::query()->create([
            'user_id' => $user->id,
            'type' => Transaction::TYPE_DEPOSIT,
            'status' => Transaction::STATUS_PENDING,
            'method' => $method,
            'amount' => $amount,
            'note' => $note,
            'created_by' => $user->id,
        ]);

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

    /** Kassier books a deposit directly (confirmed). Not for accounts that left. */
    public static function bookDeposit(User $user, int $amount, string $method, User $by, ?string $note = null): Transaction
    {
        self::assertAmount($amount);

        return DB::transaction(function () use ($user, $amount, $method, $by, $note) {
            self::lock($user);
            self::assertActive($user);
            self::assertBeerAccount($user);

            return Transaction::query()->create([
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
        });
    }

    public static function confirmDeposit(Transaction $tx, User $by): void
    {
        DB::transaction(function () use ($tx, $by) {
            $user = User::query()->findOrFail($tx->user_id);
            self::lock($user);
            $locked = self::lockPending($tx, Transaction::TYPE_DEPOSIT);
            self::assertActive($user);
            $locked->forceFill(['status' => Transaction::STATUS_CONFIRMED, 'confirmed_by' => $by->id, 'confirmed_at' => now()])->save();
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
            $locked->forceFill(['amount' => $amount, 'method' => $method, 'edited_at' => now()])->save();
            $tx->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** The user withdraws an own pending deposit report ("nicht bezahlt"); the row is deleted. */
    public static function cancelDeposit(Transaction $tx, User $user): void
    {
        DB::transaction(function () use ($tx, $user) {
            self::lockOwnPendingDeposit($tx, $user)->delete();
        });
    }

    public static function rejectDeposit(Transaction $tx, User $by): void
    {
        DB::transaction(function () use ($tx, $by) {
            $locked = self::lockPending($tx, Transaction::TYPE_DEPOSIT);
            $locked->forceFill(['status' => Transaction::STATUS_REJECTED, 'confirmed_by' => $by->id, 'confirmed_at' => now()])->save();
            $tx->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** Signed correction by kassier/admin; note is mandatory. Not for accounts that left. */
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
            self::assertActive($user);
            self::assertBeerAccount($user);

            return Transaction::query()->create([
                'user_id' => $user->id,
                'type' => Transaction::TYPE_CORRECTION,
                'status' => Transaction::STATUS_CONFIRMED,
                'amount' => $amount,
                'note' => $note,
                'created_by' => $by->id,
                'confirmed_by' => $by->id,
                'confirmed_at' => now(),
            ]);
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

        return Transaction::query()->create([
            'user_id' => $user->id,
            'type' => Transaction::TYPE_CARRYOVER,
            'status' => Transaction::STATUS_PENDING,
            'quantity' => $quantity,
            'unit_price' => $quantity !== null ? $unitPrice : null,
            'amount' => $amount,
            'created_by' => $user->id,
        ]);
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
            self::assertActive($user);
            $changes = ['status' => Transaction::STATUS_CONFIRMED, 'confirmed_by' => $by->id, 'confirmed_at' => now()];
            if ($amount !== null && $amount !== $locked->amount) {
                $changes += [
                    'note' => __('ledger.carryover_adjusted', ['text' => $locked->carryoverText()]),
                    'amount' => $amount,
                    'quantity' => null,
                    'unit_price' => null,
                ];
            }
            $locked->forceFill($changes)->save();
            $tx->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public static function rejectCarryover(Transaction $tx, User $by): void
    {
        DB::transaction(function () use ($tx, $by) {
            $locked = self::lockPending($tx, Transaction::TYPE_CARRYOVER);
            $locked->forceFill(['status' => Transaction::STATUS_REJECTED, 'confirmed_by' => $by->id, 'confirmed_at' => now()])->save();
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

            return Transaction::query()->create([
                'user_id' => $user->id,
                'type' => Transaction::TYPE_PAYOUT,
                'status' => Transaction::STATUS_PENDING,
                'method' => 'cash',
                // Informative while pending; recalculated on confirmation.
                'amount' => -self::balance($user),
                'created_by' => $user->id,
            ]);
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
            $locked->forceFill([
                'status' => Transaction::STATUS_CONFIRMED,
                'method' => $method,
                'amount' => -self::balance($user),
                'confirmed_by' => $by->id,
                'confirmed_at' => now(),
            ])->save();
            self::markLeft($user);
            $tx->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public static function cancelPayout(Transaction $tx, User $by): void
    {
        DB::transaction(function () use ($tx, $by) {
            $locked = self::lockPending($tx, Transaction::TYPE_PAYOUT);
            $locked->forceFill(['status' => Transaction::STATUS_REJECTED, 'confirmed_by' => $by->id, 'confirmed_at' => now()])->save();
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
     * @return array{total:int, beers: list<array{unit_price:int, quantity:int, amount:int}>, partial: list<array{unit_price:int, amount:int}>, other:int}
     */
    public static function openItems(User $user): array
    {
        $balance = self::balance($user);
        if ($balance >= 0) {
            return ['total' => 0, 'beers' => [], 'partial' => [], 'other' => 0];
        }

        $rows = Transaction::query()->where('user_id', $user->id)->confirmed()->orderBy('created_at')->orderBy('id')->get();
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
            $beerRow = in_array($row->type, [...Transaction::BEER_TYPES, Transaction::TYPE_CARRYOVER], true);
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
        $user->forceFill(['status' => User::STATUS_LEFT, 'left_at' => now()])->save();
    }

    private static function assertActive(User $user): void
    {
        if (! $user->isActive()) {
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
    public static function setAdminOnly(User $user, bool $on): void
    {
        DB::transaction(function () use ($user, $on) {
            self::lock($user);
            if ($on) {
                if (! $user->isKassier()) {
                    throw new LedgerException(__('account.admin_only_role'));
                }
                if (self::balance($user) !== 0 || Transaction::query()->where('user_id', $user->id)->pending()->exists()) {
                    throw new LedgerException(__('account.admin_only_settle_first'));
                }
            }
            $user->forceFill(['admin_only' => $on])->save();
        });
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
