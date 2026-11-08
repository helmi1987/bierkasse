<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stored print of the offline emergency plan (1.40.0, see App\Services\EmergencyPlan): when, by whom and
 * which users were on the printed list.
 */
class EmergencyPlanPrint extends Model
{
    protected $fillable = ['printed_by', 'printed_at', 'user_ids', 'user_count'];

    protected function casts(): array
    {
        return [
            'printed_by' => 'integer',
            'printed_at' => 'datetime',
            'user_ids' => 'array',
            'user_count' => 'integer',
        ];
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }

    /**
     * IDs of the users on the printed list.
     *
     * @return list<int>
     */
    public function userIds(): array
    {
        return array_values(array_map('intval', (array) $this->user_ids));
    }

    /** Latest stored print (null = never printed). */
    public static function latestPrint(): ?self
    {
        return static::query()->latest('printed_at')->latest('id')->first();
    }
}
