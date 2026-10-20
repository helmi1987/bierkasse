<?php

namespace App\Support;

use App\Models\Setting;
use Throwable;

/**
 * Typed access to the settings managed in the backend (all stored encrypted, see Setting).
 */
class AppSettings
{
    public const BEER_PRICE = 'beer_price';

    public const DEFAULT_LIMIT = 'default_limit';

    public const APP_NAME = 'app_name';

    public const TWINT_PHONE = 'twint_phone';

    public const TWINT_NAME = 'twint_name';

    public const REGISTRATION_ENABLED = 'registration_enabled';

    public const REGISTRATION_PASSWORD = 'registration_password';

    public const NOTIFY_KASSIER = 'notify_kassier';

    public const PERSONAL_SSO = 'personal_sso_enabled';

    public const MAIL = 'mail';

    public const MAIL_TRANSPORTS = ['sendmail', 'smtp'];

    public const MAIL_ENCRYPTIONS = ['', 'tls', 'ssl'];

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            return Setting::get($key, $default);
        } catch (Throwable) {
            // Not installed yet / database unreachable.
            return $default;
        }
    }

    public static function beerPrice(): int
    {
        return max(0, (int) self::get(self::BEER_PRICE, 150));
    }

    public static function defaultLimit(): int
    {
        return max(0, (int) self::get(self::DEFAULT_LIMIT, 0));
    }

    public static function appName(): string
    {
        $name = trim((string) self::get(self::APP_NAME, ''));

        return $name !== '' ? $name : (string) config('app.name', 'Bierkasse');
    }

    public static function twintPhone(): string
    {
        return trim((string) self::get(self::TWINT_PHONE, ''));
    }

    public static function twintName(): string
    {
        return trim((string) self::get(self::TWINT_NAME, ''));
    }

    public static function registrationPassword(): string
    {
        return (string) self::get(self::REGISTRATION_PASSWORD, '');
    }

    /** Registration is possible when switched on and a registration password is set. */
    public static function registrationOpen(): bool
    {
        return (bool) self::get(self::REGISTRATION_ENABLED, true) && self::registrationPassword() !== '';
    }

    /** Users may add their own OAuth2/OIDC provider (default on). */
    public static function personalSsoAllowed(): bool
    {
        return (bool) self::get(self::PERSONAL_SSO, true);
    }

    public static function notifyKassier(): bool
    {
        return (bool) self::get(self::NOTIFY_KASSIER, true);
    }

    /**
     * @return array{transport:string,from_address:string,from_name:string,smtp_host:string,smtp_port:?int,smtp_user:string,smtp_password:string,smtp_encryption:string}
     */
    public static function mail(): array
    {
        $c = (array) self::get(self::MAIL, []);
        $port = $c['smtp_port'] ?? null;
        $encryption = (string) ($c['smtp_encryption'] ?? '');
        $transport = (string) ($c['transport'] ?? '');

        return [
            'transport' => in_array($transport, self::MAIL_TRANSPORTS, true) ? $transport : 'sendmail',
            'from_address' => trim((string) ($c['from_address'] ?? '')),
            'from_name' => trim((string) ($c['from_name'] ?? '')),
            'smtp_host' => trim((string) ($c['smtp_host'] ?? '')),
            'smtp_port' => is_numeric($port) ? (int) $port : null,
            'smtp_user' => (string) ($c['smtp_user'] ?? ''),
            'smtp_password' => (string) ($c['smtp_password'] ?? ''),
            'smtp_encryption' => in_array($encryption, self::MAIL_ENCRYPTIONS, true) ? $encryption : '',
        ];
    }
}
