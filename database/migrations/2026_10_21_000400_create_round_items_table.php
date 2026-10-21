<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Runde spendieren": the giver pays a transaction of type "round"; its beers are listed here.
 * One row per named colleague (quantity 1) and one row without user for beers without a name
 * (e.g. guests). Plus the per-user switch for the mail to recipients.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('round_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            // Recipient; null = beers without a name (counted as "Gäste (eingeladen)").
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('round_mail')->default(true)->after('stats_opt_out');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('round_mail');
        });
        Schema::dropIfExists('round_items');
    }
};
