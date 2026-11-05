<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client-secret expiry per provider (admin reminders) and the last reminder stage sent
 * (days before expiry, 0 = expiry day; reset when the date changes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sso_providers', function (Blueprint $table) {
            $table->date('client_secret_expires_at')->nullable()->after('client_secret');
            $table->unsignedSmallInteger('secret_reminder_stage')->nullable()->after('client_secret_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('sso_providers', function (Blueprint $table) {
            $table->dropColumn(['client_secret_expires_at', 'secret_reminder_stage']);
        });
    }
};
