<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QR codes with history (cashbox cash/TWINT, registration flyer, guest page): several can be active,
 * deactivated ones stay and can be reactivated. Code stored encrypted and as SHA-256 hash for the lookup.
 * Moves the former single codes from the settings (printed stickers/flyers keep working).
 */
return new class extends Migration
{
    private const MOVED = [
        'cashbox_qr_cash_token' => 'cashbox_cash',
        'cashbox_qr_twint_token' => 'cashbox_twint',
        'registration_flyer_code' => 'flyer',
    ];

    public function up(): void
    {
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->index();
            $table->string('label', 100)->nullable();
            $table->string('code_hash', 64)->unique();
            $table->text('code');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
        });

        foreach (self::MOVED as $key => $kind) {
            $raw = DB::table('settings')->where('key', $key)->value('value');
            try {
                $code = $raw === null ? null : json_decode(Crypt::decryptString((string) $raw), true);
            } catch (Throwable) {
                $code = null;
            }
            if (is_string($code) && $code !== '') {
                DB::table('qr_codes')->insert([
                    'kind' => $kind,
                    'code_hash' => hash('sha256', $code),
                    'code' => Crypt::encryptString($code),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('settings')->where('key', $key)->delete();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
    }
};
