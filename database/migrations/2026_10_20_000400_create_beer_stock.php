<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beer stock: number of beers per purchase (cash book, null for older rows)
 * and inventory corrections (delta to the counted stock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_entries', function (Blueprint $table) {
            $table->unsignedInteger('beers')->nullable()->after('amount');
        });

        Schema::create('stock_corrections', function (Blueprint $table) {
            $table->id();
            // Signed change of the stock; counted = stock after the correction.
            $table->integer('delta');
            $table->integer('counted');
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_corrections');
        Schema::table('cash_entries', function (Blueprint $table) {
            $table->dropColumn('beers');
        });
    }
};
