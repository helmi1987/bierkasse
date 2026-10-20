<?php

namespace App\Providers;

use App\Services\Ledger;
use App\Support\AppSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Loaded here as well so a stale Composer autoloader cannot break the views.
        require_once app_path('helpers.php');
    }

    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $url = route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()]);

            return (new MailMessage)
                ->subject(__('passwords.mail_subject', ['app' => AppSettings::appName()]))
                ->greeting(__('passwords.mail_greeting', ['name' => $notifiable->displayName()]))
                ->line(__('passwords.mail_intro'))
                ->action(__('passwords.mail_action'), $url)
                ->line(__('passwords.mail_expire', ['count' => config('auth.passwords.users.expire')]))
                ->line(__('passwords.mail_ignore'))
                ->salutation(AppSettings::appName());
        });

        // Header: app name and the open items badge for kassiers/admins.
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();
            $open = 0;
            if ($user && $user->isKassier()) {
                try {
                    $open = Ledger::pendingCount();
                } catch (Throwable) {
                    $open = 0;
                }
            }
            $view->with('appName', AppSettings::appName())->with('openItems', $open);
        });
    }
}
