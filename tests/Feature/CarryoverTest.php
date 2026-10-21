<?php

namespace Tests\Feature;

use App\Mail\KassierNotice;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BeerStock;
use App\Services\Ledger;
use App\Services\LedgerException;
use App\Services\Stats;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class CarryoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put(AppSettings::REGISTRATION_MODE, AppSettings::MODE_PASSWORD);
        Setting::put(AppSettings::REGISTRATION_PASSWORD, 'durst');
        Setting::put(AppSettings::BEER_PRICE, 150);
    }

    private function register(array $carryover = [], string $username = 'neu.user'): User
    {
        $this->post('/registrieren', array_merge([
            'code' => 'durst',
            'username' => $username,
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ], $carryover))->assertRedirect(route('account.dashboard'));
        auth()->logout();

        return User::query()->where('username', $username)->firstOrFail();
    }

    private function carryover(User $user): ?Transaction
    {
        return Transaction::query()->where('user_id', $user->id)->where('type', Transaction::TYPE_CARRYOVER)->first();
    }

    public function test_debt_in_beers_creates_pending_entry_and_mail(): void
    {
        Mail::fake();
        $this->kassier(['email' => 'kassier@example.org']);

        $user = $this->register(['carryover_direction' => 'debt', 'carryover_unit' => 'beers', 'carryover_value' => '12']);

        $tx = $this->carryover($user);
        $this->assertNotNull($tx);
        $this->assertSame(Transaction::STATUS_PENDING, $tx->status);
        $this->assertSame(-1800, $tx->amount);
        $this->assertSame(12, $tx->quantity);
        $this->assertSame(150, $tx->unit_price);
        $this->assertSame(0, $user->balance());

        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => str_contains($m->render(), 'Übertrag von der alten Strichliste: schuldet 12 Biere à CHF 1.50 (CHF 18.00)'));

        $this->actingAs($user)->get('/konto')->assertOk()
            ->assertSee('Übertrag ausstehend: CHF -18.00 – wartet auf Kassier');
    }

    public function test_credit_in_chf(): void
    {
        $user = $this->register(['carryover_direction' => 'credit', 'carryover_unit' => 'chf', 'carryover_value' => '20.50']);

        $tx = $this->carryover($user);
        $this->assertSame(2050, $tx->amount);
        $this->assertNull($tx->quantity);
        $this->assertSame('hat Guthaben CHF 20.50', $tx->carryoverText());
    }

    public function test_empty_value_creates_nothing(): void
    {
        Mail::fake();
        $this->kassier(['email' => 'kassier@example.org']);

        $user = $this->register(['carryover_direction' => 'debt', 'carryover_unit' => 'beers', 'carryover_value' => '']);

        $this->assertNull($this->carryover($user));
        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => ! str_contains($m->render(), 'Übertrag'));
    }

    public function test_invalid_values_are_rejected(): void
    {
        foreach ([['beers', '0'], ['beers', '501'], ['beers', '2.5'], ['chf', '0'], ['chf', '500.01'], ['chf', 'abc']] as [$unit, $value]) {
            $this->post('/registrieren', [
                'code' => 'durst', 'username' => 'neu.user', 'password' => 'password-123', 'password_confirmation' => 'password-123',
                'carryover_direction' => 'debt', 'carryover_unit' => $unit, 'carryover_value' => $value,
            ])->assertSessionHasErrors('carryover_value');
            RateLimiter::clear('register:127.0.0.1');
        }
        $this->assertSame(0, User::query()->count());
    }

    public function test_confirm_counts_in_balance_and_badge_but_not_stock_or_stats(): void
    {
        $kassier = $this->kassier();
        $user = $this->register(['carryover_direction' => 'debt', 'carryover_unit' => 'beers', 'carryover_value' => '12']);
        $stockBefore = BeerStock::current();
        $tx = $this->carryover($user);

        $this->assertSame(1, Ledger::pendingCount());
        $this->actingAs($kassier)->get('/backend')->assertOk()
            ->assertSee('Überträge von der alten Strichliste')->assertSee('schuldet 12 Biere à CHF 1.50 (CHF 18.00)');

        $this->post(route('backend.carryovers.confirm', $tx), ['amount' => '-18.00'])->assertSessionHas('status');

        $tx->refresh();
        $this->assertSame(Transaction::STATUS_CONFIRMED, $tx->status);
        $this->assertSame(12, $tx->quantity);
        $this->assertSame(-1800, $user->balance());
        $this->assertSame(0, Ledger::pendingCount());
        $this->assertSame($stockBefore, BeerStock::current());
        $this->assertSame(0, Stats::forUser($user)['total_beers']);
        $this->assertSame(0, Stats::global()['total_beers']);

        // Open items show the carried-over beers.
        $this->assertSame([['unit_price' => 150, 'quantity' => 12, 'amount' => 1800]], Ledger::openItems($user)['beers']);
    }

    public function test_kassier_can_adjust_amount(): void
    {
        $kassier = $this->kassier();
        $user = $this->register(['carryover_direction' => 'debt', 'carryover_unit' => 'beers', 'carryover_value' => '12']);
        $tx = $this->carryover($user);

        $this->actingAs($kassier)->post(route('backend.carryovers.confirm', $tx), ['amount' => '-15'])->assertSessionHas('status');

        $tx->refresh();
        $this->assertSame(-1500, $tx->amount);
        $this->assertNull($tx->quantity);
        $this->assertStringContainsString('schuldet 12 Biere', (string) $tx->note);
        $this->assertSame(-1500, $user->balance());
    }

    public function test_reject_keeps_balance(): void
    {
        $kassier = $this->kassier();
        $user = $this->register(['carryover_direction' => 'credit', 'carryover_unit' => 'chf', 'carryover_value' => '30']);
        $tx = $this->carryover($user);

        $this->actingAs($kassier)->post(route('backend.carryovers.reject', $tx))->assertSessionHas('status');

        $this->assertSame(Transaction::STATUS_REJECTED, $tx->refresh()->status);
        $this->assertSame(0, $user->balance());
        $this->assertSame(0, Ledger::pendingCount());
        $this->actingAs($kassier)->post(route('backend.carryovers.confirm', $tx))->assertSessionHas('error');
    }

    public function test_users_cannot_confirm_and_cannot_leave_while_pending(): void
    {
        $user = $this->register(['carryover_direction' => 'credit', 'carryover_unit' => 'chf', 'carryover_value' => '30']);
        $tx = $this->carryover($user);

        $this->actingAs($user)->post(route('backend.carryovers.confirm', $tx))->assertForbidden();
        $this->assertSame(Transaction::STATUS_PENDING, $tx->refresh()->status);

        $this->expectException(LedgerException::class);
        Ledger::assertCanLeave($user);
    }
}
