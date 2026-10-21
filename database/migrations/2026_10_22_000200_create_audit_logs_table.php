<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log with a SHA-256 hash chain (decision 2026-10-22: entries are never deleted).
 * "audit_chain" holds the head of the chain in one row; it is locked while an entry is appended,
 * so concurrent writers never fork the chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Stored as text (exact string is part of the hash).
            $table->string('created_at', 19);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // Snapshot of the actor id (hashed; actor_id may be nulled by the foreign key).
            $table->unsignedBigInteger('actor_ref')->nullable();
            $table->string('actor_name', 100)->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // Canonical JSON (text, not json: the exact string is hashed).
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('prev_hash', 64);
            $table->string('hash', 64);

            $table->index('created_at');
            $table->index(['actor_ref', 'id']);
            $table->index(['action', 'id']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('audit_chain', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('last_hash', 64);
            $table->unsignedBigInteger('entries');
        });
        DB::table('audit_chain')->insert(['id' => 1, 'last_hash' => '', 'entries' => 0]);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain');
        Schema::dropIfExists('audit_logs');
    }
};
