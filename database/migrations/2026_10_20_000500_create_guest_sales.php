<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beers sold to guests via the guest QR code (no user account). Count as income
 * in the cash book, reduce the stock and appear as "Gäste" in the statistics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_sales', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->nullable();
            $table->unsignedInteger('beers');
            // Beer price at the moment of the sale.
            $table->integer('unit_price');
            // Positive income in Rappen (beers * unit_price).
            $table->integer('amount');
            // cash | twint
            $table->string('method', 8);
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_sales');
    }
};
