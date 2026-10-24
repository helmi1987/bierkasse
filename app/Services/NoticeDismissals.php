<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Dismissed dashboard notices ("Runde erhalten" / "Bier erhalten"): stored per user and transaction
 * (table notice_dismissals), so a dismissed notice never shows again on any device.
 */
class NoticeDismissals
{
    /** Subquery: transaction IDs the user dismissed. */
    public static function query(User $user): Builder
    {
        return DB::table('notice_dismissals')->where('user_id', $user->id)->select('transaction_id');
    }

    /** Transactions the user may get a notice for: his gift recipient row or a round that contains a beer for him. */
    public static function isNoticeFor(User $user, Transaction $tx): bool
    {
        if ($tx->type === Transaction::TYPE_GIFT) {
            return $tx->parent_id !== null && $tx->user_id === $user->id;
        }

        return $tx->type === Transaction::TYPE_ROUND && $tx->user_id !== $user->id
            && $tx->roundItems()->where('user_id', $user->id)->exists();
    }

    /** Idempotent. Returns false when the transaction is no notice of this user. */
    public static function dismiss(User $user, Transaction $tx): bool
    {
        if (! self::isNoticeFor($user, $tx)) {
            return false;
        }
        DB::table('notice_dismissals')->insertOrIgnore([
            'user_id' => $user->id, 'transaction_id' => $tx->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return true;
    }
}
