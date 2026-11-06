<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Name in Statistik Entsorgung zeigen" (1.32.0): own opt-out for the disposal ranking, separate from
 * stats_opt_out (beer statistics). Default false = name shown; deliberately not copied from stats_opt_out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('disposal_stats_opt_out')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('disposal_stats_opt_out');
        });
    }
};
