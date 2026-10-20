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
            ->whereIn('type', [Transaction::TYPE_DEPOSIT, Transaction::TYPE_PAYOUT])->count();
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

    public static function undo(Transaction $tx, User $user): void
    {
        if (! $tx->canBeUndoneBy($user)) {
            throw new LedgerException(__('ledger.undo_expired'));
        }
        $tx->delete();
        BeerStock::rearm();
    }

    /** User reports a deposit; counts once a kassier confirms it. */
    public static function reportDeposit(User $user, int $amount, string $method, ?string $note = null): Transaction
    {
        self::assertActive($user);
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
            [__('mail.deposit_line', ['user' => $user->displayName(), 'amount' => chf($amount), 'method' => __('ledger.method.'.$method)])],
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
     * Leaving: request the payout of the balance. A kassier confirms it once paid out.
     */
    public static function requestPayout(User $user): Transaction
    {
        $tx = DB::transaction(function () use ($user) {
            self::lock($user);
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
            [__('mail.payout_line', ['user' => $user->displayName(), 'amount' => chf(-$tx->amount)])],
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
            if ($row->type !== Transaction::TYPE_CONSUMPTION || $price <= 0 || (int) $row->quantity * $price !== $debit) {
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

    public static function assertCanLeave(User $user): void
    {
        self::assertActive($user);
        if (self::hasPendingPayout($user)) {
            throw new LedgerException(__('ledger.payout_pending'));
        }
        if (Transaction::query()->where('user_id', $user->id)->where('type', Transaction::TYPE_DEPOSIT)->pending()->exists()) {
            throw new LedgerException(__('ledger.deposit_pending'));
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
        $user->forceFill(['status' => $fresh->status])->syncOriginalAttribute('status');

        return $user;
    }
}
