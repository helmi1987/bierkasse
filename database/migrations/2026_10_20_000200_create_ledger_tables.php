<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User ledger (transactions) and the cash book (cash_entries). All money in Rappen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // consumption | deposit | payout | donation | correction
            $table->string('type', 16);
            // pending | confirmed | rejected
            $table->string('status', 16)->default('confirmed');
            // cash | twint (deposits and payouts)
            $table->string('method', 8)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            // Beer price at the moment of consumption (never changes afterwards).
            $table->integer('unit_price')->nullable();
            // Signed effect on the user's balance (counts only when confirmed).
            $table->integer('amount');
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['type', 'status']);
        });

        Schema::create('cash_entries', function (Blueprint $table) {
            $table->id();
            // expense_beer | expense_other | income | adjustment
            $table->string('type', 16);
            $table->date('date');
            // Signed effect on the cash box: expenses negative, income positive.
            $table->integer('amount');
            // cash | twint
            $table->string('method', 8)->default('cash');
            $table->string('note', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_entries');
        Schema::dropIfExists('transactions');
    }
};
