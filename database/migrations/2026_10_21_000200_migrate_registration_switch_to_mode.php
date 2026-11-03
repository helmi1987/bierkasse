<?php

use App\Support\AppSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the old registration on/off switch by the registration mode:
 * off -> closed, on -> password. The switch defaulted to on, so an existing install
 * without a stored switch becomes "password" as well. A fresh install (no settings
 * yet) keeps the new default ("invite").
 *
 * Query builder only: the settings table changed its layout in 1.26.0 (tenants) and the Setting
 * model expects the new one. Values are JSON, encrypted with APP_KEY.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('settings')->exists() || $this->rows()->where('key', AppSettings::REGISTRATION_MODE)->exists()) {
            return;
        }
        $enabled = (bool) $this->read(AppSettings::LEGACY_REGISTRATION_ENABLED, true);
        $this->write(AppSettings::REGISTRATION_MODE, $enabled ? AppSettings::MODE_PASSWORD : AppSettings::MODE_CLOSED);
        $this->rows()->where('key', AppSettings::LEGACY_REGISTRATION_ENABLED)->delete();
    }

    public function down(): void
    {
        if ($this->rows()->where('key', AppSettings::REGISTRATION_MODE)->exists()) {
            $mode = $this->read(AppSettings::REGISTRATION_MODE, AppSettings::MODE_INVITE);
            $this->write(AppSettings::LEGACY_REGISTRATION_ENABLED, $mode !== AppSettings::MODE_CLOSED);
            $this->rows()->where('key', AppSettings::REGISTRATION_MODE)->delete();
        }
    }

    /** Settings rows; of the oldest tenant once the table has tenant_id (1.26.0, e.g. when run again in tests). */
    private function rows(): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('settings');
        if (Schema::hasColumn('settings', 'tenant_id')) {
            $query->where('tenant_id', $this->tenantId());
        }

        return $query;
    }

    private function tenantId(): ?int
    {
        return Schema::hasColumn('settings', 'tenant_id') ? (int) DB::table('tenants')->min('id') : null;
    }

    private function read(string $key, mixed $default): mixed
    {
        $raw = $this->rows()->where('key', $key)->value('value');
        try {
            return $raw === null ? $default : json_decode(Crypt::decryptString((string) $raw), true);
        } catch (Throwable) {
            return $default;
        }
    }

    private function write(string $key, mixed $value): void
    {
        $where = ['key' => $key] + (Schema::hasColumn('settings', 'tenant_id') ? ['tenant_id' => $this->tenantId()] : []);
        DB::table('settings')->updateOrInsert($where, [
            'value' => Crypt::encryptString(json_encode($value)), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};
