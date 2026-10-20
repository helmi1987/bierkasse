<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who booked a guest sale on the dashboard; null = public guest page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_sales', function (Blueprint $table) {
            $table->foreignId('booked_by')->nullable()->after('method')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('guest_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booked_by');
        });
    }
};
