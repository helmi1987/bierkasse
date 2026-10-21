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

    /** Old single TWINT number/name, replaced by TWINT_RECIPIENTS (still read as fallback). */
    public const TWINT_PHONE = 'twint_phone';

    public const TWINT_NAME = 'twint_name';

    /** TWINT recipients (kassiers): list of ['name' => string, 'phone' => string], at least one of both set. */
    public const TWINT_RECIPIENTS = 'twint_recipients';

    public const TWINT_MAX = 10;

    /** TWINT on/off; unset = on when a number is configured (installs before the switch). */
    public const TWINT_ENABLED = 'twint_enabled';

    /** Old on/off switch, replaced by REGISTRATION_MODE (only read by the migration). */
    public const LEGACY_REGISTRATION_ENABLED = 'registration_enabled';

    /** Registration mode, one of REGISTRATION_MODES. */
    public const REGISTRATION_MODE = 'registration_mode';

    /** No registration at all (invitation links are invalid too). */
    public const MODE_CLOSED = 'closed';

    /** Registration only with a valid invitation link. */
    public const MODE_INVITE = 'invite';

    /** Registration with the registration password; invitation links work too. */
    public const MODE_PASSWORD = 'password';

    public const REGISTRATION_MODES = [self::MODE_CLOSED, self::MODE_INVITE, self::MODE_PASSWORD];

    public const REGISTRATION_PASSWORD = 'registration_password';

    /** Random code in the flyer/backend registration QR (replaces the password there). */
    public const FLYER_CODE = 'registration_flyer_code';

    public const NOTIFY_KASSIER = 'notify_kassier';

    public const PERSONAL_SSO = 'personal_sso_enabled';

    public const DEPOSIT_SUGGESTIONS = 'deposit_suggestions';

    public const STOCK_MINIMUM = 'stock_minimum';

    public const GUEST_ENABLED = 'guest_enabled';

    public const GUEST_PASSWORD = 'guest_password';

    /** Logged-in users may book beers for guests on their dashboard. */
    public const GUEST_USER_BOOKING = 'guest_user_booking';

    public const STOCK_LOW_WARNED = 'stock_low_warned';

    /** Users may pay a round for colleagues/guests ("Runde spendieren"). Planned to move to the tenant settings. */
    public const ROUNDS_ENABLED = 'rounds_enabled';

    /** Preselected deposit proposal in Rappen; null = first proposal. */
    public const DEPOSIT_DEFAULT = 'deposit_suggestion_default';

    public const MAIL = 'mail';

    /** Maintenance mode for users: only admins can use the app (see MaintenanceMode middleware). */
    public const MAINTENANCE = 'maintenance_mode';

    public const MAINTENANCE_MESSAGE = 'maintenance_message';

    /** Who gets the feedback mails, one of FEEDBACK_RECIPIENT_MODES. */
    public const FEEDBACK_RECIPIENT_MODE = 'feedback_recipient_mode';

    /** Fixed feedback recipients (list of e-mail addresses, used in mode "fixed"). */
    public const FEEDBACK_RECIPIENTS = 'feedback_recipients';

    /** All active admins with an e-mail address. */
    public const FEEDBACK_TO_ADMINS = 'admins';

    /** The fixed addresses in FEEDBACK_RECIPIENTS. */
    public const FEEDBACK_TO_FIXED = 'fixed';

    public const FEEDBACK_RECIPIENT_MODES = [self::FEEDBACK_TO_ADMINS, self::FEEDBACK_TO_FIXED];

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

    /**
     * Configured TWINT recipients; falls back to the old single number/name.
     *
     * @return list<array{name: string, phone: string}>
     */
    public static function twintRecipients(): array
    {
        $stored = self::get(self::TWINT_RECIPIENTS);
        $list = is_array($stored) ? $stored : [[
            'name' => (string) self::get(self::TWINT_NAME, ''),
            'phone' => (string) self::get(self::TWINT_PHONE, ''),
        ]];

        $recipients = [];
        foreach ($list as $r) {
            $r = ['name' => trim((string) ($r['name'] ?? '')), 'phone' => trim((string) ($r['phone'] ?? ''))];
            if ($r['name'] !== '' || $r['phone'] !== '') {
                $recipients[] = $r;
            }
        }

        return array_slice($recipients, 0, self::TWINT_MAX);
    }

    /** TWINT switch as stored; unset = on when a number is configured (installs before the switch). */
    public static function twintSwitch(): bool
    {
        $hasPhone = array_filter(self::twintRecipients(), fn (array $r) => $r['phone'] !== '') !== [];

        return (bool) self::get(self::TWINT_ENABLED, $hasPhone);
    }

    /** TWINT is offered: switched on and at least one recipient is configured. */
    public static function twintEnabled(): bool
    {
        return self::twintSwitch() && self::twintRecipients() !== [];
    }

    /**
     * Recipients for a sentence ("an A, B oder C"): the name, the number only when no name is set
     * (numbers are offered by copy buttons). With $html each one is escaped and wrapped in <strong>.
     */
    public static function twintRecipientText(bool $html = false): string
    {
        $parts = array_map(fn (array $r) => $r['name'] !== '' ? $r['name'] : $r['phone'], self::twintRecipients());
        if ($html) {
            $parts = array_map(fn (string $p) => '<strong>'.e($p).'</strong>', $parts);
        }

        // "A, B oder C": commas, "oder" only before the last one.
        $last = array_pop($parts);

        return $parts === [] ? (string) $last : implode(', ', $parts).' '.__('account.twint_or').' '.$last;
    }

    public static function registrationPassword(): string
    {
        return (string) self::get(self::REGISTRATION_PASSWORD, '');
    }

    /** Flyer code; generated on first use (random, URL-safe, 32 characters). */
    public static function flyerCode(): string
    {
        $code = (string) self::get(self::FLYER_CODE, '');

        return $code !== '' ? $code : self::regenerateFlyerCode();
    }

    /** New flyer code; QR codes printed with the old one stop working. */
    public static function regenerateFlyerCode(): string
    {
        $code = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        Setting::put(self::FLYER_CODE, $code);

        return $code;
    }

    /** Valid flyer code: only in mode "password" (open registration); never generates one. */
    public static function flyerCodeValid(string $code): bool
    {
        $stored = (string) self::get(self::FLYER_CODE, '');

        return self::registrationOpen() && $stored !== '' && $code !== '' && hash_equals($stored, $code);
    }

    /** Registration mode; default for new installs: invitation only. */
    public static function registrationMode(): string
    {
        $mode = self::get(self::REGISTRATION_MODE, self::MODE_INVITE);

        return in_array($mode, self::REGISTRATION_MODES, true) ? $mode : self::MODE_INVITE;
    }

    /** Invitation links can be created and used (every mode except "closed"). */
    public static function invitationsAllowed(): bool
    {
        return self::registrationMode() !== self::MODE_CLOSED;
    }

    /** Registration with the registration password: mode "password" and a password is set. */
    public static function registrationOpen(): bool
    {
        return self::registrationMode() === self::MODE_PASSWORD && self::registrationPassword() !== '';
    }

    /**
     * Configured deposit proposals in Rappen; null = automatic (see DepositSuggestions).
     *
     * @return list<int>|null
     */
    public static function depositSuggestions(): ?array
    {
        $list = array_values(array_filter(array_map('intval', (array) self::get(self::DEPOSIT_SUGGESTIONS, [])), fn (int $a) => $a > 0));

        return $list === [] ? null : $list;
    }

    /** Preselected deposit proposal (amount without debt) in Rappen; null = first proposal. */
    public static function depositDefault(): ?int
    {
        $amount = (int) self::get(self::DEPOSIT_DEFAULT, 0);

        return $amount > 0 ? $amount : null;
    }

    public static function guestPassword(): string
    {
        return (string) self::get(self::GUEST_PASSWORD, '');
    }

    /** Guest sales are possible when switched on and a guest password is set. */
    public static function guestOpen(): bool
    {
        return (bool) self::get(self::GUEST_ENABLED, false) && self::guestPassword() !== '';
    }

    /** Logged-in users may book beers for guests (default on, independent of the public guest page). */
    public static function guestUserBooking(): bool
    {
        return (bool) self::get(self::GUEST_USER_BOOKING, true);
    }

    /** "Runde spendieren" is allowed (default on). */
    public static function roundsAllowed(): bool
    {
        return (bool) self::get(self::ROUNDS_ENABLED, true);
    }

    /** Minimum beer stock (low-stock mail and warning); 0 = off. */
    public static function stockMinimum(): int
    {
        return max(0, (int) self::get(self::STOCK_MINIMUM, 20));
    }

    /** A low-stock mail was sent and the stock has not been back at the minimum since. */
    public static function stockLowWarned(): bool
    {
        return (bool) self::get(self::STOCK_LOW_WARNED, false);
    }

    /** Users may add their own OAuth2/OIDC provider (default on). */
    public static function personalSsoAllowed(): bool
    {
        return (bool) self::get(self::PERSONAL_SSO, true);
    }

    public static function maintenanceMode(): bool
    {
        return (bool) self::get(self::MAINTENANCE, false);
    }

    public static function maintenanceMessage(): string
    {
        return trim((string) self::get(self::MAINTENANCE_MESSAGE, ''));
    }

    /** Feedback recipient mode; default: all admins. */
    public static function feedbackRecipientMode(): string
    {
        $mode = self::get(self::FEEDBACK_RECIPIENT_MODE, self::FEEDBACK_TO_ADMINS);

        return in_array($mode, self::FEEDBACK_RECIPIENT_MODES, true) ? $mode : self::FEEDBACK_TO_ADMINS;
    }

    /**
     * Stored fixed feedback recipients.
     *
     * @return list<string>
     */
    public static function feedbackRecipients(): array
    {
        return array_values(array_filter(array_map(
            fn ($a) => trim((string) $a),
            (array) self::get(self::FEEDBACK_RECIPIENTS, []),
        ), fn (string $a) => $a !== ''));
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
