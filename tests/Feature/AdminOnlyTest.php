<?php

namespace Tests\Feature;

use App\Mail\KassierNotice;
use App\Models\GuestSale;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger;
use App\Services\LedgerException;
use App\Services\Rounds;
use App\Services\Stats;
use App\Support\AppSettings;
use App\Support\RegistrationLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * "Nur Verwaltung" (users.admin_only): admin/kassier accounts without a beer account.
 */
class AdminOnlyTest extends TestCase
{
    use RefreshDatabase;

    // --- Setting the flag ---------------------------------------------------------------

    public function test_admin_sets_flag_in_backend_for_others_and_self(): void
    {
        $admin = $this->admin();
        $kassier = $this->kassier();
        $this->actingAs($admin);

        $this->get("/backend/benutzer/{$kassier->id}")->assertOk()->assertSee(route('backend.users.admin-only', $kassier));
        $this->put("/backend/benutzer/{$kassier->id}/nur-verwaltung", ['admin_only' => 1])->assertSessionHas('status');
        $this->assertTrue($kassier->fresh()->isAdminOnly());

        $this->put("/backend/benutzer/{$admin->id}/nur-verwaltung", ['admin_only' => 1])->assertSessionHas('status');
        $this->assertTrue($admin->fresh()->isAdminOnly());

        $this->put("/backend/benutzer/{$kassier->id}/nur-verwaltung", ['admin_only' => 0])->assertSessionHas('status');
        $this->assertFalse($kassier->fresh()->admin_only);
    }

    public function test_kassier_cannot_set_flag_for_others_in_backend(): void
    {
        $other = $this->kassier();
        $this->actingAs($this->kassier());

        $this->put("/backend/benutzer/{$other->id}/nur-verwaltung", ['admin_only' => 1])->assertForbidden();
        $this->assertFalse($other->fresh()->admin_only);
    }

    public function test_flag_only_for_admin_or_kassier_role(): void
    {
        $user = $this->user();
        $this->actingAs($this->admin());

        $this->put("/backend/benutzer/{$user->id}/nur-verwaltung", ['admin_only' => 1])
            ->assertSessionHas('error', __('account.admin_only_role'));
        $this->assertFalse($user->fresh()->admin_only);
        $this->get("/backend/benutzer/{$user->id}")->assertOk()->assertDontSee(route('backend.users.admin-only', $user));
    }

    public function test_switching_role_to_user_resets_flag(): void
    {
        $kassier = $this->kassier(['admin_only' => true]);
        $this->actingAs($this->admin());

        $this->put("/backend/benutzer/{$kassier->id}/rolle", ['role' => 'user'])->assertSessionHas('status');
        $kassier->refresh();
        $this->assertSame(User::ROLE_USER, $kassier->role);
        $this->assertFalse($kassier->admin_only);
    }

    public function test_enabling_refused_with_balance_or_pending_bookings(): void
    {
        $admin = $this->admin();
        $kassier = $this->kassier();
        Ledger::bookDeposit($kassier, 500, 'cash', $admin);
        $this->actingAs($admin);

        $this->put("/backend/benutzer/{$kassier->id}/nur-verwaltung", ['admin_only' => 1])
            ->assertSessionHas('error', __('account.admin_only_settle_first'));
        $this->assertFalse($kassier->fresh()->admin_only);

        // Balance 0 again, but a pending deposit report: still refused.
        Ledger::correction($kassier, -500, 'Ausgleich', $admin);
        Ledger::reportDeposit($kassier, 1000, 'cash');
        $this->put("/backend/benutzer/{$kassier->id}/nur-verwaltung", ['admin_only' => 1])
            ->assertSessionHas('error', __('account.admin_only_settle_first'));
        $this->assertFalse($kassier->fresh()->admin_only);

        // Self-service: same guard (negative balance).
        $this->setDefaultLimit(10000);
        Ledger::cancelDeposit(Transaction::query()->pending()->sole(), $kassier);
        Ledger::consume($kassier, 1);
        $this->actingAs($kassier)->post('/konto/nur-verwaltung', ['admin_only' => 1])
            ->assertSessionHas('error', __('account.admin_only_settle_first'));
        $this->assertFalse($kassier->fresh()->admin_only);
    }

    public function test_kassier_and_admin_toggle_flag_themselves_in_settings(): void
    {
        foreach ([$this->kassier(), $this->admin()] as $account) {
            $this->actingAs($account);
            $this->get('/konto/einstellungen')->assertOk()->assertSee(route('account.admin-only'));

            $this->post('/konto/nur-verwaltung', ['admin_only' => 1])
                ->assertRedirect(route('account.settings'))->assertSessionHas('status', __('account.admin_only_on'));
            $this->assertTrue($account->fresh()->isAdminOnly());

            // The settings page stays reachable: it is the way back.
            $this->get('/konto/einstellungen')->assertOk()->assertSee(route('account.admin-only'))
                ->assertDontSee(route('account.leave'))->assertDontSee(route('account.stats'));

            $this->post('/konto/nur-verwaltung', ['admin_only' => 0])->assertSessionHas('status', __('account.admin_only_off'));
            $this->assertFalse($account->fresh()->admin_only);
            $this->get('/konto')->assertOk();
        }
    }

    public function test_plain_user_cannot_toggle_flag(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $this->get('/konto/einstellungen')->assertOk()->assertDontSee(route('account.admin-only'));
        $this->post('/konto/nur-verwaltung', ['admin_only' => 1])->assertForbidden();
        $this->assertFalse($user->fresh()->admin_only);
    }

    public function test_registration_never_sets_flag(): void
    {
        $user = new User;
        $user->fill(['username' => 'neu', 'role' => User::ROLE_ADMIN, 'admin_only' => true]);
        $this->assertFalse($user->admin_only);
    }

    // --- Effects --------------------------------------------------------------------------

    public function test_dashboard_and_pages_redirect_to_backend(): void
    {
        $this->actingAs($this->admin(['admin_only' => true]));

        $this->get('/konto')->assertRedirect(route('backend.dashboard'));
        $this->get('/konto/verlauf')->assertRedirect(route('backend.dashboard'))->assertSessionHas('warning');
        $this->get('/konto/verlassen')->assertRedirect(route('backend.dashboard'));
        $this->get('/backend')->assertOk();
        $this->get('/konto/einstellungen')->assertOk();
        $this->get('/konto/feedback')->assertOk();
        $this->get('/konto/einladen')->assertOk();
        $this->get('/statistik')->assertOk();
    }

    public function test_every_money_action_is_blocked_server_side(): void
    {
        $admin = $this->admin();
        $colleague = $this->user();
        $kassier = $this->kassier(['admin_only' => true]);
        $tx = Ledger::reportDeposit($colleague, 500, 'cash');
        $this->actingAs($kassier);

        $this->post('/konto/bier', $this->tokened('consume', ['quantity' => 1]))->assertForbidden();
        $this->post("/konto/bier/{$tx->id}/rueckgaengig")->assertForbidden();
        $this->post('/konto/gast', $this->tokened('guest-booking', ['guest_quantity' => 1, 'guest_method' => 'cash']))->assertForbidden();
        $sale = GuestSale::query()->create(['beers' => 1, 'unit_price' => 150, 'amount' => 150, 'method' => 'cash', 'booked_by' => $kassier->id]);
        $this->post("/konto/gast/{$sale->id}/rueckgaengig")->assertForbidden();
        $this->post('/konto/runde', $this->tokened('round', ['recipients' => [$colleague->id], 'extra' => 0]))->assertForbidden();
        $this->post('/konto/einzahlung', $this->tokened('deposit', ['amount' => '10', 'method' => 'cash']))->assertForbidden();
        $this->put("/konto/einzahlung/{$tx->id}", ['amount' => '10', 'method' => 'cash'])->assertForbidden();
        $this->delete("/konto/einzahlung/{$tx->id}")->assertForbidden();
        $this->post('/konto/verlassen', ['choice' => 'donate', 'confirm' => 1])->assertForbidden();

        $this->assertSame(0, Transaction::query()->where('user_id', $kassier->id)->count());
        $this->assertSame(1, GuestSale::query()->count());
        $this->assertTrue($kassier->fresh()->isActive());
        $this->assertSame(Transaction::STATUS_PENDING, $tx->fresh()->status);

        // Backend bookings on the account are refused as well (no money stranded).
        $this->actingAs($admin);
        $this->post("/backend/benutzer/{$kassier->id}/einzahlung", ['amount' => '10', 'method' => 'cash'])
            ->assertSessionHas('error', __('account.admin_only_blocked'));
        $this->post("/backend/benutzer/{$kassier->id}/korrektur", ['amount' => '5', 'note' => 'x'])
            ->assertSessionHas('error', __('account.admin_only_blocked'));
        $this->assertSame(0, Transaction::query()->where('user_id', $kassier->id)->count());

        $this->expectException(LedgerException::class);
        Ledger::consume($kassier, 1);
    }

    public function test_nav_hidden_and_logo_points_to_backend(): void
    {
        $this->actingAs($this->admin(['admin_only' => true]));

        $this->get('/backend')->assertOk()
            ->assertDontSee('href="'.route('account.dashboard').'"', false)
            ->assertSee('class="brand" href="'.route('backend.dashboard').'"', false);

        $this->actingAs($this->admin());
        $this->get('/backend')->assertOk()->assertSee('class="brand" href="'.route('account.dashboard').'"', false);
    }

    public function test_nav_shows_invite_and_flyer_qr_for_admin_only(): void
    {
        Setting::putMany([
            AppSettings::REGISTRATION_MODE => AppSettings::MODE_INVITE,
        ]);
        $admin = $this->admin(['admin_only' => true]);
        $this->actingAs($admin);
        $this->get('/backend')->assertSee('href="'.route('account.invite').'"', false)->assertSee('Einladen');
        // Invite mode: only one-time links, no reusable flyer QR.
        $this->get('/konto/einladen')->assertOk()->assertDontSee('Flyer-QR zeigen');

        Setting::putMany([
            AppSettings::REGISTRATION_MODE => AppSettings::MODE_PASSWORD,
            AppSettings::REGISTRATION_PASSWORD => 'durst',
        ]);
        $this->get('/konto/einladen')->assertOk()->assertSee('Flyer-QR zeigen')
            ->assertSee(e(RegistrationLink::url(true)), false);

        // Plain users never see the flyer QR; closed mode hides the nav entry.
        $this->actingAs($this->user());
        $this->get('/konto/einladen')->assertOk()->assertDontSee('Flyer-QR zeigen');
        Setting::putMany([AppSettings::REGISTRATION_MODE => AppSettings::MODE_CLOSED]);
        $this->actingAs($admin);
        $this->get('/backend')->assertDontSee('href="'.route('account.invite').'"', false);
    }

    public function test_login_redirects_to_backend(): void
    {
        $this->admin(['username' => 'chef', 'admin_only' => true]);

        $this->post('/anmelden', ['username' => 'chef', 'password' => 'secret-password'])
            ->assertRedirect(route('backend.dashboard'));
        $this->get('/')->assertRedirect(route('backend.dashboard'));
    }

    public function test_excluded_from_round_colleagues_and_recipients(): void
    {
        $giver = $this->user();
        $adminOnly = $this->admin(['admin_only' => true]);
        $normalAdmin = $this->admin();
        Ledger::bookDeposit($giver, 2000, 'cash', $normalAdmin);

        $ids = Rounds::colleagues($giver)->pluck('id')->all();
        $this->assertNotContains($adminOnly->id, $ids);
        $this->assertContains($normalAdmin->id, $ids);

        $this->actingAs($giver)->post('/konto/runde', $this->tokened('round', ['recipients' => [$adminOnly->id], 'extra' => 0]))
            ->assertSessionHas('error', __('round.recipient_invalid'));
        $this->assertSame(0, Transaction::query()->where('type', Transaction::TYPE_ROUND)->count());
    }

    public function test_excluded_from_ranking_and_minus_list_badge_in_user_list(): void
    {
        $this->setDefaultLimit(10000);
        $admin = $this->admin(['first_name' => 'Ada', 'last_name' => 'Chef']);
        Ledger::consume($admin, 2);
        Ledger::bookDeposit($admin, Ledger::balance($admin) * -1, 'cash', $admin);
        $admin->forceFill(['admin_only' => true])->save();

        $stats = Stats::global();
        $this->assertSame(2, $stats['total_beers']);
        $this->assertNotContains($admin->id, array_column($stats['ranking'], 'user_id'));

        $this->actingAs($admin);
        $this->get('/backend/benutzer')->assertOk()->assertSee(__('account.admin_only'))->assertSee('Ada Chef');
        $this->get("/backend/benutzer/{$admin->id}")->assertOk()->assertSee('data-testid="admin-only-note"', false)
            ->assertDontSee(route('backend.users.deposit', $admin));
    }

    public function test_still_admin_in_backend_and_gets_mails(): void
    {
        Mail::fake();
        $admin = $this->admin(['email' => 'chef@example.org', 'admin_only' => true]);
        $this->actingAs($admin);

        foreach (['/backend', '/backend/benutzer', '/backend/einstellungen', '/backend/wartung', '/backend/feedback'] as $url) {
            $this->get($url)->assertOk();
        }

        Ledger::reportDeposit($this->user(), 1000, 'cash');
        Mail::assertSent(KassierNotice::class, fn ($m) => $m->hasTo('chef@example.org'));

        // Still counts as the last admin.
        $this->put("/backend/benutzer/{$admin->id}/rolle", ['role' => 'user'])->assertSessionHas('error', __('users.last_admin'));
        $this->assertTrue($admin->fresh()->isAdminOnly());
    }
}
