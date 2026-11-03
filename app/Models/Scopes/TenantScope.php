<?php

namespace App\Models\Scopes;

use App\Services\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query of a tenant-owned model to the current tenant (App\Services\Tenancy).
 * Without a current tenant (multi mode, request without tenant context) nothing matches.
 * Use Model::withoutTenant() for deliberate cross-tenant lookups (login, codes, tenant management).
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = Tenancy::currentId();
        if ($tenantId === null) {
            $builder->whereRaw('0 = 1');

            return;
        }
        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
