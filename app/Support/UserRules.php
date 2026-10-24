<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Shared validation rules for user accounts.
 */
class UserRules
{
    public const USERNAME_PATTERN = '/^[a-z0-9._-]{3,32}$/';

    public const PASSWORD_MIN = 8;

    /** Name used for guest payments (e.g. in the TWINT message); no account may take it. */
    public const GUEST_USERNAME = 'gast';

    /** User names no account may take (compared case-insensitively). */
    public const RESERVED_USERNAMES = [self::GUEST_USERNAME];

    /**
     * @return list<mixed>
     */
    public static function username(?int $ignoreId = null): array
    {
        return ['required', 'string', 'min:3', 'max:32', 'regex:'.self::USERNAME_PATTERN, self::notReserved(), Rule::unique('users', 'username')->ignore($ignoreId)];
    }

    /** Rejects the reserved user names (RESERVED_USERNAMES), regardless of case. */
    public static function notReserved(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (in_array(Str::lower(trim((string) $value)), self::RESERVED_USERNAMES, true)) {
                $fail(__('validation.username_reserved'));
            }
        };
    }

    /**
     * @return list<mixed>
     */
    public static function password(): array
    {
        return ['required', 'string', 'min:'.self::PASSWORD_MIN, 'max:1024', 'confirmed'];
    }

    /**
     * @param  bool  $emailRequired  Kassiers and admins need an e-mail address.
     * @return array<string, list<mixed>>
     */
    public static function profile(?int $ignoreId = null, bool $emailRequired = false): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            // Unique, because the password reset identifies the account by its address.
            'email' => [$emailRequired ? 'required' : 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignoreId)],
        ];
    }
}
