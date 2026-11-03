<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Services\Tenancy;
use App\Services\TenantRequiredException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned model: queries are limited to the current tenant (TenantScope) and new rows get the
 * current tenant's id. tenant_id is never mass assignable; cross-tenant queries use withoutTenant().
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if ($model->getAttribute('tenant_id') === null) {
                $tenantId = Tenancy::currentId();
                if ($tenantId === null) {
                    throw new TenantRequiredException('No current tenant while creating '.class_basename($model).'.');
                }
                $model->setAttribute('tenant_id', $tenantId);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * All tenants (no tenant scope), e.g. Model::withoutTenant()->where(...).
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithoutTenant(Builder $query): void
    {
        $query->withoutGlobalScope(TenantScope::class);
    }

    /**
     * Rows of one tenant regardless of the current tenant.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForTenant(Builder $query, Tenant|int $tenant): void
    {
        $query->withoutGlobalScope(TenantScope::class)
            ->where($this->qualifyColumn('tenant_id'), $tenant instanceof Tenant ? $tenant->id : $tenant);
    }

    /** The row belongs to another tenant than the current one. */
    public function isForeignTenant(): bool
    {
        return $this->tenant_id !== null && (int) $this->tenant_id !== Tenancy::currentId();
    }
}
