<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-use registration codes (QR/flyer) created by admins: valid in every registration mode,
 * no limit and no expiry; can be locked or deleted. The code is stored encrypted (shown again
 * for reprints) and as SHA-256 hash for the lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_codes', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100);
            $table->string('code_hash', 64)->unique();
            $table->text('code');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('registration_code_id')->nullable()->after('role')->constrained('registration_codes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registration_code_id');
        });
        Schema::dropIfExists('registration_codes');
    }
};
