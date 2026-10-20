<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger;
use App\Services\LedgerException;
use App\Support\AppSettings;
use App\Support\FormToken;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_double_submitted_beer_is_booked_once(): void
    {
        $this->setDefaultLimit(10000);
        $user = $this->user();
        $this->actingAs($user);
        $data = $this->tokened('consume', ['quantity' => 2]);

        $this->post('/konto/bier', $data)->assertSessionHas('status');
        $this->post('/konto/bier', $data)->assertSessionHas('warning');
        $this->post('/konto/bier', ['quantity' => 2])->assertSessionHas('warning');

        $this->assertSame(1, Transaction::query()->where('type', 'consumption')->count());
    }

    public function test_double_submitted_deposit_report_is_booked_once(): void
    {
        $this->actingAs($this->user());
        $data = $this->tokened('deposit', ['amount' => '10', 'method' => 'cash']);

        $this->post('/konto/einzahlung', $data)->assertSessionHas('status');
        $this->post('/konto/einzahlung', $data)->assertSessionHas('warning');

        $this->assertSame(1, Transaction::query()->where('type', 'deposit')->count());
    }

    public function test_dashboard_renders_fresh_form_tokens(): void
    {
        $this->actingAs($this->user());
        $html = (string) $this->get('/konto')->getContent();

        // Beer, beer for a guest, deposit report.
        $this->assertSame(3, substr_count($html, 'name="'.FormToken::FIELD.'"'));
    }

    public function test_payout_cannot_be_confirmed_twice(): void
    {
        $kassier = $this->kassier();
        $user = $this->user();
        Ledger::bookDeposit($user, 1200, 'cash', $kassier);
        $payout = Ledger::requestPayout($user);
        $stale = Transaction::query()->findOrFail($payout->id);

        Ledger::confirmPayout($payout, $kassier);
        $this->assertSame(-1200, $payout->fresh()->amount);

        try {
            Ledger::confirmPayout($stale, $kassier, 'twint');
            $this->fail('Second confirmation must fail.');
        } catch (LedgerException) {
        }

        $payout->refresh();
        $this->assertSame(-1200, $payout->amount);
        $this->assertSame('cash', $payout->method);
        $this->assertSame(0, $user->balance());

        $this->actingAs($kassier)->post("/backend/auszahlungen/{$payout->id}/bestaetigen")->assertSessionHas('error');
    }

    public function test_deposit_cannot_be_confirmed_or_rejected_twice(): void
    {
        $kassier = $this->kassier();
        $user = $this->user();
        $deposit = Ledger::reportDeposit($user, 1000, 'cash');
        $stale = Transaction::query()->findOrFail($deposit->id);
        $stale2 = Transaction::query()->findOrFail($deposit->id);

        Ledger::confirmDeposit($deposit, $kassier);

        $this->expectException(LedgerException::class);
        try {
            Ledger::confirmDeposit($stale, $kassier);
        } finally {
            try {
                Ledger::rejectDeposit($stale2, $kassier);
            } catch (LedgerException) {
            }
            $this->assertSame('confirmed', $deposit->fresh()->status);
            $this->assertSame(1000, $user->balance());
        }
    }

    public function test_no_bookings_for_left_users(): void
    {
        $kassier = $this->kassier();
        $user = $this->user();
        $deposit = Ledger::reportDeposit($user, 500, 'cash');
        $user->forceFill(['status' => User::STATUS_LEFT])->save();

        $this->actingAs($kassier);
        $this->post("/backend/benutzer/{$user->id}/einzahlung", ['amount' => '10', 'method' => 'cash'])->assertSessionHas('error');
        $this->post("/backend/benutzer/{$user->id}/korrektur", ['amount' => '5', 'note' => 'x'])->assertSessionHas('error');
        $this->post("/backend/einzahlungen/{$deposit->id}/bestaetigen")->assertSessionHas('error');

        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame(0, $user->balance());

        // A stale model with status "active" is refreshed inside the lock.
        $stale = User::query()->findOrFail($user->id);
        $stale->status = User::STATUS_ACTIVE;
        $stale->syncOriginal();
        $this->expectException(LedgerException::class);
        Ledger::bookDeposit($stale, 100, 'cash', $kassier);
    }

    public function test_deactivation_requires_settled_account(): void
    {
        $kassier = $this->kassier();
        $user = $this->user();
        Ledger::bookDeposit($user, 300, 'cash', $kassier);
        $this->actingAs($kassier);

        $this->post("/backend/benutzer/{$user->id}/status", ['active' => 0])->assertSessionHas('error');
        $this->assertTrue($user->fresh()->isActive());

        Ledger::correction($user, -300, 'Ausgleich', $kassier);
        $pending = Ledger::reportDeposit($user, 100, 'cash');
        $this->post("/backend/benutzer/{$user->id}/status", ['active' => 0])->assertSessionHas('error');

        Ledger::rejectDeposit($pending, $kassier);
        $this->post("/backend/benutzer/{$user->id}/status", ['active' => 0])->assertSessionHas('status');
        $this->assertFalse($user->fresh()->isActive());
    }

    public function test_admins_cannot_lock_out_the_last_admin(): void
    {
        $first = $this->admin();
        $second = $this->admin();

        // Kassiers cannot touch admins at all.
        $this->actingAs($this->kassier());
        $this->post("/backend/benutzer/{$first->id}/status", ['active' => 0])->assertForbidden();

        // With two active admins one may deactivate the other ...
        $this->actingAs($second);
        $this->post("/backend/benutzer/{$first->id}/status", ['active' => 0])->assertSessionHas('status');
        $this->assertFalse($first->fresh()->isActive());

        // ... but the remaining (last) admin cannot deactivate the own account.
        $this->post("/backend/benutzer/{$second->id}/status", ['active' => 0])->assertForbidden();
        $this->assertSame(1, User::query()->where('role', 'admin')->where('status', 'active')->count());
    }

    public function test_reset_link_uses_app_url_not_host_header(): void
    {
        Notification::fake();
        $user = $this->user(['username' => 'hans', 'email' => 'hans@example.org']);

        $this->post('http://evil.example/passwort-vergessen', ['login' => 'hans']);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;

            return str_starts_with($url, rtrim((string) config('app.url'), '/').'/passwort-zuruecksetzen/')
                && ! str_contains($url, 'evil.example');
        });
    }

    public function test_registration_checks_code_before_username(): void
    {
        Setting::put(AppSettings::REGISTRATION_PASSWORD, 'durst');
        $this->user(['username' => 'taken']);

        $response = $this->post('/registrieren', [
            'code' => 'falsch', 'username' => 'taken', 'password' => 'password-123', 'password_confirmation' => 'password-123',
        ]);
        $response->assertSessionHasErrors('code')->assertSessionDoesntHaveErrors('username');
    }

    public function test_registration_validation_failures_count_towards_rate_limit(): void
    {
        Setting::put(AppSettings::REGISTRATION_PASSWORD, 'durst');
        $this->user(['username' => 'taken']);
        $data = ['code' => 'durst', 'username' => 'taken', 'password' => 'password-123', 'password_confirmation' => 'password-123'];

        for ($i = 0; $i < 5; $i++) {
            $this->post('/registrieren', $data)->assertSessionHasErrors('username');
        }
        $this->post('/registrieren', ['username' => 'frei'] + $data)->assertSessionHasErrors('code');
        $this->assertNull(User::query()->where('username', 'frei')->first());
    }

    public function test_open_items_add_up_to_total(): void
    {
        $this->setDefaultLimit(100000);
        $kassier = $this->kassier();
        $user = $this->user();

        $this->setPrice(150);
        Ledger::consume($user, 3);                 // 4.50
        $this->travel(1)->minutes();
        $this->setPrice(200);
        Ledger::consume($user, 2);                 // 4.00
        $this->travel(1)->minutes();
        Ledger::correction($user, -120, 'Glas', $kassier);
        $this->travel(1)->minutes();
        Ledger::bookDeposit($user, 500, 'cash', $kassier); // covers 3 x 1.50 and 0.50 of a 2.00 beer

        $open = Ledger::openItems($user);
        $this->assertSame(470, $open['total']);
        $this->assertSame([['unit_price' => 200, 'quantity' => 1, 'amount' => 200]], $open['beers']);
        $this->assertSame([['unit_price' => 200, 'amount' => 150]], $open['partial']);
        $this->assertSame(120, $open['other']);
        $this->assertSame($open['total'], array_sum(array_column($open['beers'], 'amount')) + array_sum(array_column($open['partial'], 'amount')) + $open['other']);

        $this->actingAs($user)->get('/konto')->assertOk()
            ->assertSee('Offen: CHF 4.70')
            ->assertSee('Rest von 1 Bier à CHF 2.00: CHF 1.50')
            ->assertSee('Übrige Beträge (Korrekturen): CHF 1.20');
    }
}
