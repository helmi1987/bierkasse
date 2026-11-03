<?php

namespace App\Auth;

use Illuminate\Auth\Passwords\PasswordBrokerManager;

/** Password brokers with tokens per tenant (see TenantTokenRepository). */
class TenantPasswordBrokerManager extends PasswordBrokerManager
{
    protected function createTokenRepository(array $config)
    {
        $key = $this->app['config']['app.key'];
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }
        $connection = $config['connection'] ?? null;

        return new TenantTokenRepository(
            $this->app['db']->connection($connection),
            $this->app['hash'],
            $config['table'],
            $key,
            $config['expire'],
            $config['throttle'] ?? 0
        );
    }
}
