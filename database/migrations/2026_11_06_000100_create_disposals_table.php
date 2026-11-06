<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Entsorgungsbonus" (1.32.0): a user (or a kassier/admin for a user) reports that the empties were taken
 * out. After the withdrawal window the bonus (fixed or random number of beers) is drawn and booked as a
 * credit transaction of type "disposal". The quantity is only set when booked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disposals', function (Blueprint $table) {
            $table->id();
            // Recipient of the bonus.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The recipient or a kassier/admin (nullable only so deleting an account keeps the row).
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reported_at');
            // reported | withdrawn | booked
            $table->string('status', 16)->default('reported');
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('withdrawn_at')->nullable();
            $table->dateTime('booked_at')->nullable();
            // Drawn at booking time; never known before.
            $table->unsignedSmallInteger('quantity')->nullable();
            // Credit row; null again after a storno (the row is deleted).
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            // Set when a kassier/admin overrode the lock or the minimum.
            $table->string('override_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['status', 'reported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disposals');
    }
};
