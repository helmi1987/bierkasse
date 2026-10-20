<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * Shared validation rules for user accounts.
 */
class UserRules
{
    public const USERNAME_PATTERN = '/^[a-z0-9._-]{3,32}$/';

    public const PASSWORD_MIN = 8;

    /**
     * @return list<mixed>
     */
    public static function username(?int $ignoreId = null): array
    {
        return ['required', 'string', 'min:3', 'max:32', 'regex:'.self::USERNAME_PATTERN, Rule::unique('users', 'username')->ignore($ignoreId)];
    }

    /**
     * @return list<mixed>
     */
    public static function password(): array
    {
        return ['required', 'string', 'min:'.self::PASSWORD_MIN, 'max:1024', 'confirmed'];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function profile(?int $ignoreId = null): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            // Unique, because the password reset identifies the account by its address.
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignoreId)],
        ];
    }
}
