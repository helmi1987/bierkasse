<?php

namespace Tests;

use App\Models\QrCode;
use App\Models\Setting;
use App\Models\User;
use App\Services\LegalArchive;
use App\Support\AppSettings;
use App\Support\FormToken;
use App\Support\OutboundUrl;
use App\Support\Totp;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    protected ?string $testStorage = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The settings cache is static; start every test with an empty one.
        Setting::flushCache();
        // No real DNS lookups from a previous test's resolver.
        OutboundUrl::resolveUsing(null);

        $this->testStorage = sys_get_temp_dir().'/bierkasse-test-'.uniqid();
        File::ensureDirectoryExists($this->testStorage.'/app');
        File::ensureDirectoryExists($this->testStorage.'/framework/views');
        $this->app->useStoragePath($this->testStorage);
    }

    protected function tearDown(): void
    {
        if ($this->testStorage !== null) {
            File::deleteDirectory($this->testStorage);
        }
        parent::tearDown();
    }

    /**
     * Switching users within a test starts a fresh session (AuthenticateSession would
     * otherwise log out the second user because of the stored password hash).
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->flushSession();

        return parent::actingAs($user, $guard);
    }

    /**
     * Form of Admin > Einstellungen > Funktionen: rounds, gifts and guest booking by users on (defaults),
     * public guest page and cashbox QR off, unless overridden ("0" = off).
     */
    protected function featureForm(array $values = []): array
    {
        $form = array_merge(['rounds_enabled' => '1', 'gifts_enabled' => '1', 'guest_user_booking' => '1'], $values);

        return array_filter($form, fn ($v) => $v !== '0' && $v !== false && $v !== null);
    }

    /**
     * The registration form sends the revisions of the linked legal texts as hidden fields; tests that
     * post it without them get the current ones (as if the form had just been shown). Tests of the
     * check itself pass the fields explicitly.
     */
    public function post($uri, array $data = [], array $headers = [])
    {
        $path = '/'.ltrim((string) parse_url($uri, PHP_URL_PATH), '/');
        if ($path === '/registrieren') {
            foreach (LegalArchive::currentAll() as $kind => $revision) {
                $data += [$kind.'_revision' => $revision->id];
            }
        }

        return parent::post($uri, $data, $headers);
    }

    /**
     * Adds a fresh single-use form token (FormToken) to the posted data.
     */
    protected function tokened(string $form, array $data = []): array
    {
        $this->startSession();

        return $data + [FormToken::FIELD => FormToken::issue($form)];
    }

    /** Newest active flyer code (the first one is created when there never was one). */
    protected function flyerCode(): string
    {
        QrCode::ensureFirst(QrCode::KIND_FLYER);

        return QrCode::newestActive(QrCode::KIND_FLYER)->code;
    }

    protected function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['password' => 'secret-password'], $attributes));
    }

    protected function kassier(array $attributes = []): User
    {
        return $this->user(array_merge(['role' => User::ROLE_KASSIER], $attributes));
    }

    protected function admin(array $attributes = []): User
    {
        return $this->user(array_merge(['role' => User::ROLE_ADMIN], $attributes));
    }

    protected function withTotp(User $user): User
    {
        $user->forceFill([
            'totp_secret' => Totp::generateSecret(),
            'totp_confirmed_at' => now(),
        ])->save();

        return $user->refresh();
    }

    protected function currentCode(User $user, int $offset = 0): string
    {
        return Totp::code((string) $user->totp_secret, Totp::currentStep() + $offset);
    }

    protected function setPrice(int $rappen): void
    {
        Setting::put(AppSettings::BEER_PRICE, $rappen);
    }

    protected function setDefaultLimit(int $rappen): void
    {
        Setting::put(AppSettings::DEFAULT_LIMIT, $rappen);
    }
}
