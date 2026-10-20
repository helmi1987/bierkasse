<?php

namespace App\Services;

use App\Support\AppSettings;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;

/**
 * Applies the mail settings from the backend (sendmail or SMTP) to the default mailer.
 * Mails are sent synchronously; no queue worker is needed.
 */
class MailSettings
{
    public const MAILER = 'app';

    public static function apply(): void
    {
        // Tests use the array mailer from phpunit.xml.
        if (app()->runningUnitTests()) {
            return;
        }

        $c = AppSettings::mail();
        config([
            'mail.mailers.'.self::MAILER => self::mailerConfig($c),
            'mail.default' => self::MAILER,
            'mail.from.address' => $c['from_address'] !== '' ? $c['from_address'] : self::defaultSender(),
            'mail.from.name' => $c['from_name'] !== '' ? $c['from_name'] : AppSettings::appName(),
        ]);

        $manager = Mail::getFacadeRoot();
        if ($manager instanceof MailManager) {
            $manager->purge(self::MAILER);
        }
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    public static function mailerConfig(array $c): array
    {
        if ($c['transport'] !== 'smtp' || $c['smtp_host'] === '') {
            return ['transport' => 'sendmail', 'path' => (string) env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -t -i')];
        }

        return array_filter([
            'transport' => 'smtp',
            'scheme' => $c['smtp_encryption'] === 'ssl' ? 'smtps' : 'smtp',
            'host' => $c['smtp_host'],
            'port' => $c['smtp_port'] ?? ($c['smtp_encryption'] === 'ssl' ? 465 : 587),
            'username' => $c['smtp_user'] !== '' ? $c['smtp_user'] : null,
            'password' => $c['smtp_password'] !== '' ? $c['smtp_password'] : null,
            'auto_tls' => $c['smtp_encryption'] === '' ? false : null,
            'require_tls' => $c['smtp_encryption'] === 'tls' ? true : null,
            'timeout' => 15,
        ], fn ($v) => $v !== null);
    }

    /** no-reply@<host of APP_URL>. */
    public static function defaultSender(): string
    {
        $host = (string) preg_replace('~^www\.~i', '', (string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return 'no-reply@'.($host !== '' ? $host : 'localhost');
    }
}
