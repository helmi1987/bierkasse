<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\GuestSale;
use App\Models\Setting;
use App\Models\User;
use App\Services\BeerStock;
use App\Services\CashBook;
use App\Services\Stats;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Beers for guests booked by a logged-in user on the dashboard.
 */
class GuestBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Setting::put(AppSettings::TWINT_PHONE, '079 123 45 67');
        $this->stock(30);
    }

    public function test_user_booking_is_on_by_default_and_independent_of_the_public_page(): void
    {
        $this->assertTrue(AppSettings::guestUserBooking());
        $this->assertFalse(AppSettings::guestOpen());

        $this->actingAs($this->user())->get('/konto')->assertOk()
            ->assertSee('Bier für Gast')->assertSee('name="guest_method" value="twint"', false)
            ->assertSee('guest-twint-phone', false);
    }

    public function test_user_books_cash_and_twint_without_touching_the_balance(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post('/konto/gast', $this->sale(['guest_quantity' => 3, 'guest_name' => 'Fritz']))
            ->assertSessionHas('status', '3 Biere für Gast erfasst – bitte CHF 4.50 bar in die Kasse legen.');
        $this->post('/konto/gast', $this->sale(['guest_quantity' => 1, 'guest_method' => 'twint']))
            ->assertSessionHas('status', '1 Bier für Gast erfasst – bitte CHF 1.50 per TWINT an 079 123 45 67 senden.');

        $this->assertDatabaseHas('guest_sales', ['name' => 'Fritz', 'beers' => 3, 'amount' => 450, 'method' => 'cash', 'booked_by' => $user->id]);
        $this->assertDatabaseHas('guest_sales', ['name' => null, 'beers' => 1, 'amount' => 150, 'method' => 'twint', 'booked_by' => $user->id]);
        $this->assertSame(0, $user->refresh()->balance());
        $this->assertSame(0, $user->transactions()->count());

        $cash = CashBook::balance();
        $this->assertSame(-3000 + 450, $cash['cash']);
        $this->assertSame(150, $cash['twint']);
        $this->assertSame(26, BeerStock::current());

        // Statistics: guests, not the booking user.
        $stats = Stats::global();
        $this->assertSame([['user_id' => 0, 'name' => 'Gäste', 'beers' => 4]], $stats['ranking']);
        $this->assertSame(0, Stats::forUser($user)['total_beers']);

        $this->get('/konto')->assertOk()->assertSee('Fritz');
        $this->actingAs($this->kassier())->get('/backend/gaeste')->assertOk()->assertSee('gebucht von '.$user->username);
    }

    public function test_booking_works_with_overdraft_limit_reached(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post('/konto/bier', $this->tokened('consume', ['quantity' => 1]))->assertSessionHas('error');

        $this->post('/konto/gast', $this->sale())->assertSessionHas('status');
        $this->assertSame(1, GuestSale::query()->count());
        $this->assertSame(0, $user->refresh()->balance());
    }

    public function test_twint_only_with_twint_number(): void
    {
        Setting::put(AppSettings::TWINT_PHONE, '');
        $this->actingAs($this->user())->get('/konto')->assertOk()->assertDontSee('name="guest_method" value="twint"', false);
        $this->post('/konto/gast', $this->sale(['guest_method' => 'twint']))->assertSessionHasErrors('guest_method');
        $this->assertSame(0, GuestSale::query()->count());
    }

    public function test_twint_switch_and_name_without_number(): void
    {
        Setting::put(AppSettings::TWINT_PHONE, '');
        Setting::put(AppSettings::TWINT_NAME, 'Phlipp Arnold');
        Setting::put(AppSettings::TWINT_ENABLED, true);
        $this->actingAs($this->user())->get('/konto')->assertOk()
            ->assertSee('name="guest_method" value="twint"', false)->assertSee('per TWINT an Phlipp Arnold senden.')->assertDontSee('guest-twint-phone', false);
        $this->post('/konto/gast', $this->sale(['guest_method' => 'twint']))
            ->assertSessionHas('status', '1 Bier für Gast erfasst – bitte CHF 1.50 per TWINT an Phlipp Arnold senden.');

        Setting::put(AppSettings::TWINT_PHONE, '079 123 45 67');
        Setting::put(AppSettings::TWINT_ENABLED, false);
        $this->get('/konto')->assertOk()->assertDontSee('name="guest_method" value="twint"', false)->assertDontSee('079 123 45 67');
        $this->post('/konto/gast', $this->sale(['guest_method' => 'twint']))->assertSessionHasErrors('guest_method');
    }

    public function test_validation_and_double_submit(): void
    {
        $this->actingAs($this->user());
        $data = $this->sale();
        $this->post('/konto/gast', $data)->assertSessionHas('status');
        $this->post('/konto/gast', $data)->assertSessionHas('warning');
        $this->post('/konto/gast', $this->sale(['guest_quantity' => 11]))->assertSessionHasErrors('guest_quantity');
        $this->post('/konto/gast', $this->sale(['guest_quantity' => 0]))->assertSessionHasErrors('guest_quantity');
        $this->post('/konto/gast', $this->sale(['guest_method' => 'gold']))->assertSessionHasErrors('guest_method');
        $this->post('/konto/gast', ['guest_quantity' => 1, 'guest_method' => 'cash'])->assertSessionHas('warning');
        $this->assertSame(1, GuestSale::query()->count());
    }

    public function test_stock_limit_is_enforced(): void
    {
        CashEntry::query()->delete();
        $this->stock(3);
        $this->actingAs($this->user());
        $this->get('/konto')->assertOk()->assertSee('id="guest_quantity" name="guest_quantity" value="1" min="1" max="3"', false);

        $this->post('/konto/gast', $this->sale(['guest_quantity' => 4]))->assertSessionHas('error', 'Nur noch 3 Biere an Lager.');
        $this->post('/konto/gast', $this->sale(['guest_quantity' => 3]))->assertSessionHas('status');
        $this->post('/konto/gast', $this->sale(['guest_quantity' => 1]))->assertSessionHas('error', 'Zurzeit ist kein Bier an Lager.');
        $this->get('/konto')->assertOk()->assertSee('Zurzeit ist kein Bier an Lager.');

        $this->assertSame(3, (int) GuestSale::query()->sum('beers'));
        $this->assertSame(0, BeerStock::current());
    }

    public function test_disabled_hides_form_and_refuses_booking(): void
    {
        Setting::put(AppSettings::GUEST_USER_BOOKING, false);
        $this->actingAs($this->user())->get('/konto')->assertOk()->assertDontSee('Bier für Gast')->assertDontSee('guest_quantity');
        $this->post('/konto/gast', $this->sale())->assertNotFound();
        $this->assertSame(0, GuestSale::query()->count());
    }

    public function test_requires_login_and_active_user(): void
    {
        $this->post('/konto/gast', ['guest_quantity' => 1, 'guest_method' => 'cash'])->assertRedirect(route('login'));
        $this->actingAs($this->user(['status' => User::STATUS_LEFT]))->post('/konto/gast', $this->sale())->assertRedirect(route('login'));
        $this->assertSame(0, GuestSale::query()->count());
    }

    public function test_own_undo_within_ten_minutes_only(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post('/konto/gast', $this->sale(['guest_quantity' => 2]));
        $first = GuestSale::query()->sole();
        $this->get('/konto')->assertOk()->assertSee(route('account.guest.undo', $first), false);

        // Another user cannot undo it.
        $this->actingAs($this->user())->post("/konto/gast/{$first->id}/rueckgaengig")->assertSessionHas('error');
        $this->assertSame(1, GuestSale::query()->count());

        // A public sale (no booking user) cannot be undone by a user.
        $public = GuestSale::query()->create(['beers' => 1, 'unit_price' => 150, 'amount' => 150, 'method' => 'cash']);
        $this->post("/konto/gast/{$public->id}/rueckgaengig")->assertSessionHas('error');

        $this->actingAs($user)->post("/konto/gast/{$first->id}/rueckgaengig")->assertSessionHas('status', __('guest.undone'));
        $this->assertNull(GuestSale::query()->find($first->id));
        $this->assertSame(29, BeerStock::current());

        // After 10 minutes it is locked.
        $this->post('/konto/gast', $this->sale());
        $second = GuestSale::query()->where('booked_by', $user->id)->sole();
        $this->travel(11)->minutes();
        $this->get('/konto')->assertOk()->assertDontSee(route('account.guest.undo', $second), false);
        $this->post("/konto/gast/{$second->id}/rueckgaengig")->assertSessionHas('error', __('ledger.undo_expired'));
        $this->assertNotNull(GuestSale::query()->find($second->id));
    }

    public function test_kassier_switches_user_booking(): void
    {
        $this->actingAs($this->kassier())->get('/backend/gaeste')->assertOk()
            ->assertSee('Gäste durch Benutzer buchen')->assertSee('Öffentliche Gäste-Seite (QR + Gäste-Passwort)');
        $this->put('/backend/gaeste', ['guest_user_booking' => '1'])->assertSessionHas('status');
        $this->assertTrue(AppSettings::guestUserBooking());
        $this->assertFalse(AppSettings::guestOpen());
        $this->put('/backend/gaeste', [])->assertSessionHas('status');
        $this->assertFalse(AppSettings::guestUserBooking());
    }

    private function stock(int $beers): void
    {
        CashEntry::query()->create(['type' => 'expense_beer', 'date' => '2026-10-01', 'amount' => -100 * $beers, 'beers' => $beers, 'method' => 'cash', 'note' => 'Kauf']);
    }

    private function sale(array $overrides = []): array
    {
        return $this->tokened('guest-booking', array_merge(['guest_quantity' => 1, 'guest_method' => 'cash'], $overrides));
    }
}
