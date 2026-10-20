<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Backend;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\PersonalSsoController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\StatsController;
use App\Http\Middleware\EnsureInstalled;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

// PWA manifest (outside the install check, uses the app name when available).
Route::get('/manifest.webmanifest', ManifestController::class)->name('manifest');

Route::middleware(EnsureInstalled::class)->group(function () {
    // First-run wizard (404 once installed).
    Route::get('/setup', [SetupController::class, 'show'])->name('setup.show');
    Route::post('/setup', [SetupController::class, 'store'])->middleware('throttle:10,1')->name('setup.store');

    // Login ("/" is the login page only)
    Route::get('/', [LoginController::class, 'show'])->name('login');
    Route::post('/anmelden', [LoginController::class, 'login'])->middleware('throttle:30,1')->name('login.post');
    Route::get('/anmelden/totp', [LoginController::class, 'totpForm'])->name('login.totp');
    Route::post('/anmelden/totp', [LoginController::class, 'totpVerify'])->middleware('throttle:30,1')->name('login.totp.verify');
    Route::post('/anmelden/totp/abbrechen', [LoginController::class, 'totpCancel'])->name('login.totp.cancel');
    Route::post('/abmelden', [LoginController::class, 'logout'])->name('logout');
    Route::get('/login/sso/{provider}', [LoginController::class, 'ssoRedirect'])->name('login.sso')->where('provider', '[a-z0-9-]+');
    Route::get('/login/sso/{provider}/callback', [LoginController::class, 'ssoCallback'])->name('login.sso.callback')->where('provider', '[a-z0-9-]+');
    // Personal provider of a user (rate limited in the controller, plus a coarse route throttle).
    Route::get('/login/eigener', [PersonalSsoController::class, 'loginForm'])->name('login.personal');
    Route::post('/login/eigener', [PersonalSsoController::class, 'loginPost'])->middleware('throttle:30,1')->name('login.personal.post');
    Route::get('/login/eigener/{username}', [PersonalSsoController::class, 'confirm'])->middleware('throttle:30,1')->where('username', '[A-Za-z0-9._-]{1,64}')->name('login.personal.user');
    Route::post('/login/eigener/{username}', [PersonalSsoController::class, 'start'])->middleware('throttle:30,1')->where('username', '[A-Za-z0-9._-]{1,64}')->name('login.personal.start');

    // Guest sale via the guest QR code (no login).
    Route::get('/gast', [GuestController::class, 'show'])->name('guest');
    Route::post('/gast', [GuestController::class, 'store'])->middleware('throttle:30,1')->name('guest.store');
    Route::get('/gast/danke', [GuestController::class, 'thanks'])->name('guest.thanks');

    // Registration and password reset (guests only)
    Route::middleware('guest')->group(function () {
        Route::get('/registrieren', [RegisterController::class, 'show'])->name('register');
        Route::post('/registrieren', [RegisterController::class, 'store'])->middleware('throttle:10,10')->name('register.store');
        Route::get('/passwort-vergessen', [PasswordResetController::class, 'request'])->name('password.request');
        Route::post('/passwort-vergessen', [PasswordResetController::class, 'email'])->middleware('throttle:5,10')->name('password.email');
        Route::get('/passwort-zuruecksetzen/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
        Route::post('/passwort-zuruecksetzen', [PasswordResetController::class, 'update'])->middleware('throttle:10,10')->name('password.update');
    });

    Route::middleware(['auth', AuthenticateSession::class, 'active'])->group(function () {
        // User dashboard
        Route::get('/konto', [AccountController::class, 'dashboard'])->name('account.dashboard');
        Route::post('/konto/bier', [AccountController::class, 'consume'])->middleware('throttle:30,1')->name('account.consume');
        Route::post('/konto/bier/{transaction}/rueckgaengig', [AccountController::class, 'undo'])->whereNumber('transaction')->name('account.undo');
        Route::post('/konto/gast', [AccountController::class, 'guest'])->middleware('throttle:30,1')->name('account.guest');
        Route::post('/konto/gast/{sale}/rueckgaengig', [AccountController::class, 'undoGuest'])->whereNumber('sale')->name('account.guest.undo');
        Route::post('/konto/einzahlung', [AccountController::class, 'deposit'])->middleware('throttle:10,1')->name('account.deposit');
        Route::put('/konto/einzahlung/{transaction}', [AccountController::class, 'updateDeposit'])->whereNumber('transaction')->middleware('throttle:20,1')->name('account.deposit.update');
        Route::delete('/konto/einzahlung/{transaction}', [AccountController::class, 'cancelDeposit'])->whereNumber('transaction')->middleware('throttle:20,1')->name('account.deposit.cancel');
        Route::get('/konto/verlauf', [AccountController::class, 'history'])->name('account.history');
        Route::get('/konto/verlassen', [AccountController::class, 'leaveForm'])->name('account.leave');
        Route::post('/konto/verlassen', [AccountController::class, 'leave'])->name('account.leave.store');

        // Account settings
        Route::get('/konto/einstellungen', [AccountSettingsController::class, 'show'])->name('account.settings');
        Route::put('/konto/profil', [AccountSettingsController::class, 'profile'])->name('account.profile');
        Route::put('/konto/passwort', [AccountSettingsController::class, 'password'])->name('account.password');
        Route::post('/konto/statistik', [AccountSettingsController::class, 'stats'])->name('account.stats');
        Route::post('/konto/totp', [AccountSettingsController::class, 'enableTotp'])->name('account.totp.enable');
        Route::post('/konto/totp/neu', [AccountSettingsController::class, 'restartTotp'])->name('account.totp.restart');
        Route::post('/konto/totp/deaktivieren', [AccountSettingsController::class, 'disableTotp'])->name('account.totp.disable');
        Route::post('/konto/totp/wiederherstellung', [AccountSettingsController::class, 'regenerateRecovery'])->name('account.totp.recovery');
        Route::get('/konto/sso/{provider}/verknuepfen', [AccountSettingsController::class, 'linkSso'])->where('provider', '[a-z0-9-]+')->name('account.sso.link');
        Route::delete('/konto/sso/{identity}', [AccountSettingsController::class, 'unlinkSso'])->whereNumber('identity')->name('account.sso.unlink');
        Route::put('/konto/eigener-anbieter', [PersonalSsoController::class, 'save'])->middleware('throttle:10,1')->name('account.personal-sso.save');
        Route::delete('/konto/eigener-anbieter', [PersonalSsoController::class, 'destroy'])->name('account.personal-sso.destroy');
        Route::get('/konto/eigener-anbieter/verbinden', [PersonalSsoController::class, 'connect'])->middleware('throttle:10,1')->name('account.personal-sso.connect');

        Route::get('/statistik', [StatsController::class, 'index'])->name('stats');

        // Backend: kassier and admin
        Route::prefix('backend')->name('backend.')->middleware('role:kassier')->group(function () {
            Route::get('/', [Backend\DashboardController::class, 'index'])->name('dashboard');
            Route::post('/einzahlungen/{transaction}/bestaetigen', [Backend\DashboardController::class, 'confirmDeposit'])->whereNumber('transaction')->name('deposits.confirm');
            Route::post('/einzahlungen/{transaction}/ablehnen', [Backend\DashboardController::class, 'rejectDeposit'])->whereNumber('transaction')->name('deposits.reject');
            Route::post('/auszahlungen/{transaction}/bestaetigen', [Backend\DashboardController::class, 'confirmPayout'])->whereNumber('transaction')->name('payouts.confirm');
            Route::post('/auszahlungen/{transaction}/ablehnen', [Backend\DashboardController::class, 'cancelPayout'])->whereNumber('transaction')->name('payouts.cancel');

            Route::get('/kasse', [Backend\CashController::class, 'index'])->name('cash');
            Route::post('/kasse', [Backend\CashController::class, 'store'])->name('cash.store');
            Route::delete('/kasse/{entry}', [Backend\CashController::class, 'destroy'])->whereNumber('entry')->name('cash.destroy');

            Route::get('/benutzer', [Backend\UserController::class, 'index'])->name('users.index');
            Route::get('/benutzer/{user}', [Backend\UserController::class, 'show'])->whereNumber('user')->name('users.show');
            Route::put('/benutzer/{user}/limit', [Backend\UserController::class, 'limit'])->whereNumber('user')->name('users.limit');
            Route::put('/benutzer/{user}/passwort', [Backend\UserController::class, 'password'])->whereNumber('user')->name('users.password');
            Route::post('/benutzer/{user}/totp-zuruecksetzen', [Backend\UserController::class, 'resetTotp'])->whereNumber('user')->name('users.totp');
            Route::post('/benutzer/{user}/status', [Backend\UserController::class, 'status'])->whereNumber('user')->name('users.status');
            Route::post('/benutzer/{user}/einzahlung', [Backend\UserController::class, 'deposit'])->whereNumber('user')->name('users.deposit');
            Route::post('/benutzer/{user}/korrektur', [Backend\UserController::class, 'correction'])->whereNumber('user')->name('users.correction');
            Route::put('/benutzer/{user}/rolle', [Backend\UserController::class, 'role'])->whereNumber('user')->middleware('role:admin')->name('users.role');

            Route::get('/statistik', [Backend\DashboardController::class, 'stats'])->name('stats');
            Route::get('/gaeste', [Backend\GuestController::class, 'index'])->name('guests');
            Route::put('/gaeste', [Backend\GuestController::class, 'update'])->name('guests.update');
            Route::get('/gaeste/qr', [Backend\GuestController::class, 'qr'])->name('guests.qr');
            Route::delete('/gaeste/{sale}', [Backend\GuestController::class, 'destroy'])->whereNumber('sale')->name('guests.destroy');
            Route::get('/lager', [Backend\StockController::class, 'index'])->name('stock');
            Route::put('/lager/mindestbestand', [Backend\StockController::class, 'minimum'])->name('stock.minimum');
            Route::post('/lager/korrektur', [Backend\StockController::class, 'correct'])->name('stock.correct');
            Route::get('/einzahlungsvorschlaege', [Backend\DepositSuggestionController::class, 'edit'])->name('deposit-suggestions');
            Route::put('/einzahlungsvorschlaege', [Backend\DepositSuggestionController::class, 'update'])->name('deposit-suggestions.update');

            // Admin only
            Route::middleware('role:admin')->group(function () {
                Route::get('/einstellungen', [Backend\SettingsController::class, 'edit'])->name('settings');
                Route::put('/einstellungen', [Backend\SettingsController::class, 'update'])->name('settings.update');
                Route::get('/registrierung-qr', [Backend\SettingsController::class, 'registrationQr'])->name('registration.qr');
                Route::resource('sso', Backend\SsoProviderController::class)->except(['show'])
                    ->parameters(['sso' => 'ssoProvider'])->names('sso');
            });
        });
    });
});
