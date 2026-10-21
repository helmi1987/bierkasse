<?php

use App\Models\Setting;
use App\Support\AppSettings;
use Illuminate\Database\Migrations\Migration;

/**
 * Replaces the old registration on/off switch by the registration mode:
 * off -> closed, on -> password. The switch defaulted to on, so an existing install
 * without a stored switch becomes "password" as well. A fresh install (no settings
 * yet) keeps the new default ("invite").
 */
return new class extends Migration
{
    public function up(): void
    {
        Setting::flushCache();

        if (! Setting::query()->exists() || Setting::query()->whereKey(AppSettings::REGISTRATION_MODE)->exists()) {
            Setting::flushCache();

            return;
        }

        $enabled = (bool) Setting::get(AppSettings::LEGACY_REGISTRATION_ENABLED, true);
        Setting::put(AppSettings::REGISTRATION_MODE, $enabled ? AppSettings::MODE_PASSWORD : AppSettings::MODE_CLOSED);
        Setting::forget(AppSettings::LEGACY_REGISTRATION_ENABLED);
        Setting::flushCache();
    }

    public function down(): void
    {
        Setting::flushCache();

        if (Setting::query()->whereKey(AppSettings::REGISTRATION_MODE)->exists()) {
            $mode = Setting::get(AppSettings::REGISTRATION_MODE);
            Setting::put(AppSettings::LEGACY_REGISTRATION_ENABLED, $mode !== AppSettings::MODE_CLOSED);
            Setting::forget(AppSettings::REGISTRATION_MODE);
        }
        Setting::flushCache();
    }
};
