<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separate favourites per list: user_favorites.list = 'round' ("Runde spendieren") | 'gift' ("Bier verschenken").
 * Existing favourites (shared so far) are kept in BOTH lists.
 *
 * Order matters on MySQL/MariaDB: the foreign key on user_id needs an index starting with user_id, so the new
 * unique index (user_id, list, favorite_user_id) is created before the old one is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_favorites', function (Blueprint $table) {
            // Existing rows become the 'round' favourites.
            $table->string('list', 5)->default('round')->after('favorite_user_id');
            $table->unique(['user_id', 'list', 'favorite_user_id']);
        });

        Schema::table('user_favorites', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'favorite_user_id']);
        });

        // Copy every existing favourite into the 'gift' list.
        DB::table('user_favorites')->insertUsing(
            ['user_id', 'favorite_user_id', 'list', 'created_at', 'updated_at'],
            DB::table('user_favorites')->where('list', 'round')
                ->select('user_id', 'favorite_user_id', DB::raw("'gift'"), 'created_at', 'updated_at'),
        );
    }

    public function down(): void
    {
        // Merge: a colleague pinned in either list stays pinned (as one row).
        DB::table('user_favorites')->insertUsing(
            ['user_id', 'favorite_user_id', 'list', 'created_at', 'updated_at'],
            DB::table('user_favorites as g')->where('g.list', 'gift')
                ->whereNotExists(fn ($q) => $q->from('user_favorites as r')->where('r.list', 'round')
                    ->whereColumn('r.user_id', 'g.user_id')->whereColumn('r.favorite_user_id', 'g.favorite_user_id'))
                ->select('g.user_id', 'g.favorite_user_id', DB::raw("'round'"), 'g.created_at', 'g.updated_at'),
        );
        DB::table('user_favorites')->where('list', 'gift')->delete();

        Schema::table('user_favorites', function (Blueprint $table) {
            $table->unique(['user_id', 'favorite_user_id']);
        });

        Schema::table('user_favorites', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'list', 'favorite_user_id']);
        });

        Schema::table('user_favorites', function (Blueprint $table) {
            $table->dropColumn('list');
        });
    }
};
