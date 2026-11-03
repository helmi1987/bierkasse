<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Audit;
use App\Services\DatabaseBackup;
use App\Services\Ledger;
use App\Services\Tenancy;
use App\Support\AppSettings;
use App\Support\LiveFingerprint;
use Closure;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * Multi-tenancy ("Mandanten", App\Services\Tenancy): single mode as default, isolation of the tenants in
 * multi mode, login resolution, mode switch, tenant management, superadmins, audit chains, public pages.
 */
class TenancyTest extends TestCase
{
    use RefreshDatabase;

    /** Tables with a tenant_id column (see the migration create_tenants). */
    private const TENANT_TABLES = [
        'users', 'transactions', 'cash_entries', 'stock_corrections', 'guest_sales', 'invitations', 'feedback',
        'registration_codes', 'qr_codes', 'legal_revisions', 'audit_logs', 'settings', 'audit_chains',
    ];

    private ?string $setupTmp = null;

    protected function tearDown(): void
    {
        if ($this->setupTmp !== null) {
            // The wizard switched the default connection to its own SQLite file, so RefreshDatabase cannot roll
            // back the transaction of the shared in-memory test database any more: do it here.
            foreach (RefreshDatabaseState::$inMemoryConnections as $pdo) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
            File::deleteDirectory($this->setupTmp);
        }

        parent::tearDown();
    }

    /**
     * Multi mode with the default tenant "Standard" (alpha, holds the superadmin "chef") and a second
     * tenant "Luks" (beta, slug "luks").
     *
     * @return array{0: Tenant, 1: Tenant, 2: User}
     */
    private function multi(): array
    {
        $super = $this->admin(['username' => 'chef', 'first_name' => 'Chef', 'last_name' => 'Superadmin']);
        Tenancy::enableMulti($super, backup: false);
        $alpha = Tenant::query()->findOrFail(1);
        $beta = Tenant::query()->create(['name' => 'Luks', 'slug' => 'luks']);
        foreach ([$alpha, $beta] as $tenant) {
            // Beers on credit (the default limit 0 means "deposit first").
            $this->in($tenant, fn () => $this->setDefaultLimit(10000));
        }
        // Like a new request: no tenant context until something names one.
        Tenancy::flush();

        return [$alpha, $beta, $super->fresh()];
    }

    private function in(Tenant $tenant, Closure $callback): mixed
    {
        return Tenancy::runAs($tenant, $callback);
    }

    private function auditExists(string $action): bool
    {
        return AuditLog::withoutGlobalScopes()->where('action', $action)->exists();
    }

    // 1. Default state

    public function test_default_is_single_mode_with_one_tenant(): void
    {
        $this->assertFalse(Tenancy::isMulti());
        $this->assertSame(Tenancy::MODE_SINGLE, Tenancy::mode());
        $this->assertSame(1, Tenant::query()->count());
        $tenant = Tenant::query()->first();
        $this->assertSame(1, $tenant->id);
        $this->assertSame('Standard', $tenant->name);
        $this->assertSame('standard', $tenant->slug);
        $this->assertSame(1, Tenancy::currentId());

        $this->assertSame(1, (int) $this->user()->tenant_id);
    }

    public function test_settings_are_per_tenant_except_global_keys(): void
    {
        $this->setPrice(180);
        $this->assertSame(1, (int) Setting::row(AppSettings::BEER_PRICE)->tenant_id);
        Tenancy::flush();
        $this->assertSame(180, Setting::get(AppSettings::BEER_PRICE));
        $this->assertSame(180, AppSettings::beerPrice());

        Setting::put(AppSettings::MAIL, ['transport' => 'sendmail']);
        Setting::put(AppSettings::TENANCY_MODE, Tenancy::MODE_SINGLE);
        $this->assertNull(Setting::row(AppSettings::MAIL)->tenant_id);
        $this->assertNull(Setting::row(AppSettings::TENANCY_MODE)->tenant_id);
        $this->assertTrue(Setting::isGlobal(AppSettings::MAIL));
        $this->assertTrue(Setting::isGlobal(AppSettings::TENANCY_MODE));
        $this->assertFalse(Setting::isGlobal(AppSettings::BEER_PRICE));
        $this->assertSame(1, DB::table('settings')->where('key', AppSettings::MAIL)->count());
        $this->assertSame(0, DB::table('settings')->where('key', AppSettings::MAIL)->whereNotNull('tenant_id')->count());
    }

    // 2. Isolation in multi mode

    public function test_settings_are_isolated_between_tenants(): void
    {
        [$alpha, $beta] = $this->multi();
        $this->in($alpha, fn () => $this->setPrice(150));
        $this->in($beta, fn () => $this->setPrice(220));

        $this->assertSame(150, $this->in($alpha, fn () => AppSettings::beerPrice()));
        $this->assertSame(220, $this->in($beta, fn () => AppSettings::beerPrice()));

        // Without tenant context tenant settings read as unset and cannot be written.
        $this->assertNull(Tenancy::current());
        $this->assertNull(Setting::get(AppSettings::BEER_PRICE));
        $this->expectException(RuntimeException::class);
        Setting::put(AppSettings::BEER_PRICE, 999);
    }

    public function test_models_get_the_current_tenant_and_queries_are_scoped(): void
    {
        [$alpha, $beta] = $this->multi();
        $anna = $this->in($alpha, fn () => $this->user(['username' => 'annaalpha']));
        $bert = $this->in($beta, fn () => $this->user(['username' => 'bertbeta']));

        $this->assertSame($alpha->id, (int) $anna->tenant_id);
        $this->assertSame($beta->id, (int) $bert->tenant_id);
        $this->assertSame(['annaalpha', 'chef'], $this->in($alpha, fn () => User::query()->orderBy('username')->pluck('username')->all()));
        $this->assertSame(['bertbeta'], $this->in($beta, fn () => User::query()->pluck('username')->all()));
        // No tenant context in multi mode: nothing matches.
        $this->assertSame(0, User::query()->count());
        $this->assertSame(3, User::withoutTenant()->count());
        $this->assertSame(1, User::forTenant($beta)->count());
    }

    public function test_kassier_sees_only_users_of_the_own_tenant(): void
    {
        [$alpha, $beta] = $this->multi();
        $kassier = $this->in($alpha, fn () => $this->kassier(['username' => 'kasper']));
        $anna = $this->in($alpha, fn () => $this->user(['username' => 'annaalpha', 'first_name' => 'Anna', 'last_name' => 'Alpha']));
        $bert = $this->in($beta, fn () => $this->user(['username' => 'bertbeta', 'first_name' => 'Bert', 'last_name' => 'Betaner']));

        $this->actingAs($kassier);
        $this->get('/admin/benutzer')->assertOk()->assertSee('annaalpha')->assertDontSee('bertbeta')->assertDontSee('Betaner');
        $this->get("/admin/benutzer/{$anna->id}")->assertOk();
        $this->get("/admin/benutzer/{$bert->id}")->assertNotFound();
        $this->post("/admin/benutzer/{$bert->id}/einzahlung", ['amount' => '100', 'method' => 'cash'])->assertNotFound();
        $this->assertSame(0, Transaction::withoutTenant()->where('user_id', $bert->id)->count());
    }

    /**
     * Route model binding (SubstituteBindings) must see the tenant of the request. In a fresh PHP process the
     * static tenancy state is empty; Tenancy::flush() simulates that (tests otherwise keep the tenant of the
     * previous request in the static state).
     */
    public function test_route_binding_of_tenant_models_works_in_a_fresh_request(): void
    {
        [$alpha] = $this->multi();
        $kassier = $this->in($alpha, fn () => $this->kassier());
        $anna = $this->in($alpha, fn () => $this->user());

        $this->actingAs($kassier);
        Tenancy::flush();
        $this->get("/admin/benutzer/{$anna->id}")->assertOk();
    }

    public function test_balances_and_prices_are_per_tenant(): void
    {
        [$alpha, $beta] = $this->multi();
        $anna = $this->in($alpha, function () {
            $this->setPrice(150);
            $user = $this->user(['username' => 'annaalpha']);
            Ledger::consume($user, 2);

            return $user;
        });
        $bert = $this->in($beta, function () {
            $this->setPrice(200);
            $user = $this->user(['username' => 'bertbeta']);
            Ledger::consume($user, 1);

            return $user;
        });

        $this->assertSame(150, $this->in($alpha, fn () => AppSettings::beerPrice()));
        $this->assertSame(200, $this->in($beta, fn () => AppSettings::beerPrice()));
        $this->assertSame(-300, $this->in($alpha, fn () => Ledger::balance($anna)));
        $this->assertSame(-200, $this->in($beta, fn () => Ledger::balance($bert)));
        $this->assertSame($beta->id, (int) Transaction::withoutTenant()->where('user_id', $bert->id)->value('tenant_id'));
        $this->assertSame([$bert->id => -200], $this->in($beta, fn () => Ledger::balances()));

        $this->actingAs($bert)->get('/konto')->assertOk()->assertSee('CHF -2.00')->assertDontSee('CHF -3.00');
        $this->actingAs($anna)->get('/konto')->assertOk()->assertSee('CHF -3.00');
    }

    // 3. Login resolution

    public function test_same_credentials_in_two_tenants_ask_for_the_tenant(): void
    {
        [$alpha, $beta] = $this->multi();
        $this->in($alpha, fn () => $this->user(['username' => 'hans']));
        $b = $this->in($beta, fn () => $this->user(['username' => 'hans']));

        $this->post('/anmelden', ['username' => 'hans', 'password' => 'secret-password'])
            ->assertRedirect(route('login.tenant'));
        $this->assertGuest();

        $this->post('/anmelden/mandant', ['user' => $b->id])->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($b);
        $this->assertSame($beta->id, (int) session(Tenancy::SESSION_KEY));
        $this->get('/konto')->assertOk();
    }

    public function test_tenant_choice_page_lists_both_tenants(): void
    {
        [$alpha, $beta] = $this->multi();
        $this->in($alpha, fn () => $this->user(['username' => 'hans']));
        $this->in($beta, fn () => $this->user(['username' => 'hans']));

        $this->post('/anmelden', ['username' => 'hans', 'password' => 'secret-password'])->assertRedirect(route('login.tenant'));
        $this->get('/anmelden/mandant')->assertOk()->assertSee('Standard')->assertSee('Luks');
    }

    public function test_tenant_choice_refuses_an_unknown_account(): void
    {
        [$alpha, $beta] = $this->multi();
        $this->in($alpha, fn () => $this->user(['username' => 'hans']));
        $this->in($beta, fn () => $this->user(['username' => 'hans']));
        $other = $this->in($beta, fn () => $this->user(['username' => 'fritz']));

        $this->post('/anmelden', ['username' => 'hans', 'password' => 'secret-password'])->assertRedirect(route('login.tenant'));
        $this->from('/anmelden/mandant')->post('/anmelden/mandant', ['user' => 999999])->assertSessionHasErrors('user');
        $this->from('/anmelden/mandant')->post('/anmelden/mandant', ['user' => $other->id])->assertSessionHasErrors('user');
        $this->assertGuest();
    }

    public function test_tenant_choice_without_pending_login_goes_back_to_login(): void
    {
        $this->multi();

        $this->get('/anmelden/mandant')->assertRedirect(route('login'));
        $this->post('/anmelden/mandant', ['user' => 1])->assertRedirect(route('login'))->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_unique_username_logs_in_directly_on_the_base_host(): void
    {
        [, $beta] = $this->multi();
        $bert = $this->in($beta, fn () => $this->user(['username' => 'bertbeta']));

        $this->post('/anmelden', ['username' => 'bertbeta', 'password' => 'secret-password'])
            ->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($bert);
        $this->assertSame($beta->id, (int) session(Tenancy::SESSION_KEY));
    }

    public function test_wrong_password_fails_in_multi_mode(): void
    {
        [$alpha, $beta] = $this->multi();
        $this->in($alpha, fn () => $this->user(['username' => 'hans']));
        $this->in($beta, fn () => $this->user(['username' => 'hans']));

        $this->from('/')->post('/anmelden', ['username' => 'hans', 'password' => 'wrong'])
            ->assertRedirect('/')->assertSessionHasErrors(['username' => __('auth.failed')]);
        $this->assertGuest();
    }

    public function test_only_the_named_tenant_is_searched_with_the_link_parameter(): void
    {
        [$alpha, $beta] = $this->multi();
        $a = $this->in($alpha, fn () => $this->user(['username' => 'hans']));
        $this->in($beta, fn () => $this->user(['username' => 'hans']));

        $this->get('/?mandant=standard')->assertOk();
        $this->post('/anmelden', ['username' => 'hans', 'password' => 'secret-password'])
            ->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($a);
    }

    public function test_link_parameter_on_the_post_names_the_tenant(): void
    {
        [$alpha, $beta] = $this->multi();
        $this->in($alpha, fn () => $this->user(['username' => 'hans']));
        $b = $this->in($beta, fn () => $this->user(['username' => 'hans']));

        $this->post('/anmelden?mandant=luks', ['username' => 'hans', 'password' => 'secret-password'])
            ->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($b);
    }

    public function test_choosing_the_locked_account_is_refused(): void
    {
        [$alpha, $beta] = $this->multi();
        $locked = $this->in($alpha, fn () => User::factory()->locked()->create(['username' => 'hans', 'password' => 'secret-password']));
        $this->in($beta, fn () => $this->user(['username' => 'hans']));

        $this->post('/anmelden', ['username' => 'hans', 'password' => 'secret-password'])->assertRedirect(route('login.tenant'));
        $this->post('/anmelden/mandant', ['user' => $locked->id])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username' => __('auth.locked', ['email' => $this->in($alpha, fn () => AppSettings::legalEmail())])]);
        $this->assertGuest();
    }

    // 4. TOTP

    public function test_totp_after_login_in_multi_mode(): void
    {
        [, $beta] = $this->multi();
        $bert = $this->withTotp($this->in($beta, fn () => $this->user(['username' => 'bertbeta'])));

        $this->post('/anmelden', ['username' => 'bertbeta', 'password' => 'secret-password'])
            ->assertRedirect(route('login.totp'));
        $this->assertGuest();

        $this->post('/anmelden/totp', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/anmelden/totp', ['code' => $this->currentCode($bert)])->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($bert);
        $this->assertSame($beta->id, (int) session(Tenancy::SESSION_KEY));
    }

    // 5. Mode switch

    public function test_admin_switches_to_multi_and_back(): void
    {
        $admin = $this->admin(['username' => 'chef']);
        $this->actingAs($admin);

        $this->post('/admin/mandanten/modus', ['mode' => 'multi'])->assertRedirect(route('backend.tenants'));
        Tenancy::flush();
        $this->assertTrue(Tenancy::isMulti());
        $this->assertNull(Setting::row(AppSettings::TENANCY_MODE)->tenant_id);
        $this->assertTrue((bool) $admin->fresh()->superadmin);
        $this->assertTrue($admin->fresh()->isSuperadmin());
        $this->assertNotEmpty(DatabaseBackup::list());
        $this->assertTrue($this->auditExists('tenancy.multi'));

        // Only one tenant: back to single mode.
        $this->post('/admin/mandanten/modus', ['mode' => 'single'])->assertRedirect(route('backend.tenants'));
        Tenancy::flush();
        $this->assertFalse(Tenancy::isMulti());
        $this->assertFalse((bool) $admin->fresh()->superadmin);
        $this->assertTrue($this->auditExists('tenancy.single'));
    }

    public function test_mode_switch_needs_an_admin(): void
    {
        $this->actingAs($this->kassier());
        $this->post('/admin/mandanten/modus', ['mode' => 'multi'])->assertForbidden();
        Tenancy::flush();
        $this->assertFalse(Tenancy::isMulti());
    }

    public function test_tenant_admin_cannot_switch_back_to_single(): void
    {
        [$alpha] = $this->multi();
        $admin = $this->in($alpha, fn () => $this->admin());

        $this->actingAs($admin)->post('/admin/mandanten/modus', ['mode' => 'single'])->assertForbidden();
        Tenancy::flush();
        $this->assertTrue(Tenancy::isMulti());
    }

    public function test_single_mode_needs_exactly_one_tenant(): void
    {
        [, , $super] = $this->multi();

        $this->actingAs($super);
        $this->from('/admin/mandanten')->post('/admin/mandanten/modus', ['mode' => 'single'])
            ->assertRedirect('/admin/mandanten')
            ->assertSessionHas('error', __('tenants.error_not_single'));
        Tenancy::flush();
        $this->assertTrue(Tenancy::isMulti());
        $this->assertTrue((bool) $super->fresh()->superadmin);
    }

    public function test_service_mode_switch_without_backup(): void
    {
        $admin = $this->admin();
        Tenancy::enableMulti($admin, backup: false);
        $this->assertTrue(Tenancy::isMulti());
        $this->assertSame([], DatabaseBackup::list());

        Tenancy::disableMulti($admin, backup: false);
        $this->assertFalse(Tenancy::isMulti());
        $this->assertFalse((bool) $admin->fresh()->superadmin);
    }

    // 6. Tenant management

    public function test_superadmin_creates_a_tenant_with_its_admin(): void
    {
        [, , $super] = $this->multi();

        $this->actingAs($super)->post('/admin/mandanten', [
            'name' => 'Club Zug',
            'slug' => ' Zug ',
            'admin_username' => 'Zugchef',
            'admin_email' => 'zug@example.org',
            'admin_password' => 'a-long-password-123',
            'admin_password_confirmation' => 'a-long-password-123',
        ])->assertRedirect(route('backend.tenants'));

        $tenant = Tenant::query()->where('slug', 'zug')->first();
        $this->assertNotNull($tenant);
        $this->assertSame('Club Zug', $tenant->name);
        $admin = User::forTenant($tenant)->where('username', 'zugchef')->first();
        $this->assertNotNull($admin);
        $this->assertSame(User::ROLE_ADMIN, $admin->role);
        $this->assertTrue((bool) $admin->must_change_password);
        $this->assertFalse((bool) $admin->superadmin);
        $this->assertTrue($this->auditExists('tenant.create'));
    }

    public function test_tenant_slug_is_validated(): void
    {
        [, , $super] = $this->multi();
        $data = [
            'name' => 'Club',
            'admin_username' => 'clubchef',
            'admin_email' => 'club@example.org',
            'admin_password' => 'a-long-password-123',
            'admin_password_confirmation' => 'a-long-password-123',
        ];

        $this->actingAs($super);
        $this->post('/admin/mandanten', $data + ['slug' => 'Bad Slug'])->assertSessionHasErrors('slug');
        $this->post('/admin/mandanten', $data + ['slug' => '-abc'])->assertSessionHasErrors('slug');
        $this->post('/admin/mandanten', $data + ['slug' => 'luks'])->assertSessionHasErrors('slug');
        $this->post('/admin/mandanten', $data + ['slug' => 'standard'])->assertSessionHasErrors('slug');
        $this->assertSame(2, Tenant::query()->count());
    }

    public function test_tenant_admin_cannot_manage_tenants(): void
    {
        [$alpha, $beta] = $this->multi();
        $admin = $this->in($beta, fn () => $this->admin());

        $this->actingAs($admin);
        $this->post('/admin/mandanten', [
            'name' => 'Club', 'slug' => 'club', 'admin_username' => 'clubchef', 'admin_email' => 'club@example.org',
            'admin_password' => 'a-long-password-123', 'admin_password_confirmation' => 'a-long-password-123',
        ])->assertForbidden();
        $this->delete("/admin/mandanten/{$alpha->id}", ['confirm' => 'standard'])->assertForbidden();
        $this->post("/admin/mandanten/{$alpha->id}/wechseln")->assertForbidden();
        $this->put("/admin/mandanten/{$alpha->id}", ['name' => 'Gekapert'])->assertForbidden();
        $this->assertSame(2, Tenant::query()->count());
        $this->assertSame('Standard', $alpha->fresh()->name);
    }

    public function test_tenant_admin_renames_the_own_tenant_but_not_the_slug(): void
    {
        [, $beta] = $this->multi();
        $admin = $this->in($beta, fn () => $this->admin());

        $this->actingAs($admin)->from('/admin/mandanten')
            ->put("/admin/mandanten/{$beta->id}", ['name' => 'Luks Neu', 'slug' => 'gekapert'])
            ->assertRedirect('/admin/mandanten')->assertSessionHas('status');
        $this->assertSame('Luks Neu', $beta->fresh()->name);
        $this->assertSame('luks', $beta->fresh()->slug);
    }

    public function test_superadmin_renames_a_tenant_with_slug(): void
    {
        [, $beta, $super] = $this->multi();

        $this->actingAs($super)->from('/admin/mandanten')
            ->put("/admin/mandanten/{$beta->id}", ['name' => 'Luzern', 'slug' => 'Luzern'])
            ->assertSessionHas('status');
        $this->assertSame('Luzern', $beta->fresh()->name);
        $this->assertSame('luzern', $beta->fresh()->slug);

        $this->put("/admin/mandanten/{$beta->id}", ['name' => 'Luzern', 'slug' => 'standard'])->assertSessionHasErrors('slug');
        $this->assertSame('luzern', $beta->fresh()->slug);
    }

    public function test_superadmin_deletes_a_tenant_with_all_its_data(): void
    {
        [$alpha, $beta, $super] = $this->multi();
        $this->in($beta, function () {
            $this->setPrice(200);
            $user = $this->user(['username' => 'bertbeta']);
            Ledger::consume($user, 1);
            Invitation::issue($user);
            Audit::log('marker.beta', null, [], ['n' => 1], $user);
        });
        $this->in($alpha, fn () => $this->user(['username' => 'annaalpha']));
        foreach (['users', 'transactions', 'settings', 'audit_logs', 'audit_chains', 'invitations'] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->where('tenant_id', $beta->id)->count(), $table);
        }

        $this->actingAs($super);
        $this->from('/admin/mandanten')->delete("/admin/mandanten/{$beta->id}", ['confirm' => 'falsch'])
            ->assertSessionHas('error', __('tenants.error_confirm'));
        $this->assertNotNull($beta->fresh());
        $this->assertSame([], DatabaseBackup::list());

        $this->from('/admin/mandanten')->delete("/admin/mandanten/{$beta->id}", ['confirm' => 'luks'])
            ->assertRedirect(route('backend.tenants'));
        $this->assertNull(Tenant::query()->find($beta->id));
        foreach (self::TENANT_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->where('tenant_id', $beta->id)->count(), $table);
        }
        $this->assertNotEmpty(DatabaseBackup::list());
        $this->assertTrue($this->auditExists('tenant.delete'));
        // The other tenant is untouched.
        $this->assertSame(2, User::forTenant($alpha)->count());
        $this->assertNull($this->in($alpha, fn () => Audit::verify()));
    }

    public function test_own_tenant_cannot_be_deleted(): void
    {
        [$alpha, , $super] = $this->multi();

        $this->actingAs($super)->from('/admin/mandanten')
            ->delete("/admin/mandanten/{$alpha->id}", ['confirm' => 'standard'])
            ->assertSessionHas('error', __('tenants.error_own'));
        $this->assertNotNull($alpha->fresh());
    }

    public function test_last_tenant_cannot_be_deleted(): void
    {
        $super = $this->admin();
        Tenancy::enableMulti($super, backup: false);
        $alpha = Tenant::query()->findOrFail(1);

        $this->actingAs($super->fresh())->from('/admin/mandanten')
            ->delete("/admin/mandanten/{$alpha->id}", ['confirm' => 'standard'])
            ->assertSessionHas('error', __('tenants.error_last'));
        $this->assertNotNull($alpha->fresh());

        $this->expectException(RuntimeException::class);
        Tenancy::delete($alpha, $this->in($alpha, fn () => $this->admin()), backup: false);
    }

    public function test_tenant_pages_need_multi_mode_for_management(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->put('/admin/mandanten/1', ['name' => 'X'])->assertForbidden();
        $this->post('/admin/mandanten/1/wechseln')->assertForbidden();
        $this->delete('/admin/mandanten/1', ['confirm' => 'standard'])->assertForbidden();
        $this->put("/admin/benutzer/{$admin->id}/superadmin", ['superadmin' => 1])->assertNotFound();
    }

    // Pages (Blade views of Admin > Mandanten and the layout)

    public function test_tenant_page_in_single_mode_offers_the_switch(): void
    {
        $this->actingAs($this->admin());
        $this->get('/admin/mandanten')->assertOk()
            ->assertSee(__('tenants.single_title'))->assertSee(__('tenants.enable_multi'))
            ->assertDontSee(__('tenants.create'));

        $this->actingAs($this->kassier());
        $this->get('/admin/mandanten')->assertForbidden();
    }

    public function test_tenant_page_for_the_superadmin_lists_all_tenants(): void
    {
        [, , $super] = $this->multi();

        $this->actingAs($super)->get('/admin/mandanten')->assertOk()
            ->assertSee('Standard')->assertSee('Luks')->assertSee('luks.localhost')
            ->assertSee(__('tenants.create'))->assertSee(__('tenants.superadmins'))->assertSee('chef')
            ->assertSee(__('tenants.disable_multi_blocked'));
    }

    public function test_tenant_page_for_a_tenant_admin_shows_only_the_own_tenant(): void
    {
        [, $beta] = $this->multi();
        $admin = $this->in($beta, fn () => $this->admin());

        $this->actingAs($admin)->get('/admin/mandanten')->assertOk()
            ->assertSee('Luks')->assertSee(__('tenants.rename'))
            ->assertDontSee(__('tenants.create'))->assertDontSee(__('tenants.switch'));
    }

    public function test_layout_shows_the_tenant_and_the_foreign_notice(): void
    {
        [, $beta, $super] = $this->multi();

        $this->actingAs($super);
        $this->get('/admin')->assertOk()
            ->assertSee(__('tenants.topbar', ['name' => 'Standard']))
            ->assertDontSee('data-testid="foreign-tenant"', false);

        $this->post("/admin/mandanten/{$beta->id}/wechseln");
        $this->get('/admin')->assertOk()
            ->assertSee(__('tenants.topbar', ['name' => 'Luks']))
            ->assertSee('data-testid="foreign-tenant"', false);
    }

    // 7. Superadmin switch

    public function test_superadmin_switches_into_another_tenant(): void
    {
        [$alpha, $beta, $super] = $this->multi();
        $this->in($alpha, fn () => $this->user(['username' => 'annaalpha', 'first_name' => 'Anna', 'last_name' => 'Alpha']));
        $this->in($beta, fn () => $this->user(['username' => 'bertbeta', 'first_name' => 'Bert', 'last_name' => 'Betaner']));

        $this->actingAs($super);
        $this->get('/admin/benutzer')->assertOk()->assertSee('annaalpha')->assertDontSee('bertbeta');

        $this->post("/admin/mandanten/{$beta->id}/wechseln")
            ->assertRedirect(route('backend.dashboard'))
            ->assertSessionHas(Tenancy::SESSION_KEY, $beta->id);

        $this->get('/admin/benutzer')->assertOk()->assertSee('bertbeta')->assertDontSee('annaalpha');
        $this->get('/admin/einstellungen')->assertOk();
        // No beer account in a foreign tenant.
        $this->get('/konto')->assertRedirect(route('backend.dashboard'));
        $this->assertTrue($super->fresh()->isAdmin());

        // Back home.
        $this->post("/admin/mandanten/{$alpha->id}/wechseln")->assertSessionHas(Tenancy::SESSION_KEY, $alpha->id);
        $this->get('/admin/benutzer')->assertOk()->assertSee('annaalpha');
        $this->get('/konto')->assertOk();
    }

    public function test_switched_superadmin_sees_the_backend_dashboard(): void
    {
        [, $beta, $super] = $this->multi();

        $this->actingAs($super);
        $this->post("/admin/mandanten/{$beta->id}/wechseln");
        $this->get('/admin')->assertOk();
    }

    public function test_settings_written_after_a_switch_go_to_that_tenant(): void
    {
        [$alpha, $beta, $super] = $this->multi();
        $this->in($alpha, fn () => $this->setPrice(150));

        $this->actingAs($super);
        $this->post("/admin/mandanten/{$beta->id}/wechseln");
        $this->in($beta, fn () => Setting::put(AppSettings::APP_NAME, 'Luks-Kasse'));

        $this->assertSame('Luks-Kasse', $this->in($beta, fn () => AppSettings::appName()));
        $this->assertNotSame('Luks-Kasse', $this->in($alpha, fn () => AppSettings::appName()));
        $this->assertSame(150, $this->in($alpha, fn () => AppSettings::beerPrice()));
    }

    public function test_installation_pages_are_for_superadmins_in_multi_mode(): void
    {
        [, $beta, $super] = $this->multi();
        $tenantAdmin = $this->in($beta, fn () => $this->admin());

        $this->actingAs($tenantAdmin);
        $this->get('/admin/wartung')->assertForbidden();
        $this->get('/admin/sso')->assertForbidden();
        $this->get('/admin/einstellungen')->assertOk();

        $this->actingAs($super);
        $this->get('/admin/wartung')->assertOk();
        $this->get('/admin/sso')->assertOk();
    }

    public function test_installation_pages_are_for_admins_in_single_mode(): void
    {
        $this->actingAs($this->admin());
        $this->get('/admin/wartung')->assertOk();
        $this->get('/admin/sso')->assertOk();
    }

    // 8. Superadmin appointment

    public function test_superadmin_appoints_and_removes_superadmins(): void
    {
        [$alpha, , $super] = $this->multi();
        $admin = $this->in($alpha, fn () => $this->admin(['username' => 'zweiter']));
        $kassier = $this->in($alpha, fn () => $this->kassier());

        $this->actingAs($super);
        $this->from("/admin/benutzer/{$admin->id}")->put("/admin/benutzer/{$admin->id}/superadmin", ['superadmin' => 1])
            ->assertRedirect("/admin/benutzer/{$admin->id}")->assertSessionHas('status', __('tenants.superadmin_on'));
        $this->assertTrue((bool) $admin->fresh()->superadmin);
        $this->assertTrue($this->auditExists('user.superadmin'));

        $this->from("/admin/benutzer/{$kassier->id}")->put("/admin/benutzer/{$kassier->id}/superadmin", ['superadmin' => 1])
            ->assertSessionHas('error', __('tenants.superadmin_needs_admin'));
        $this->assertFalse((bool) $kassier->fresh()->superadmin);

        // Two superadmins: one may be removed.
        $this->put("/admin/benutzer/{$admin->id}/superadmin", ['superadmin' => 0])->assertSessionHas('status', __('tenants.superadmin_off'));
        $this->assertFalse((bool) $admin->fresh()->superadmin);

        // The last superadmin stays.
        $this->put("/admin/benutzer/{$super->id}/superadmin", ['superadmin' => 0])->assertSessionHas('error', __('tenants.superadmin_last'));
        $this->assertTrue((bool) $super->fresh()->superadmin);
    }

    public function test_tenant_admin_cannot_appoint_superadmins(): void
    {
        [$alpha] = $this->multi();
        $admin = $this->in($alpha, fn () => $this->admin());
        $other = $this->in($alpha, fn () => $this->admin());

        $this->actingAs($admin)->put("/admin/benutzer/{$other->id}/superadmin", ['superadmin' => 1])->assertForbidden();
        $this->put("/admin/benutzer/{$admin->id}/superadmin", ['superadmin' => 1])->assertForbidden();
        $this->assertFalse((bool) $other->fresh()->superadmin);
        $this->assertFalse((bool) $admin->fresh()->superadmin);
    }

    public function test_role_change_clears_the_superadmin_flag(): void
    {
        [$alpha, , $super] = $this->multi();
        $admin = $this->in($alpha, fn () => $this->admin(['superadmin' => true]));

        $this->actingAs($super)->from("/admin/benutzer/{$admin->id}")
            ->put("/admin/benutzer/{$admin->id}/rolle", ['role' => User::ROLE_KASSIER])->assertSessionHas('status');
        $this->assertSame(User::ROLE_KASSIER, $admin->fresh()->role);
        $this->assertFalse((bool) $admin->fresh()->superadmin);
    }

    public function test_superadmin_flag_counts_only_in_multi_mode(): void
    {
        $admin = $this->admin(['superadmin' => true]);
        $this->assertFalse($admin->isSuperadmin());
        $this->assertFalse($admin->isInForeignTenant());

        Tenancy::enableMulti($this->admin(), backup: false);
        $this->assertTrue($admin->fresh()->isSuperadmin());
    }

    // 9. Audit chains

    public function test_each_tenant_has_its_own_audit_chain(): void
    {
        [$alpha, $beta] = $this->multi();
        $before = AuditLog::withoutGlobalScopes()->where('tenant_id', $beta->id)->count();
        foreach ([$alpha, $beta] as $tenant) {
            $this->in($tenant, function () use ($tenant) {
                foreach (range(1, 3) as $i) {
                    Audit::log('marker.'.$tenant->slug, null, [], ['n' => $i]);
                }
            });
        }

        $this->assertNull($this->in($alpha, fn () => Audit::verify()));
        $this->assertNull($this->in($beta, fn () => Audit::verify()));
        $this->assertSame(1, DB::table('audit_chains')->where('tenant_id', $alpha->id)->count());
        $this->assertSame(1, DB::table('audit_chains')->where('tenant_id', $beta->id)->count());
        $this->assertSame($before + 3, (int) DB::table('audit_chains')->where('tenant_id', $beta->id)->value('entries'));
        $this->assertSame($before + 3, AuditLog::withoutGlobalScopes()->where('tenant_id', $beta->id)->count());
        $this->assertSame(3, AuditLog::withoutGlobalScopes()->where('tenant_id', $beta->id)->where('action', 'marker.luks')->count());

        // Breaking one chain leaves the other intact.
        $id = AuditLog::withoutGlobalScopes()->where('tenant_id', $beta->id)->where('action', 'marker.luks')->orderBy('id')->value('id');
        DB::table('audit_logs')->where('id', $id)->update(['new_values' => '{"n":99}']);
        $this->assertSame(['id' => $id, 'reason' => 'changed'], $this->in($beta, fn () => Audit::verify()));
        $this->assertNull($this->in($alpha, fn () => Audit::verify()));
    }

    public function test_audit_page_lists_only_entries_of_the_own_tenant(): void
    {
        [$alpha, $beta] = $this->multi();
        $admin = $this->in($alpha, fn () => $this->admin());
        $this->in($alpha, fn () => Audit::log('marker.alpha'));
        $this->in($beta, fn () => Audit::log('marker.beta'));

        $this->assertSame(['marker.alpha'], $this->in($alpha, fn () => AuditLog::query()->where('action', 'like', 'marker.%')->pluck('action')->all()));

        $this->actingAs($admin)->get('/admin/protokoll')->assertOk()->assertSee('marker.alpha')->assertDontSee('marker.beta');
        $this->post('/admin/protokoll/pruefen')->assertSessionHas('status');
    }

    // 10. Public pages

    public function test_registration_on_the_base_host_needs_a_tenant(): void
    {
        $this->multi();

        $this->get('/registrieren')->assertOk()->assertSee(__('register.no_tenant'))->assertDontSee('name="username"', false);
    }

    public function test_registration_with_link_parameter_shows_the_form(): void
    {
        [, $beta] = $this->multi();
        $this->in($beta, function () {
            Setting::put(AppSettings::REGISTRATION_MODE, AppSettings::MODE_PASSWORD);
            Setting::put(AppSettings::REGISTRATION_PASSWORD, 'durst');
        });

        $this->get('/registrieren?mandant=luks')->assertOk()->assertSee('name="username"', false)->assertDontSee(__('register.no_tenant'));
    }

    public function test_invitation_link_resolves_the_tenant(): void
    {
        [, $beta] = $this->multi();
        $token = $this->in($beta, function () {
            [, $token] = Invitation::issue($this->user());

            return $token;
        });

        $this->get(Invitation::url($token))->assertOk()->assertSee('name="username"', false)->assertDontSee(__('register.no_tenant'));
        $this->assertSame($beta->id, (int) session(Tenancy::GUEST_KEY));
    }

    public function test_privacy_page_works_on_the_base_host(): void
    {
        $this->multi();

        $this->get('/datenschutz')->assertOk();
    }

    public function test_subdomain_shows_the_login_page_of_the_tenant(): void
    {
        [, $beta] = $this->multi();
        $this->in($beta, fn () => Setting::put(AppSettings::APP_NAME, 'Luks-Bierkasse'));

        $this->get('http://luks.localhost/')->assertOk()->assertSee('Luks-Bierkasse');
        $this->assertSame($beta->id, (int) session(Tenancy::GUEST_KEY));
    }

    public function test_subdomain_login_finds_only_that_tenants_account(): void
    {
        [$alpha, $beta] = $this->multi();
        $this->in($alpha, fn () => $this->user(['username' => 'hans']));
        $b = $this->in($beta, fn () => $this->user(['username' => 'hans']));

        $this->post('http://luks.localhost/anmelden', ['username' => 'hans', 'password' => 'secret-password'])
            ->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($b);
    }

    public function test_tenant_helpers(): void
    {
        [$alpha, $beta] = $this->multi();

        $this->assertSame('localhost', Tenancy::baseHost());
        $this->assertSame($beta->id, Tenancy::fromHost('luks.localhost')?->id);
        $this->assertSame($beta->id, Tenancy::fromHost('LUKS.localhost')?->id);
        $this->assertNull(Tenancy::fromHost('localhost'));
        $this->assertNull(Tenancy::fromHost('unbekannt.localhost'));
        $this->assertNull(Tenancy::fromHost('luks.example.org'));
        $this->assertSame($alpha->id, Tenancy::fromSlug(' Standard ')?->id);
        $this->assertNull(Tenancy::fromSlug('../x'));
        $this->assertSame('http://luks.localhost', Tenancy::hostUrl($beta));
        $this->assertSame(['a' => 1, 'mandant' => 'luks'], $this->in($beta, fn () => Tenancy::linkParams(['a' => 1])));
        $this->assertSame(['a' => 1], Tenancy::linkParams(['a' => 1]));
    }

    // 11. Password reset

    public function test_password_reset_mails_every_matching_account(): void
    {
        Notification::fake();
        [$alpha, $beta] = $this->multi();
        $a = $this->in($alpha, fn () => $this->user(['username' => 'hans', 'email' => 'hans.a@example.org']));
        $b = $this->in($beta, fn () => $this->user(['username' => 'hans', 'email' => 'hans.b@example.org']));

        $this->from('/passwort-vergessen')->post('/passwort-vergessen', ['login' => 'hans'])
            ->assertRedirect('/passwort-vergessen')->assertSessionHas('status', __('passwords.sent_generic'));

        Notification::assertSentTo($a, ResetPassword::class);
        Notification::assertSentTo($b, ResetPassword::class);
    }

    public function test_password_reset_with_link_parameter_mails_only_that_tenant(): void
    {
        Notification::fake();
        [$alpha, $beta] = $this->multi();
        $a = $this->in($alpha, fn () => $this->user(['username' => 'hans', 'email' => 'hans.a@example.org']));
        $b = $this->in($beta, fn () => $this->user(['username' => 'hans', 'email' => 'hans.b@example.org']));

        $this->post('/passwort-vergessen?mandant=luks', ['login' => 'hans'])->assertSessionHas('status', __('passwords.sent_generic'));

        Notification::assertSentTo($b, ResetPassword::class);
        Notification::assertNotSentTo($a, ResetPassword::class);
    }

    public function test_password_reset_link_works_for_the_account_of_its_tenant(): void
    {
        Notification::fake();
        [$alpha, $beta] = $this->multi();
        $a = $this->in($alpha, fn () => $this->user(['username' => 'hans', 'email' => 'hans.a@example.org']));
        $b = $this->in($beta, fn () => $this->user(['username' => 'hans', 'email' => 'hans.b@example.org']));

        $this->post('/passwort-vergessen', ['login' => 'hans.b@example.org']);
        $token = null;
        Notification::assertSentTo($b, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });
        Notification::assertNotSentTo($a, ResetPassword::class);

        $this->get('/passwort-zuruecksetzen/'.$token.'?email=hans.b%40example.org&mandant=luks')
            ->assertOk()->assertSee('name="mandant" value="luks"', false);
        $this->post('/passwort-zuruecksetzen', [
            'token' => $token, 'email' => 'hans.b@example.org', 'mandant' => 'luks',
            'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertRedirect(route('login'))->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('new-password-123', $b->fresh()->password));
        $this->assertTrue(Hash::check('secret-password', $a->fresh()->password));
    }

    /**
     * password_reset_tokens is keyed by the e-mail address alone: the same address in two tenants must not
     * make one tenant's reset link invalid.
     */
    public function test_password_reset_with_the_same_email_in_two_tenants(): void
    {
        Notification::fake();
        [$alpha, $beta] = $this->multi();
        $a = $this->in($alpha, fn () => $this->user(['username' => 'hans', 'email' => 'hans@example.org']));
        $b = $this->in($beta, fn () => $this->user(['username' => 'hans', 'email' => 'hans@example.org']));

        $this->post('/passwort-vergessen', ['login' => 'hans']);
        $tokens = [];
        foreach ([$a, $b] as $user) {
            Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$tokens, $user) {
                $tokens[$user->id] = $n->token;

                return true;
            });
        }

        $this->post('/passwort-zuruecksetzen', [
            'token' => $tokens[$a->id], 'email' => 'hans@example.org', 'mandant' => 'standard',
            'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-password-123', $a->fresh()->password));
    }

    // 12. Live fingerprint

    public function test_live_fingerprint_is_per_tenant(): void
    {
        [$alpha, $beta] = $this->multi();
        $anna = $this->in($alpha, fn () => $this->user());
        $bert = $this->in($beta, fn () => $this->user());

        $before = $this->in($alpha, fn () => LiveFingerprint::current());
        $this->in($beta, fn () => Ledger::consume($bert, 1));
        $this->assertSame($before, $this->in($alpha, fn () => LiveFingerprint::current()));

        $this->in($alpha, fn () => Ledger::consume($anna, 1));
        $this->assertNotSame($before, $this->in($alpha, fn () => LiveFingerprint::current()));
    }

    // 13. Setup wizard

    public function test_setup_with_multi_mode_makes_the_admin_superadmin(): void
    {
        $this->setupTmp = sys_get_temp_dir().'/bierkasse-setup-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->setupTmp.'/storage/app');
        File::ensureDirectoryExists($this->setupTmp.'/storage/framework/views');
        file_put_contents($this->setupTmp.'/.env', "APP_INSTALLED=false\nAPP_URL=http://localhost\n");
        file_put_contents($this->setupTmp.'/storage/app/setup-token.txt', "the-token\n");
        $this->app->useEnvironmentPath($this->setupTmp);
        $this->app->useStoragePath($this->setupTmp.'/storage');
        config(['app.installed' => false]);

        $this->post('/setup', [
            'token' => 'the-token',
            'app_url' => 'https://bier.example.org/',
            'driver' => 'sqlite',
            'database' => $this->setupTmp.'/db.sqlite',
            'admin_username' => 'chef',
            'admin_first_name' => 'Chef',
            'admin_email' => 'chef@example.org',
            'admin_password' => 'a-long-password-123',
            'admin_password_confirmation' => 'a-long-password-123',
            'tenancy' => 'multi',
        ])->assertRedirect(route('backend.settings'));

        Tenancy::flush();
        $this->assertTrue(Tenancy::isMulti());
        $admin = User::withoutTenant()->where('username', 'chef')->first();
        $this->assertNotNull($admin);
        $this->assertSame(1, (int) $admin->tenant_id);
        $this->assertTrue((bool) $admin->superadmin);
        $this->assertTrue($admin->isSuperadmin());
        $this->assertTrue($this->auditExists('tenancy.multi'));
    }
}
