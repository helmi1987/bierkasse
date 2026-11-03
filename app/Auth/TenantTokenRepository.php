<?php

namespace App\Auth;

use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Support\Str;

/**
 * Password reset tokens keyed by tenant and e-mail ("<tenant id>|<e-mail>" in the email column): the same
 * address in two tenants gets two independent tokens (and is not throttled by the other one).
 */
class TenantTokenRepository extends DatabaseTokenRepository
{
    public function create(CanResetPasswordContract $user)
    {
        $key = $this->key($user);
        $this->deleteExisting($user);
        $token = $this->createNewToken();
        $this->getTable()->insert($this->getPayload($key, $token));

        return $token;
    }

    protected function deleteExisting(CanResetPasswordContract $user)
    {
        return $this->getTable()->where('email', $this->key($user))->delete();
    }

    public function exists(CanResetPasswordContract $user, #[\SensitiveParameter] $token)
    {
        $record = (array) $this->getTable()->where('email', $this->key($user))->first();

        return $record && ! $this->tokenExpired($record['created_at']) && $this->hasher->check($token, $record['token']);
    }

    public function recentlyCreatedToken(CanResetPasswordContract $user)
    {
        $record = (array) $this->getTable()->where('email', $this->key($user))->first();

        return $record && $this->tokenRecentlyCreated($record['created_at']);
    }

    /** Storage key of a user's token. */
    private function key(CanResetPasswordContract $user): string
    {
        $tenantId = $user->getAttribute('tenant_id');

        return Str::limit(($tenantId === null ? '' : $tenantId.'|').$user->getEmailForPasswordReset(), 255, '');
    }
}
