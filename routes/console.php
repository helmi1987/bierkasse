<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

// Break-glass helpers (run via SSH).
Artisan::command('user:totp-reset {username}', function (string $username) {
    $user = User::query()->where('username', $username)->first();
    if (! $user) {
        $this->error("User {$username} not found.");

        return 1;
    }
    $user->disableTotp();
    $this->info("TOTP removed for {$username}.");

    return 0;
})->purpose('Remove TOTP two-factor from a user (e.g. lost phone)');

Artisan::command('user:password {username} {--generate : Generate a random 32 character password}', function (string $username) {
    $user = User::query()->where('username', $username)->first();
    if (! $user) {
        $this->error("User {$username} not found.");

        return 1;
    }

    if ($this->option('generate')) {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!#$%&*+-=?@_';
        $password = '';
        for ($i = 0; $i < 32; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
    } else {
        $password = (string) $this->secret('New password (min. 8 characters)');
        if (strlen($password) < 8 || $password !== (string) $this->secret('Repeat')) {
            $this->error('Too short or not identical.');

            return 1;
        }
    }

    $user->forceFill(['password' => $password, 'must_change_password' => false])->save();
    $this->info("Password changed for {$username}.");
    if ($this->option('generate')) {
        $this->line($password);
    }

    return 0;
})->purpose('Set a local user password');

Artisan::command('user:admin {username}', function (string $username) {
    $user = User::query()->where('username', $username)->first();
    if (! $user) {
        $this->error("User {$username} not found.");

        return 1;
    }
    $user->forceFill(['role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE])->save();
    $this->info("{$username} is now an active admin.");

    return 0;
})->purpose('Make a user an active admin (break-glass)');
