<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\AppSettings;
use App\Support\SystemInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIONS = ['/backend/wartung/cache', '/backend/wartung/sperren', '/backend/wartung/optimierung', '/backend/wartung/migrieren'];

    private function maintenanceOn(string $message = ''): void
    {
        Setting::put(AppSettings::MAINTENANCE, true);
        Setting::put(AppSettings::MAINTENANCE_MESSAGE, $message);
    }

    private function exhaustRegistrationLimit(): void
    {
        Setting::put(AppSettings::REGISTRATION_MODE, AppSettings::MODE_PASSWORD);
        Setting::put(AppSettings::REGISTRATION_PASSWORD, 'durst');
        for ($i = 0; $i < 10; $i++) {
            $this->post('/registrieren', ['code' => 'falsch', 'username' => 'u'.$i]);
        }
        $this->assertTrue(RateLimiter::tooManyAttempts('register:127.0.0.1', 10));
    }

    public function test_only_admins_reach_the_maintenance_page(): void
    {
        foreach ([$this->user(), $this->kassier()] as $who) {
            $this->actingAs($who);
            $this->get('/backend/wartung')->assertForbidden();
            foreach (self::ACTIONS as $url) {
                $this->post($url)->assertForbidden();
            }
            $this->put('/backend/wartung/modus', ['maintenance' => 1])->assertForbidden();
        }
        $this->assertFalse(AppSettings::maintenanceMode());

        $this->actingAs($this->kassier());
        $this->get('/backend')->assertOk()->assertDontSee(route('backend.maintenance'));

        $this->actingAs($this->admin());
        $this->get('/backend')->assertOk()->assertSee(route('backend.maintenance'));
        $this->get('/backend/wartung')->assertOk()
            ->assertSee('Cache leeren')
            ->assertSee('Setzt auch gesperrte Anmelde-/Registrierungsversuche zurück', false)
            ->assertSee(PHP_VERSION)
            ->assertSee(app()->version());
    }

    public function test_cache_clear_resets_registration_rate_limit(): void
    {
        $this->exhaustRegistrationLimit();
        Log::spy();

        $admin = $this->admin(['username' => 'chef']);
        $this->actingAs($admin);
        $this->post('/backend/wartung/cache')->assertSessionHas('status');

        $this->assertFalse(RateLimiter::tooManyAttempts('register:127.0.0.1', 10));
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx) => $msg === 'Maintenance: cache cleared' && $ctx['admin'] === 'chef');
    }

    public function test_clearing_only_the_limits_keeps_the_application_cache(): void
    {
        $this->exhaustRegistrationLimit();
        cache()->put('keep-me', 'yes', 600);

        $this->actingAs($this->admin());
        $this->post('/backend/wartung/sperren')->assertSessionHas('status');

        $this->assertFalse(RateLimiter::tooManyAttempts('register:127.0.0.1', 10));
        $this->assertSame('yes', cache()->get('keep-me'));
    }

    public function test_optimize_clear_runs(): void
    {
        $this->actingAs($this->admin());
        $this->post('/backend/wartung/optimierung')->assertSessionHas('status')->assertSessionHas('maintenance_output');
        $this->get('/backend/wartung')->assertOk();
    }

    public function test_pending_migrations_are_shown_and_migrated(): void
    {
        $dir = $this->testStorage.'/migrations';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/2099_01_01_000000_create_maintenance_probe_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_probe', function (Blueprint $table) {
            $table->id();
        });
    }
};
PHP);
        app('migrator')->path($dir);

        $this->actingAs($this->admin());
        $this->get('/backend/wartung')->assertOk()->assertSee('2099_01_01_000000_create_maintenance_probe_table');
        $this->assertSame(['2099_01_01_000000_create_maintenance_probe_table'], SystemInfo::pendingMigrations());

        $this->post('/backend/wartung/migrieren')->assertSessionHas('status')->assertSessionHas('maintenance_output');
        $this->assertTrue(Schema::hasTable('maintenance_probe'));
        $this->assertSame([], SystemInfo::pendingMigrations());
        $this->get('/backend/wartung')->assertOk()->assertSee('Keine ausstehenden Migrationen.');
    }

    public function test_admin_switches_maintenance_mode(): void
    {
        $this->actingAs($this->admin());
        $this->put('/backend/wartung/modus', ['maintenance' => 1, 'maintenance_message' => 'Bis 14 Uhr'])->assertSessionHas('status');
        $this->assertTrue(AppSettings::maintenanceMode());
        $this->assertSame('Bis 14 Uhr', AppSettings::maintenanceMessage());

        $this->put('/backend/wartung/modus', ['maintenance_message' => ''])->assertSessionHas('status');
        $this->assertFalse(AppSettings::maintenanceMode());
    }

    public function test_maintenance_mode_blocks_guests_and_non_admins(): void
    {
        $this->maintenanceOn('Neues Fass kommt');

        $this->get('/gast')->assertStatus(503)->assertSee('Die Bierkasse wird gerade gewartet')->assertSee('Neues Fass kommt')->assertSee('logo.svg');
        $this->get('/registrieren')->assertStatus(503);

        // Login page stays reachable; so do the PWA manifest.
        $this->get('/')->assertOk();
        $this->get('/manifest.webmanifest')->assertOk();

        foreach ([$this->user(), $this->kassier()] as $who) {
            $this->actingAs($who);
            $this->get('/konto')->assertStatus(503)->assertSee('Die Bierkasse wird gerade gewartet');
            $this->assertGuest();
        }
        $this->actingAs($this->kassier());
        $this->get('/backend')->assertStatus(503);
    }

    public function test_non_admin_login_gets_maintenance_page(): void
    {
        $this->maintenanceOn();
        $user = $this->user(['username' => 'durstig']);

        $this->post('/anmelden', ['username' => 'durstig', 'password' => 'secret-password'])->assertRedirect(route('account.dashboard'));
        $this->get('/konto')->assertStatus(503);
        $this->assertGuest();
    }

    public function test_admin_works_normally_and_sees_banner(): void
    {
        $this->maintenanceOn();
        $admin = $this->admin(['username' => 'boss']);

        $this->post('/anmelden', ['username' => 'boss', 'password' => 'secret-password'])->assertRedirect(route('account.dashboard'));
        $this->get('/konto')->assertOk()->assertSee('Wartungsmodus aktiv');
        $this->get('/backend')->assertOk()->assertSee('Wartungsmodus aktiv');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_no_banner_without_maintenance_mode(): void
    {
        $this->actingAs($this->admin());
        $this->get('/konto')->assertOk()->assertDontSee('Wartungsmodus aktiv');
        $this->actingAs($this->user());
        $this->get('/konto')->assertOk();
    }

    public function test_git_version_is_read_from_files(): void
    {
        $base = $this->testStorage.'/repo';
        $hash = str_repeat('ab', 20);
        File::ensureDirectoryExists($base.'/.git/refs/heads');
        File::put($base.'/.git/HEAD', "ref: refs/heads/main\n");
        File::put($base.'/.git/packed-refs', "# pack-refs with: peeled\n{$hash} refs/heads/main\n");

        $git = SystemInfo::gitVersion($base);
        $this->assertSame('abababa', $git['hash']);
        $this->assertSame('main', $git['branch']);
        $this->assertSame('file', $git['date_source']);

        // Loose ref and loose commit object with a committer date.
        File::put($base.'/.git/refs/heads/main', $hash."\n");
        $body = "tree x\nauthor A <a@b> 1700000000 +0100\ncommitter A <a@b> 1700000100 +0100\n\nmsg\n";
        File::ensureDirectoryExists($base.'/.git/objects/ab');
        File::put($base.'/.git/objects/ab/'.substr($hash, 2), gzcompress('commit '.strlen($body)."\0".$body));
        $git = SystemInfo::gitVersion($base);
        $this->assertSame(1700000100, $git['date']);
        $this->assertSame('commit', $git['date_source']);

        $this->assertNull(SystemInfo::gitVersion($this->testStorage.'/nothing'));
    }

    public function test_app_version_from_version_file(): void
    {
        $base = $this->testStorage.'/version';
        File::ensureDirectoryExists($base);
        File::put($base.'/VERSION', "1.2.3\n");
        $this->assertSame('1.2.3', SystemInfo::appVersion($base)['version']);
        File::put($base.'/VERSION', "<script>\n");
        $this->assertNull(SystemInfo::appVersion($base));
        $this->assertNull(SystemInfo::appVersion($this->testStorage.'/nothing'));

        $this->actingAs($this->admin())->get('/backend/wartung')->assertOk()
            ->assertSee('<strong>'.trim((string) file_get_contents(base_path('VERSION'))).'</strong>', false);
    }
}
