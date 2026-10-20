<?php

namespace Tests;

use App\Models\Setting;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\FormToken;
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
     * Adds a fresh single-use form token (FormToken) to the posted data.
     */
    protected function tokened(string $form, array $data = []): array
    {
        $this->startSession();

        return $data + [FormToken::FIELD => FormToken::issue($form)];
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
