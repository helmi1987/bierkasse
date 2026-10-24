<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Colleagues a user pinned to the top of the "Runde spendieren" / "Bier verschenken" lists
 * (table user_favorites, same favourites for both lists).
 */
class Favorites
{
    /**
     * IDs of the user's favourites.
     *
     * @return list<int>
     */
    public static function ids(User $user): array
    {
        return DB::table('user_favorites')->where('user_id', $user->id)->pluck('favorite_user_id')
            ->map(fn ($id) => (int) $id)->all();
    }

    /** Pin or unpin $colleague; returns true when he is pinned afterwards. */
    public static function toggle(User $user, User $colleague): bool
    {
        $row = ['user_id' => $user->id, 'favorite_user_id' => $colleague->id];
        if (DB::table('user_favorites')->where($row)->delete() > 0) {
            return false;
        }
        DB::table('user_favorites')->insertOrIgnore($row + ['created_at' => now(), 'updated_at' => now()]);

        return true;
    }

    /**
     * Favourites first, the rest below, each part in the given order. Sets the attributes
     * "sort_pos" (position in the given order) and "is_favorite" on every user.
     * Favourites that are not in the list (inactive, deleted, no beer account) are ignored.
     *
     * @param  Collection<int, User>  $colleagues
     * @param  list<int>  $favoriteIds
     * @return Collection<int, User>
     */
    public static function arrange(Collection $colleagues, array $favoriteIds): Collection
    {
        $colleagues->each(function (User $c, int $i) use ($favoriteIds) {
            $c->setAttribute('sort_pos', $i);
            $c->setAttribute('is_favorite', in_array($c->id, $favoriteIds, true));
        });

        return $colleagues->filter(fn (User $c) => $c->is_favorite)
            ->concat($colleagues->reject(fn (User $c) => $c->is_favorite))->values();
    }
}
