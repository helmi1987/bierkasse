<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal SSO providers: a provider with an owner belongs to that user only
 * (null = global provider managed by the admin). At most one per user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sso_providers', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
            $table->unique('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('sso_providers', function (Blueprint $table) {
            $table->dropForeign(['owner_user_id']);
            $table->dropUnique(['owner_user_id']);
            $table->dropColumn('owner_user_id');
        });
    }
};
