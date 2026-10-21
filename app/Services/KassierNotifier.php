<?php

namespace App\Services;

use App\Mail\KassierNotice;
use App\Models\User;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * E-mail to all active kassiers and admins with an address (if enabled in the settings).
 * A failed delivery is logged and never blocks the booking.
 */
class KassierNotifier
{
    /**
     * @param  list<string>  $lines
     * @param  string|null  $url  Link at the end of the mail (default: backend dashboard)
     * @param  string|null  $footer  Text before the link (default: mail.footer)
     */
    public static function send(string $subject, array $lines, ?string $url = null, ?string $footer = null): int
    {
        if (! AppSettings::notifyKassier()) {
            return 0;
        }

        $recipients = User::query()
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_KASSIER])
            ->where('status', User::STATUS_ACTIVE)
            ->whereNotNull('email')->where('email', '!=', '')
            ->pluck('email')->unique()->values();

        if ($recipients->isEmpty()) {
            return 0;
        }

        MailSettings::apply();
        $sent = 0;
        foreach ($recipients as $email) {
            try {
                Mail::to($email)->send(new KassierNotice($subject, $lines, $url, $footer));
                $sent++;
            } catch (Throwable $e) {
                Log::warning('Kassier notification failed', ['to' => $email, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }
}
