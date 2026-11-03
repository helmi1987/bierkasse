<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * One-off: existing accounts with an empty staff note get "first last" (same rule as on save).
 */
return new class extends Migration
{
    public function up(): void
    {
        // No tenant scope: this runs before the tenant columns exist (1.26.0).
        User::query()->withoutGlobalScopes()->where(fn ($q) => $q->whereNotNull('first_name')->orWhereNotNull('last_name'))
            ->each(function (User $user) {
                if (trim((string) $user->staff_note) === '') {
                    $user->applyNameToStaffNote();
                    $user->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        // Notes may have been edited since; nothing to undo.
    }
};
