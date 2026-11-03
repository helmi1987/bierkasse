<?php

namespace App\Auth;

use App\Models\Scopes\TenantScope;
use Illuminate\Auth\EloquentUserProvider;

/**
 * User provider of the "web" guard. The session and the "remember me" cookie identify the account by id, so
 * these lookups ignore the tenant scope (a superadmin may have switched to another tenant, and the tenant
 * context is derived from the user, not the other way round). Credential lookups (login, password reset)
 * stay within the current tenant.
 */
class TenantUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier)
    {
        return $this->newModelQuery()->withoutGlobalScope(TenantScope::class)
            ->where($this->createModel()->getAuthIdentifierName(), $identifier)->first();
    }

    public function retrieveByToken($identifier, $token)
    {
        $model = $this->createModel();
        $retrievedModel = $this->newModelQuery($model)->withoutGlobalScope(TenantScope::class)
            ->where($model->getAuthIdentifierName(), $identifier)->first();
        if (! $retrievedModel) {
            return null;
        }
        $rememberToken = $retrievedModel->getRememberToken();

        return $rememberToken && hash_equals($rememberToken, $token) ? $retrievedModel : null;
    }
}
