<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Origin of a guest sale: null = public guest page or booked by a user (booked_by), 'cashbox' = cashbox QR sticker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_sales', function (Blueprint $table) {
            $table->string('source', 16)->nullable()->after('booked_by');
        });
    }

    public function down(): void
    {
        Schema::table('guest_sales', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
