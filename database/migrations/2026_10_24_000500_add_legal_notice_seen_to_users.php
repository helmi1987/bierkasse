<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a user dismissed the update notice of the privacy policy / terms of use.
 * Null = never dismissed (a notice published later is shown).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('privacy_notice_seen_at')->nullable();
            $table->timestamp('terms_notice_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['privacy_notice_seen_at', 'terms_notice_seen_at']);
        });
    }
};
