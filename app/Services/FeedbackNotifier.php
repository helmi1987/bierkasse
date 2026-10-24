<?php

namespace App\Services;

use App\Mail\KassierNotice;
use App\Models\Feedback;
use App\Models\User;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * E-mail about new feedback to the configured recipients (all active admins with an address, or
 * fixed addresses). Independent of the kassier notification switch. A failed delivery is logged
 * and never blocks the submission (the feedback is stored already).
 */
class FeedbackNotifier
{
    /**
     * Recipients according to the settings.
     *
     * @return list<string>
     */
    public static function recipients(): array
    {
        if (AppSettings::feedbackRecipientMode() === AppSettings::FEEDBACK_TO_FIXED) {
            return self::unique(AppSettings::feedbackRecipients());
        }

        return self::unique(User::query()
            ->where('role', User::ROLE_ADMIN)
            ->where('status', User::STATUS_ACTIVE)
            ->whereNotNull('email')->where('email', '!=', '')
            ->pluck('email')->all());
    }

    /**
     * Splits an address list (one per line, comma or semicolon separated) into trimmed,
     * unique entries; validation happens in the controller.
     *
     * @return list<string>
     */
    public static function parseAddresses(string $input): array
    {
        return self::unique(preg_split('/[\s,;]+/', $input, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    public static function send(Feedback $feedback): int
    {
        try {
            $recipients = self::recipients();
            if ($recipients === []) {
                Log::info('Feedback mail skipped: no recipients', ['feedback' => $feedback->id]);

                return 0;
            }

            $user = $feedback->user;
            $email = (string) ($user?->email ?? '');
            $replyTo = $feedback->wants_reply && $email !== '' ? $email : null;
            $from = $user ? $user->username.($user->displayName() !== $user->username ? ' ('.$user->displayName().')' : '') : '–';

            $rows = [
                __('feedback.mail_type') => __('feedback.types.'.$feedback->type),
                __('feedback.mail_from') => $from,
                __('feedback.mail_email') => $email !== '' ? $email : '–',
                __('feedback.mail_wants_reply') => $feedback->wants_reply ? __('feedback.yes') : __('feedback.no'),
                __('feedback.mail_time') => local_time($feedback->created_at),
            ];
            if ($feedback->app_version !== null) {
                $rows[__('feedback.mail_version')] = $feedback->app_version;
            }

            MailSettings::apply();
        } catch (Throwable $e) {
            Log::warning('Feedback notification failed', ['feedback' => $feedback->id, 'error' => $e->getMessage()]);

            return 0;
        }

        $sent = 0;
        foreach ($recipients as $to) {
            try {
                $mail = new KassierNotice(
                    __('feedback.mail_subject', ['user' => $user?->username ?? '–']),
                    rows: $rows,
                    url: route('backend.feedback'),
                    buttonLabel: __('feedback.mail_button'),
                    title: __('feedback.mail_title'),
                    textBlock: $feedback->message,
                );
                if ($replyTo !== null) {
                    $mail->replyTo($replyTo, $user?->displayName());
                }
                Mail::to($to)->send($mail);
                $sent++;
            } catch (Throwable $e) {
                Log::warning('Feedback notification failed', ['to' => $to, 'feedback' => $feedback->id, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /**
     * @param  array<int, mixed>  $addresses
     * @return list<string>
     */
    private static function unique(array $addresses): array
    {
        $out = [];
        foreach ($addresses as $address) {
            $address = trim((string) $address);
            if ($address !== '' && ! isset($out[mb_strtolower($address)])) {
                $out[mb_strtolower($address)] = $address;
            }
        }

        return array_values($out);
    }
}
