<?php

namespace App\Providers;

use App\Auth\TenantUserProvider;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\RequireRole;
use App\Services\Ledger;
use App\Services\LegalArchive;
use App\Services\Tenancy;
use App\Support\AppSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Loaded here as well so a stale Composer autoloader cannot break the views.
        require_once app_path('helpers.php');
        // Newest legal text revisions, cached per request.
        $this->app->scoped(LegalArchive::class);
    }

    public function boot(): void
    {
        Auth::provider('tenant-eloquent', fn ($app, array $config) => new TenantUserProvider($app['hash'], $config['model']));

        // Livewire updates re-run these middleware of the page the component was rendered on (in addition to the
        // admin middleware of the update route in routes/web.php): consent/active gate and role.
        Livewire::addPersistentMiddleware([EnsureActiveUser::class, RequireRole::class]);

        // Links in mails (password reset) must not depend on the Host header of the request:
        // once installed, the root URL is always APP_URL (set by the setup wizard).
        $appUrl = rtrim((string) config('app.url'), '/');
        if (config('app.installed') && $appUrl !== '') {
            // Multi mode: a request on a tenant sub-domain (<slug>.<host of APP_URL>) keeps that host in its links
            // (the session cookie belongs to that host); everything else uses APP_URL.
            $host = $this->app->runningInConsole() ? null : request()->getHost();
            $tenantHost = $host !== null && Tenancy::isMulti() && Tenancy::fromHost($host) !== null;
            URL::forceRootUrl($tenantHost ? request()->getScheme().'://'.request()->getHttpHost() : $appUrl);
        }
        if (str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }

        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $params = ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()];
            if (Tenancy::isMulti() && Tenancy::current() !== null) {
                $params[Tenancy::QUERY] = Tenancy::current()->slug;
            }
            $url = route('password.reset', $params);
            $appName = AppSettings::appName();
            $greeting = __('passwords.mail_greeting', ['name' => $notifiable->displayName()]);
            $intro = __('passwords.mail_intro');
            $outro = [
                __('passwords.mail_expire', ['count' => config('auth.passwords.users.expire')]),
                __('passwords.mail_ignore'),
            ];

            // Same layout as all other mails (HTML + plain text); action() keeps actionUrl set.
            return (new MailMessage)
                ->subject(__('passwords.mail_subject', ['app' => $appName]))
                ->greeting($greeting)
                ->line($intro)
                ->action(__('passwords.mail_action'), $url)
                ->line($outro[0])
                ->line($outro[1])
                ->salutation($appName)
                ->view(['html' => 'mail.notice', 'text' => 'mail.notice-text'], [
                    'appName' => $appName,
                    'appUrl' => url('/'),
                    'title' => __('passwords.mail_action'),
                    'preheader' => $intro,
                    'lines' => [$greeting.',', $intro],
                    'rows' => [],
                    'buttonUrl' => $url,
                    'buttonLabel' => __('passwords.mail_action'),
                    'outro' => $outro,
                ]);
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
            $view->with('appName', AppSettings::appName())
                ->with('openItems', $open)
                ->with('maintenanceBanner', $user && $user->isAdmin() && AppSettings::maintenanceMode());
        });
    }
}
