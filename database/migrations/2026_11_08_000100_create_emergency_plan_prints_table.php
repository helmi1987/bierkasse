<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline emergency plan (1.40.0): every real print ("Notfallplan drucken") is stored with the time, the admin
 * and the users on the printed list, so the admin page can show who joined or left since the last print.
 * Test prints are not stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_plan_prints', function (Blueprint $table) {
            $table->id();
            // Nullable only so deleting an account keeps the row.
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('printed_at');
            // IDs of the users on the printed list (JSON list of integers).
            $table->json('user_ids');
            $table->unsignedInteger('user_count');
            $table->timestamps();
            $table->index('printed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_plan_prints');
    }
};
