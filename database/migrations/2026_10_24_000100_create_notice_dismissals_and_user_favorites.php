<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user state kept server-side (works across devices):
 * - notice_dismissals: dismissed "Runde erhalten" / "Bier erhalten" dashboard notices (one row per transaction).
 * - user_favorites: colleagues pinned to the top of the "Runde spendieren" / "Bier verschenken" lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'transaction_id']);
        });

        Schema::create('user_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('favorite_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'favorite_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_favorites');
        Schema::dropIfExists('notice_dismissals');
    }
};
