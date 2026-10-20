<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 32)->unique();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('email')->nullable()->index();
            // Null = no local password (SSO only).
            $table->string('password')->nullable();
            // admin | kassier | user
            $table->string('role', 16)->default('user');
            // active | left (left = no login, kept for history)
            $table->string('status', 16)->default('active');
            // Rappen the balance may go below zero; null = default from the settings.
            $table->integer('overdraft_limit')->nullable();
            $table->boolean('stats_opt_out')->default(false);
            $table->boolean('must_change_password')->default(false);
            // TOTP: secret and recovery code hashes are stored encrypted (APP_KEY).
            $table->text('totp_secret')->nullable();
            $table->timestamp('totp_confirmed_at')->nullable();
            $table->unsignedBigInteger('totp_last_step')->nullable();
            $table->text('totp_recovery_codes')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
