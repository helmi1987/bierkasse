<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Backend;
use App\Http\Controllers\CashboxQrController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LiveController;
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
    // Cashbox QR sticker (no login, secret code selects cash or TWINT).
    Route::get('/kasse/{code}', [CashboxQrController::class, 'show'])->where('code', '[A-Za-z0-9_-]{1,64}')->middleware('throttle:60,1')->name('cashbox');
    Route::post('/kasse/{code}', [CashboxQrController::class, 'store'])->where('code', '[A-Za-z0-9_-]{1,64}')->middleware('throttle:30,1')->name('cashbox.store');

    // Privacy policy and terms of use (public, also while the consent is missing).
    Route::get('/datenschutz', [LegalController::class, 'privacy'])->name('legal.privacy');
    Route::get('/nutzungsbestimmungen', [LegalController::class, 'terms'])->name('legal.terms');

    // Registration and password reset (guests only)
    Route::middleware('guest')->group(function () {
        Route::get('/registrieren', [RegisterController::class, 'show'])->name('register');
        Route::post('/registrieren', [RegisterController::class, 'store'])->middleware('throttle:60,10')->name('register.store');
        Route::get('/passwort-vergessen', [PasswordResetController::class, 'request'])->name('password.request');
        Route::post('/passwort-vergessen', [PasswordResetController::class, 'email'])->middleware('throttle:5,10')->name('password.email');
        Route::get('/passwort-zuruecksetzen/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
        Route::post('/passwort-zuruecksetzen', [PasswordResetController::class, 'update'])->middleware('throttle:10,10')->name('password.update');
    });

    Route::middleware(['auth', AuthenticateSession::class, 'active'])->group(function () {
        // Consent to the current privacy policy / terms of use (EnsureActiveUser sends users here).
        Route::get('/zustimmung', [LegalController::class, 'consent'])->name('consent.show');
        Route::post('/zustimmung', [LegalController::class, 'accept'])->middleware('throttle:20,1')->name('consent.store');

        Route::post('/rechtliches/{kind}/gelesen', [LegalController::class, 'dismissNotice'])->whereIn('kind', ['privacy', 'terms'])->name('legal.notice.dismiss');

        // Beer account (balance, beers, deposits, rounds, guests, leaving); not for "Nur Verwaltung" accounts.
        Route::middleware('beer')->group(function () {
            Route::get('/konto', [AccountController::class, 'dashboard'])->name('account.dashboard');
            Route::post('/konto/bier', [AccountController::class, 'consume'])->middleware('throttle:30,1')->name('account.consume');
            Route::post('/konto/bier/{transaction}/rueckgaengig', [AccountController::class, 'undo'])->whereNumber('transaction')->name('account.undo');
            Route::post('/konto/gast', [AccountController::class, 'guest'])->middleware('throttle:30,1')->name('account.guest');
            Route::post('/konto/gast/{sale}/rueckgaengig', [AccountController::class, 'undoGuest'])->whereNumber('sale')->name('account.guest.undo');
            Route::post('/konto/runde', [AccountController::class, 'round'])->middleware('throttle:20,1')->name('account.round');
            Route::post('/konto/verschenken', [AccountController::class, 'gift'])->middleware('throttle:20,1')->name('account.gift');
            Route::post('/konto/hinweis/{transaction}', [AccountController::class, 'dismissNotice'])->whereNumber('transaction')->middleware('throttle:60,1')->name('account.notice.dismiss');
            Route::post('/konto/favorit/{list}/{colleague}', [AccountController::class, 'toggleFavorite'])->whereNumber('colleague')->middleware('throttle:60,1')->name('account.favorite');
            Route::post('/konto/einzahlung', [AccountController::class, 'deposit'])->middleware('throttle:10,1')->name('account.deposit');
            Route::put('/konto/einzahlung/{transaction}', [AccountController::class, 'updateDeposit'])->whereNumber('transaction')->middleware('throttle:20,1')->name('account.deposit.update');
            Route::delete('/konto/einzahlung/{transaction}', [AccountController::class, 'cancelDeposit'])->whereNumber('transaction')->middleware('throttle:20,1')->name('account.deposit.cancel');
            Route::get('/konto/verlauf', [AccountController::class, 'history'])->name('account.history');
            Route::get('/konto/verlassen', [AccountController::class, 'leaveForm'])->name('account.leave');
            Route::post('/konto/verlassen', [AccountController::class, 'leave'])->name('account.leave.store');
        });

        // Invitations and feedback (no money, also for "Nur Verwaltung" accounts)
        Route::get('/konto/einladen', [InvitationController::class, 'index'])->name('account.invite');
        Route::post('/konto/einladen', [InvitationController::class, 'store'])->middleware('throttle:20,1')->name('account.invite.store');
        Route::delete('/konto/einladungen/{invitation}', [InvitationController::class, 'destroy'])->whereNumber('invitation')->name('account.invite.destroy');
        Route::get('/konto/feedback', [FeedbackController::class, 'show'])->name('account.feedback');
        Route::post('/konto/feedback', [FeedbackController::class, 'store'])->middleware('throttle:20,1')->name('account.feedback.store');

        // Account settings
        Route::get('/konto/einstellungen', [AccountSettingsController::class, 'show'])->name('account.settings');
        Route::put('/konto/profil', [AccountSettingsController::class, 'profile'])->name('account.profile');
        Route::put('/konto/navigation', [AccountSettingsController::class, 'navOrder'])->middleware('role:kassier')->name('account.nav');
        Route::delete('/konto/navigation', [AccountSettingsController::class, 'navReset'])->middleware('role:kassier')->name('account.nav.reset');
        Route::put('/konto/passwort', [AccountSettingsController::class, 'password'])->name('account.password');
        Route::post('/konto/statistik', [AccountSettingsController::class, 'stats'])->name('account.stats');
        Route::post('/konto/nur-verwaltung', [AccountSettingsController::class, 'adminOnly'])->name('account.admin-only');
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

        // Live page refresh (public/js/live.js): cheap change marker, polled every 10 seconds per open tab.
        Route::get('/live', LiveController::class)->middleware('throttle:120,1')->name('live');

        // Backend: kassier and admin
        Route::prefix('backend')->name('backend.')->middleware('role:kassier')->group(function () {
            Route::get('/', [Backend\DashboardController::class, 'index'])->name('dashboard');
            Route::post('/einzahlungen/{transaction}/bestaetigen', [Backend\DashboardController::class, 'confirmDeposit'])->whereNumber('transaction')->name('deposits.confirm');
            Route::post('/einzahlungen/{transaction}/ablehnen', [Backend\DashboardController::class, 'rejectDeposit'])->whereNumber('transaction')->name('deposits.reject');
            Route::post('/auszahlungen/{transaction}/bestaetigen', [Backend\DashboardController::class, 'confirmPayout'])->whereNumber('transaction')->name('payouts.confirm');
            Route::post('/auszahlungen/{transaction}/ablehnen', [Backend\DashboardController::class, 'cancelPayout'])->whereNumber('transaction')->name('payouts.cancel');

            Route::post('/uebertraege/{transaction}/bestaetigen', [Backend\DashboardController::class, 'confirmCarryover'])->whereNumber('transaction')->name('carryovers.confirm');
            Route::post('/uebertraege/{transaction}/ablehnen', [Backend\DashboardController::class, 'rejectCarryover'])->whereNumber('transaction')->name('carryovers.reject');

            Route::get('/kasse', [Backend\CashController::class, 'index'])->name('cash');
            Route::post('/kasse', [Backend\CashController::class, 'store'])->name('cash.store');
            Route::delete('/kasse/{entry}', [Backend\CashController::class, 'destroy'])->whereNumber('entry')->name('cash.destroy');

            Route::get('/benutzer', [Backend\UserController::class, 'index'])->name('users.index');
            Route::get('/benutzer/{user}', [Backend\UserController::class, 'show'])->whereNumber('user')->name('users.show');
            Route::put('/benutzer/{user}/limit', [Backend\UserController::class, 'limit'])->whereNumber('user')->name('users.limit');
            Route::put('/benutzer/{user}/passwort', [Backend\UserController::class, 'password'])->whereNumber('user')->name('users.password');
            Route::post('/benutzer/{user}/totp-zuruecksetzen', [Backend\UserController::class, 'resetTotp'])->whereNumber('user')->name('users.totp');
            Route::post('/benutzer/{user}/status', [Backend\UserController::class, 'status'])->whereNumber('user')->name('users.status');
            Route::post('/benutzer/{user}/sperren', [Backend\UserController::class, 'lock'])->whereNumber('user')->name('users.lock');
            Route::post('/benutzer/{user}/entsperren', [Backend\UserController::class, 'unlock'])->whereNumber('user')->name('users.unlock');
            Route::post('/benutzer/{user}/buchungen/{transaction}/storno', [Backend\UserController::class, 'storno'])->whereNumber('user')->whereNumber('transaction')->name('users.storno');
            Route::post('/benutzer/{user}/einzahlung', [Backend\UserController::class, 'deposit'])->whereNumber('user')->name('users.deposit');
            Route::post('/benutzer/{user}/korrektur', [Backend\UserController::class, 'correction'])->whereNumber('user')->name('users.correction');
            Route::put('/benutzer/{user}/rolle', [Backend\UserController::class, 'role'])->whereNumber('user')->middleware('role:admin')->name('users.role');
            Route::put('/benutzer/{user}/nur-verwaltung', [Backend\UserController::class, 'adminOnly'])->whereNumber('user')->middleware('role:admin')->name('users.admin-only');

            Route::get('/statistik', [Backend\DashboardController::class, 'stats'])->name('stats');
            Route::get('/gaeste', [Backend\GuestController::class, 'index'])->name('guests');
            Route::put('/gaeste', [Backend\GuestController::class, 'update'])->name('guests.update');
            Route::get('/gaeste/qr', [Backend\GuestController::class, 'qr'])->name('guests.qr');
            // QR images (kassiers: active codes only). No .svg extension: Plesk/nginx may treat such URLs as static files.
            Route::get('/gaeste/kassen-qr/{qrCode}', [Backend\GuestController::class, 'cashboxSvg'])->whereNumber('qrCode')->name('guests.cashbox');
            Route::get('/gaeste/kassen-qr/{qrCode}/a4', [Backend\GuestController::class, 'cashboxSheet'])->whereNumber('qrCode')->name('guests.cashbox.sheet');
            Route::get('/gaeste/kassen-qr-a4/gemischt', [Backend\GuestController::class, 'cashboxMixedSheet'])->name('guests.cashbox.mixed');
            Route::get('/qr-codes', [Backend\QrCodeController::class, 'index'])->name('qr-codes.index');
            Route::get('/registrierung-qr', [Backend\SettingsController::class, 'registrationQr'])->name('registration.qr');
            Route::get('/registrierung-qr/flyer-bild', [Backend\SettingsController::class, 'flyerSvg'])->name('registration.flyer-svg');
            Route::delete('/gaeste/{sale}', [Backend\GuestController::class, 'destroy'])->whereNumber('sale')->name('guests.destroy');
            Route::get('/runden', [Backend\RoundController::class, 'index'])->name('rounds');
            Route::put('/runden', [Backend\RoundController::class, 'update'])->name('rounds.update');
            Route::get('/verschenkt', [Backend\GiftController::class, 'index'])->name('gifts');
            Route::put('/verschenkt', [Backend\GiftController::class, 'update'])->name('gifts.update');
            Route::get('/einladungen', [Backend\InvitationController::class, 'index'])->name('invitations');
            Route::delete('/einladungen/{invitation}', [Backend\InvitationController::class, 'destroy'])->whereNumber('invitation')->name('invitations.destroy');
            Route::get('/lager', [Backend\StockController::class, 'index'])->name('stock');
            Route::put('/lager/mindestbestand', [Backend\StockController::class, 'minimum'])->name('stock.minimum');
            Route::post('/lager/korrektur', [Backend\StockController::class, 'correct'])->name('stock.correct');
            Route::get('/einzahlungsvorschlaege', [Backend\DepositSuggestionController::class, 'edit'])->name('deposit-suggestions');
            Route::put('/einzahlungsvorschlaege', [Backend\DepositSuggestionController::class, 'update'])->name('deposit-suggestions.update');
            // Audit log: read-only (no route changes or deletes entries).
            Route::get('/protokoll', [Backend\AuditController::class, 'index'])->name('audit');
            Route::get('/protokoll/export', [Backend\AuditController::class, 'export'])->name('audit.export');
            Route::get('/protokoll/{entry}', [Backend\AuditController::class, 'show'])->whereNumber('entry')->name('audit.show');
            Route::post('/protokoll/pruefen', [Backend\AuditController::class, 'verify'])->name('audit.verify');

            // Admin only ("Backend" in the top bar, see BackendNav)
            Route::middleware('role:admin')->group(function () {
                Route::get('/einstellungen', [Backend\SettingsController::class, 'edit'])->name('settings');
                Route::put('/einstellungen', [Backend\SettingsController::class, 'update'])->name('settings.update');
                Route::put('/einstellungen/rechtliches', [Backend\SettingsController::class, 'legal'])->name('settings.legal');
                Route::get('/navigation', [Backend\NavController::class, 'edit'])->name('nav');
                Route::put('/navigation', [Backend\NavController::class, 'update'])->name('nav.update');
                Route::delete('/navigation', [Backend\NavController::class, 'reset'])->name('nav.reset');
                Route::post('/registrierung-qr/codes', [Backend\RegistrationCodeController::class, 'store'])->name('registration.codes.store');
                Route::put('/registrierung-qr/codes/{registrationCode}/sperre', [Backend\RegistrationCodeController::class, 'lock'])->whereNumber('registrationCode')->name('registration.codes.lock');
                // QR codes with history (cashbox, flyer, guest page): management view.
                Route::get('/verwaltung/qr-codes', [Backend\QrCodeController::class, 'manage'])->name('qr-admin');
                Route::put('/verwaltung/qr-codes/einstellungen', [Backend\QrCodeController::class, 'settings'])->name('qr-admin.settings');
                // Audit log in the admin bar (same page as for kassiers).
                Route::get('/verwaltung/protokoll', [Backend\AuditController::class, 'index'])->name('audit-admin');
                Route::get('/verwaltung/protokoll/{entry}', [Backend\AuditController::class, 'show'])->whereNumber('entry')->name('audit-admin.show');
                Route::post('/qr-codes', [Backend\QrCodeController::class, 'store'])->name('qr-codes.store');
                Route::put('/qr-codes/{qrCode}/aktiv', [Backend\QrCodeController::class, 'active'])->whereNumber('qrCode')->name('qr-codes.active');
                Route::put('/qr-codes/{qrCode}/name', [Backend\QrCodeController::class, 'label'])->whereNumber('qrCode')->name('qr-codes.label');
                Route::get('/feedback', [Backend\FeedbackController::class, 'index'])->name('feedback');
                Route::put('/feedback/empfaenger', [Backend\FeedbackController::class, 'recipients'])->name('feedback.recipients');
                Route::post('/feedback/{feedback}/gelesen', [Backend\FeedbackController::class, 'read'])->whereNumber('feedback')->name('feedback.read');
                Route::delete('/feedback/{feedback}', [Backend\FeedbackController::class, 'destroy'])->whereNumber('feedback')->name('feedback.destroy');
                Route::get('/wartung', [Backend\MaintenanceController::class, 'index'])->name('maintenance');
                Route::post('/wartung/cache', [Backend\MaintenanceController::class, 'clearCache'])->name('maintenance.cache');
                Route::post('/wartung/sperren', [Backend\MaintenanceController::class, 'clearLimits'])->name('maintenance.limits');
                Route::post('/wartung/optimierung', [Backend\MaintenanceController::class, 'clearOptimize'])->name('maintenance.optimize');
                Route::post('/wartung/migrieren', [Backend\MaintenanceController::class, 'migrate'])->name('maintenance.migrate');
                Route::post('/wartung/backup', [Backend\MaintenanceController::class, 'backup'])->name('maintenance.backup');
                // Without the .sql.gz extension: Plesk/nginx may serve such URLs as static files.
                Route::get('/wartung/backup/{backup}', [Backend\MaintenanceController::class, 'backupFile'])
                    ->where('backup', 'bierkasse-\d{4}-\d{2}-\d{2}-\d{6}')->name('maintenance.backup.file');
                Route::delete('/wartung/backup/{backup}', [Backend\MaintenanceController::class, 'backupDelete'])
                    ->where('backup', 'bierkasse-\d{4}-\d{2}-\d{2}-\d{6}')->name('maintenance.backup.delete');
                Route::put('/wartung/modus', [Backend\MaintenanceController::class, 'mode'])->name('maintenance.mode');
                Route::resource('sso', Backend\SsoProviderController::class)->except(['show'])
                    ->parameters(['sso' => 'ssoProvider'])->names('sso');
            });
        });
    });
});
