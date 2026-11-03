<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant ("Mandant"): an independent beer fund with its own users, bookings, settings and audit log
 * in the shared database (see App\Services\Tenancy and the BelongsToTenant trait).
 */
class Tenant extends Model
{
    public const SLUG_PATTERN = '/^[a-z0-9]([a-z0-9-]{0,30}[a-z0-9])?$/';

    public const NAME_MAX = 100;

    protected $fillable = ['name', 'slug'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class)->withoutGlobalScopes();
    }

    /** Admins of the tenant (role admin, active), without the global scope. */
    public function admins(): HasMany
    {
        return $this->users()->where('role', User::ROLE_ADMIN)->where('status', User::STATUS_ACTIVE);
    }
}
