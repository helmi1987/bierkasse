<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign in with Apple: team id and key id for the client secret JWT.
 * The private key (.p8) is stored in the encrypted client_secret column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sso_providers', function (Blueprint $table) {
            $table->string('apple_team_id')->nullable()->after('client_secret');
            $table->string('apple_key_id')->nullable()->after('apple_team_id');
        });
    }

    public function down(): void
    {
        Schema::table('sso_providers', function (Blueprint $table) {
            $table->dropColumn(['apple_team_id', 'apple_key_id']);
        });
    }
};
