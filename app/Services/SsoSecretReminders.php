<?php

namespace App\Services;

use App\Mail\KassierNotice;
use App\Models\SsoProvider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Client-secret expiry reminders for global SSO providers (daily via the scheduler, `sso:secret-reminders`).
 *
 * Mails all active admins with an address at the stages of SsoProvider::SECRET_REMINDER_STAGES, each stage once.
 * A stage is only marked as sent when at least one mail went out (no recipients or all failed: retried next run).
 */
class SsoSecretReminders
{
    /**
     * @return list<array{slug: string, stage: int, days_left: int, recipients: int}>
     */
    public static function run(bool $dryRun = false): array
    {
        $results = [];
        $recipients = null;

        foreach (SsoProvider::query()->withSecretExpiry()->orderBy('client_secret_expires_at')->orderBy('id')->get() as $provider) {
            $stage = $provider->dueSecretReminderStage();
            if ($stage === null) {
                continue;
            }
            $daysLeft = (int) $provider->secretDaysLeft();
            $recipients ??= self::recipients();

            if ($dryRun) {
                $results[] = ['slug' => $provider->slug, 'stage' => $stage, 'days_left' => $daysLeft, 'recipients' => count($recipients)];

                continue;
            }

            if ($recipients === []) {
                Log::warning('SSO secret reminder: no active admin with an e-mail address', ['provider' => $provider->slug]);
                $results[] = ['slug' => $provider->slug, 'stage' => $stage, 'days_left' => $daysLeft, 'recipients' => 0];

                continue;
            }

            $sent = self::send($provider, $daysLeft, $recipients);
            if ($sent > 0) {
                DB::transaction(function () use ($provider, $stage, $daysLeft, $sent) {
                    $provider->forceFill(['secret_reminder_stage' => $stage])->save();
                    Audit::log('sso_provider.secret_reminder', $provider, [], ['stage' => $stage, 'days_left' => $daysLeft, 'recipients' => $sent], null, 'cli');
                });
            }
            $results[] = ['slug' => $provider->slug, 'stage' => $stage, 'days_left' => $daysLeft, 'recipients' => $sent];
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private static function recipients(): array
    {
        return User::query()
            ->where('role', User::ROLE_ADMIN)
            ->where('status', User::STATUS_ACTIVE)
            ->whereNotNull('email')->where('email', '!=', '')
            ->pluck('email')->unique()->values()->all();
    }

    /**
     * @param  list<string>  $recipients
     */
    private static function send(SsoProvider $provider, int $daysLeft, array $recipients): int
    {
        $name = $provider->name;
        $date = $provider->client_secret_expires_at->format('d.m.Y');

        $subject = match (true) {
            $daysLeft < 0 => __('mail.sso_secret_subject_expired', ['name' => $name]),
            $daysLeft === 0 => __('mail.sso_secret_subject_today', ['name' => $name]),
            default => __('mail.sso_secret_subject', ['name' => $name, 'days' => $daysLeft]),
        };
        $lines = [__($daysLeft < 0 ? 'mail.sso_secret_line_expired' : 'mail.sso_secret_line', ['name' => $name, 'date' => $date])];
        if ($provider->type === 'microsoft') {
            $lines[] = __('mail.sso_secret_line_microsoft');
        }
        $rows = [
            __('mail.sso_secret_provider') => $name,
            __('mail.sso_secret_type') => __('sso.types.'.$provider->type),
            __('mail.sso_secret_expires') => $date,
            __('mail.sso_secret_left') => match (true) {
                $daysLeft < 0 => __('mail.sso_secret_left_expired'),
                $daysLeft === 0 => __('mail.sso_secret_left_today'),
                default => __('mail.sso_secret_left_days', ['days' => $daysLeft]),
            },
        ];
        $url = route('backend.sso.edit', $provider);

        MailSettings::apply();
        $sent = 0;
        foreach ($recipients as $email) {
            try {
                Mail::to($email)->send(new KassierNotice($subject, $lines, $url, __('mail.button_sso'), $rows, null, [__('mail.sso_secret_outro')]));
                $sent++;
            } catch (Throwable $e) {
                Log::warning('SSO secret reminder failed', ['to' => $email, 'provider' => $provider->slug, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }
}
