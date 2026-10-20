<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SetupTest extends TestCase
{
    private ?string $tmp = null;

    protected function tearDown(): void
    {
        if ($this->tmp !== null) {
            File::deleteDirectory($this->tmp);
        }

        parent::tearDown();
    }

    public function test_redirects_to_setup_when_not_installed(): void
    {
        config(['app.installed' => false]);

        $this->get('/')->assertRedirect('/setup');
        $this->get('/konto')->assertRedirect('/setup');
        $this->get('/backend')->assertRedirect('/setup');
    }

    public function test_setup_page_is_shown_when_not_installed(): void
    {
        config(['app.installed' => false]);

        $this->get('/setup')->assertOk()->assertSee('Einrichtung')->assertSee('SQLite')
            ->assertSee('storage/app/setup-token.txt')
            ->assertDontSee(storage_path('app/setup-token.txt'));
    }

    public function test_setup_prefills_the_current_host_as_app_url(): void
    {
        config(['app.installed' => false]);

        $this->get('http://bier.example.org/setup')->assertOk()->assertSee('http://bier.example.org');
    }

    public function test_setup_is_locked_after_install(): void
    {
        $this->get('/setup')->assertNotFound();
        $this->post('/setup', ['token' => 'x'])->assertNotFound();
    }

    public function test_setup_rejects_wrong_token(): void
    {
        $this->useTempPaths('the-token');
        config(['app.installed' => false]);

        $this->from('/setup')->post('/setup', $this->setupData(['token' => 'wrong']))
            ->assertRedirect('/setup')
            ->assertSessionHasErrors('token');

        $this->assertFileExists(storage_path('app/setup-token.txt'));
        $this->assertStringNotContainsString('APP_INSTALLED=true', (string) file_get_contents($this->tmp.'/.env'));
    }

    public function test_setup_with_sqlite_installs_and_creates_admin(): void
    {
        $this->useTempPaths('the-token');
        config(['app.installed' => false]);

        $this->post('/setup', $this->setupData())->assertRedirect(route('backend.settings'));

        $env = (string) file_get_contents($this->tmp.'/.env');
        $this->assertStringContainsString('APP_INSTALLED=true', $env);
        $this->assertStringContainsString('APP_URL=https://bier.example.org', $env);
        $this->assertStringContainsString('DB_CONNECTION=sqlite', $env);
        $this->assertFileDoesNotExist(storage_path('app/setup-token.txt'));

        $this->assertSame($this->tmp.'/db.sqlite', DB::connection()->getDatabaseName());
        $admin = User::query()->where('username', 'chef')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->isActive());
        $this->assertAuthenticatedAs($admin);

        $this->get('/backend')->assertOk();
    }

    public function test_setup_post_is_throttled(): void
    {
        config(['app.installed' => false]);
        $this->useTempPaths('the-token');

        for ($i = 0; $i < 10; $i++) {
            $this->post('/setup', $this->setupData(['token' => 'wrong']))->assertStatus(302);
        }

        $this->post('/setup', $this->setupData(['token' => 'wrong']))->assertStatus(429);
    }

    private function useTempPaths(string $token): void
    {
        $this->tmp = sys_get_temp_dir().'/bierkasse-setup-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->tmp.'/storage/app');
        File::ensureDirectoryExists($this->tmp.'/storage/framework/views');
        file_put_contents($this->tmp.'/.env', "APP_INSTALLED=false\nAPP_URL=http://localhost\n");
        file_put_contents($this->tmp.'/storage/app/setup-token.txt', $token."\n");

        $this->app->useEnvironmentPath($this->tmp);
        $this->app->useStoragePath($this->tmp.'/storage');
    }

    private function setupData(array $overrides = []): array
    {
        return array_merge([
            'token' => 'the-token',
            'app_url' => 'https://bier.example.org/',
            'driver' => 'sqlite',
            'database' => $this->tmp.'/db.sqlite',
            'admin_username' => 'chef',
            'admin_first_name' => 'Chef',
            'admin_password' => 'a-long-password-123',
            'admin_password_confirmation' => 'a-long-password-123',
        ], $overrides);
    }
}
