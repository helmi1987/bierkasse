<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ledger;
use App\Services\LedgerException;
use App\Services\Rounds;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_kassier_locks_user_with_debt_and_open_items(): void
    {
        $kassier = $this->kassier();
        $user = $this->user(['overdraft_limit' => 1000]);
        Ledger::consume($user, 2);
        Ledger::reportDeposit($user, 100, 'cash');
        $this->actingAs($kassier);

        $this->post(route('backend.users.lock', $user), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertTrue($user->fresh()->isActive());

        $this->post(route('backend.users.lock', $user), ['reason' => 'Missbrauch'])->assertSessionHas('status', __('users.locked_done'));
        $fresh = $user->fresh();
        $this->assertTrue($fresh->isLocked());
        $this->assertSame('Missbrauch', $fresh->locked_reason);
        $this->assertSame($kassier->id, $fresh->locked_by);
        $this->assertSame(-300, $fresh->balance());
        $audit = AuditLog::query()->where('action', 'user.lock')->firstOrFail();
        $this->assertSame('Missbrauch', json_decode($audit->new_values, true)['locked_reason']);

        // Detail page with reason, list with badge, still in "Im Minus".
        $this->get(route('backend.users.show', $user))->assertOk()->assertSee('Grund: Missbrauch')->assertSee(__('users.unlock'));
        $this->get(route('backend.users.index'))->assertOk()->assertSee($user->displayName())->assertSee(__('users.locked_badge'));
        $this->get(route('backend.dashboard'))->assertOk()->assertSee(__('users.locked_badge'));

        // Kassier still books deposits and corrections.
        $this->post(route('backend.users.deposit', $user), ['amount' => '3.00', 'method' => 'cash'])->assertSessionHas('status');
        $this->post(route('backend.users.correction', $user), ['amount' => '-0.50', 'note' => 'Fehlbetrag'])->assertSessionHas('status');
        $this->assertSame(-50, $user->fresh()->balance());
        // Deactivating a locked account is refused (unlock first).
        $this->post(route('backend.users.status', $user), ['active' => 0])->assertSessionHas('error', __('users.status_locked'));
    }

    public function test_locked_user_cannot_log_in_and_session_is_ended(): void
    {
        Setting::put(AppSettings::LEGAL_EMAIL, 'kasse@example.org');
        $user = $this->user(['username' => 'hans']);
        $this->actingAs($user);
        $this->get('/konto')->assertOk();

        $user->forceFill(['status' => User::STATUS_LOCKED])->save();
        $this->get('/konto')->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username' => 'Konto gesperrt, bitte an kasse@example.org wenden.']);
        $this->assertGuest();

        // Wrong password: normal message, the lock is not revealed.
        $this->post('/anmelden', ['username' => 'hans', 'password' => 'falsch'])->assertSessionHasErrors(['username' => __('auth.failed')]);
        $this->post('/anmelden', ['username' => 'hans', 'password' => 'secret-password'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username' => 'Konto gesperrt, bitte an kasse@example.org wenden.']);
        $this->assertGuest();
        $this->assertTrue(AuditLog::query()->where('action', 'auth.login_locked')->exists());
    }

    public function test_locked_during_totp_step_is_refused(): void
    {
        $user = $this->withTotp($this->user(['username' => 'hans']));
        $this->post('/anmelden', ['username' => 'hans', 'password' => 'secret-password'])->assertRedirect(route('login.totp'));
        $user->forceFill(['status' => User::STATUS_LOCKED])->save();

        $this->post('/anmelden/totp', ['code' => $this->currentCode($user)])->assertRedirect(route('login'))->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_kassier_cannot_lock_admin_and_nobody_locks_himself(): void
    {
        $admin = $this->admin();
        $kassier = $this->kassier();

        $this->actingAs($kassier);
        $this->post(route('backend.users.lock', $admin), ['reason' => 'x'])->assertForbidden();
        $this->post(route('backend.users.lock', $kassier), ['reason' => 'x'])->assertForbidden();
        $this->assertTrue($admin->fresh()->isActive());

        $this->actingAs($admin);
        $this->post(route('backend.users.lock', $admin), ['reason' => 'x'])->assertForbidden();
        $this->post(route('backend.users.lock', $kassier), ['reason' => 'x'])->assertSessionHas('status');
        $this->assertTrue($kassier->fresh()->isLocked());
    }

    public function test_user_role_cannot_lock(): void
    {
        $target = $this->user();
        $this->actingAs($this->user());

        $this->post(route('backend.users.lock', $target), ['reason' => 'x'])->assertForbidden();
    }

    public function test_unlock_makes_the_account_active_again(): void
    {
        $kassier = $this->kassier();
        $user = User::factory()->locked()->create(['username' => 'hans', 'password' => 'secret-password']);
        $this->actingAs($kassier);

        $this->post(route('backend.users.unlock', $user))->assertSessionHas('status', __('users.unlocked_done'));
        $fresh = $user->fresh();
        $this->assertTrue($fresh->isActive());
        $this->assertNull($fresh->locked_reason);
        $this->assertTrue(AuditLog::query()->where('action', 'user.unlock')->exists());

        $this->post('/abmelden');
        $this->post('/anmelden', ['username' => 'hans', 'password' => 'secret-password'])->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($fresh);
    }

    public function test_locked_users_are_no_round_or_gift_recipients(): void
    {
        $giver = $this->user();
        $locked = User::factory()->locked()->create();

        $this->assertFalse(Rounds::colleagues($giver)->contains('id', $locked->id));
        $this->setDefaultLimit(1000);
        $this->expectException(LedgerException::class);
        Ledger::giveBeers($giver, [$locked->id], 1);
    }
}
