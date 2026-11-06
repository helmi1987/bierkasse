<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collapsed help texts removed again (1.33.2): the column of 1.33.0 is dropped; the 1.33.0 migration stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'open_hints')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('open_hints');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'open_hints')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->json('open_hints')->nullable();
        });
    }
};
