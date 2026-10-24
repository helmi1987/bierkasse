<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consent to the privacy policy and terms of use (accepted versions, time) and the account
 * status "locked" (reason, when, by whom). Existing users have no consent yet and must accept
 * on their next request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('privacy_accepted_version', 32)->nullable();
            $table->string('terms_accepted_version', 32)->nullable();
            $table->timestamp('consent_accepted_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('locked_reason', 500)->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('locked_by');
            $table->dropColumn(['privacy_accepted_version', 'terms_accepted_version', 'consent_accepted_at', 'locked_at', 'locked_reason']);
        });
    }
};
