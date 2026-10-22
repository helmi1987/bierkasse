<?php

namespace Tests\Feature;

use App\Mail\KassierNotice;
use App\Models\CashEntry;
use App\Models\GuestSale;
use App\Models\Setting;
use App\Services\BeerStock;
use App\Services\CashBook;
use App\Services\Stats;
use App\Support\AppSettings;
use App\Support\FormToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GuestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Setting::putMany([AppSettings::GUEST_ENABLED => true, AppSettings::GUEST_PASSWORD => 'gast-1234']);
    }

    public function test_guest_page_prefills_code_and_shows_price(): void
    {
        $this->get('/gast?code=gast-1234')->assertOk()->assertSee('value="gast-1234"', false)->assertSee('CHF 1.50');
    }

    public function test_wrong_or_missing_code_books_nothing(): void
    {
        $this->post('/gast', $this->sale(['code' => 'falsch']))->assertSessionHasErrors(['code' => __('guest.code_wrong')]);
        $this->post('/gast', $this->sale(['code' => '']))->assertSessionHasErrors('code');
        $this->post('/gast', array_diff_key($this->sale(), ['code' => 1]))->assertSessionHasErrors('code');
        $this->assertSame(0, GuestSale::query()->count());
    }

    public function test_booking_effects_cash_stock_and_stats(): void
    {
        Setting::put(AppSettings::TWINT_PHONE, '079 123 45 67');
        CashEntry::query()->create(['type' => 'expense_beer', 'date' => '2026-10-01', 'amount' => -3000, 'beers' => 30, 'method' => 'cash', 'note' => 'Kauf']);

        $this->post('/gast', $this->sale(['quantity' => 3, 'name' => 'Fritz']))->assertRedirect(route('guest.thanks'));
        $this->followRedirects($this->get('/gast/danke'))->assertOk()->assertSee('CHF 4.50 bar in die Kasse');

        $this->post('/gast', $this->sale(['quantity' => 2, 'method' => 'twint']))->assertRedirect(route('guest.thanks'));
        $this->get('/gast/danke')->assertOk()->assertSee('CHF 3.00 per TWINT an <strong>079 123 45 67</strong>', false);

        $this->assertDatabaseHas('guest_sales', ['name' => 'Fritz', 'beers' => 3, 'unit_price' => 150, 'amount' => 450, 'method' => 'cash']);
        $cash = CashBook::balance();
        $this->assertSame(-3000 + 450, $cash['cash']);
        $this->assertSame(300, $cash['twint']);
        $this->assertSame(25, BeerStock::current());

        $stats = Stats::global();
        $this->assertSame(5, $stats['total_beers']);
        $this->assertSame([['user_id' => 0, 'name' => 'Gäste', 'beers' => 5]], $stats['ranking']);
        $this->assertSame(5, array_sum(array_column($stats['weekdays'], 'beers')));
        $this->assertSame(5, end($stats['months'])['beers']);

        $this->actingAs($this->user())->get('/statistik')->assertOk()->assertSee('Gäste');
    }

    public function test_guest_sale_can_trigger_low_stock_mail(): void
    {
        $this->kassier(['email' => 'k@example.org']);
        CashEntry::query()->create(['type' => 'expense_beer', 'date' => '2026-10-01', 'amount' => -3000, 'beers' => 21, 'method' => 'cash', 'note' => 'Kauf']);

        $this->post('/gast', $this->sale(['quantity' => 2]));
        Mail::assertSent(KassierNotice::class, 1);
    }

    public function test_double_submit_and_limits(): void
    {
        $this->stock(20);
        $data = $this->sale();
        $this->post('/gast', $data)->assertRedirect(route('guest.thanks'));
        $this->post('/gast', $data)->assertSessionHas('warning');
        $this->post('/gast', $this->sale(['quantity' => 11]))->assertSessionHasErrors('quantity');
        $this->post('/gast', $this->sale(['quantity' => 0]))->assertSessionHasErrors('quantity');
        $this->post('/gast', $this->sale(['method' => 'gold']))->assertSessionHasErrors('method');
        $this->assertSame(1, GuestSale::query()->count());
    }

    public function test_rate_limit_also_for_wrong_codes(): void
    {
        $this->freezeTime();
        for ($i = 0; $i < 10; $i++) {
            $this->post('/gast', $this->sale(['code' => 'x'.$i]))->assertSessionHasErrors(['code' => __('guest.code_wrong')]);
        }
        $this->post('/gast', $this->sale())->assertSessionHasErrors(['code' => __('auth.throttle', ['seconds' => 60])]);
        $this->assertSame(0, GuestSale::query()->count());
    }

    public function test_guest_disabled(): void
    {
        Setting::put(AppSettings::GUEST_ENABLED, false);
        $this->get('/gast?code=gast-1234')->assertNotFound();
        $this->post('/gast', $this->sale())->assertNotFound();

        // Enabled without a password is closed as well.
        Setting::putMany([AppSettings::GUEST_ENABLED => true, AppSettings::GUEST_PASSWORD => '']);
        $this->get('/gast')->assertNotFound();
        $this->assertSame(0, GuestSale::query()->count());
    }

    public function test_kassier_manages_guests_and_user_is_forbidden(): void
    {
        $this->actingAs($this->user())->get('/backend/gaeste')->assertForbidden();
        $this->put('/backend/gaeste', ['guest_enabled' => '1', 'guest_password' => 'hack'])->assertForbidden();
        $this->get('/backend/gaeste/qr')->assertForbidden();

        $sale = GuestSale::query()->create(['beers' => 2, 'unit_price' => 150, 'amount' => 300, 'method' => 'cash']);
        $this->delete("/backend/gaeste/{$sale->id}")->assertForbidden();

        $this->actingAs($this->kassier())->put('/backend/gaeste', ['guest_enabled' => '1', 'guest_password' => 'neues-pw'])->assertSessionHas('status');
        $this->assertSame('neues-pw', AppSettings::guestPassword());
        $this->assertStringNotContainsString('neues-pw', (string) Setting::query()->find(AppSettings::GUEST_PASSWORD)->getRawOriginal('value'));
        $this->assertNotSame(AppSettings::registrationPassword(), AppSettings::guestPassword());

        $this->get('/backend/gaeste')->assertOk()->assertSee('Gast')->assertSee('CHF 3.00')->assertSee('öffentlich');
        $this->assertFalse(AppSettings::guestUserBooking());
        $this->get('/backend/gaeste/qr')->assertOk()->assertSee(route('guest').'?code=neues-pw');
        $this->get('/backend/gaeste/qr?code=0')->assertOk()->assertDontSee('code=neues-pw');

        $this->delete("/backend/gaeste/{$sale->id}")->assertSessionHas('status');
        $this->assertSame(0, GuestSale::query()->count());
        $this->assertSame(0, CashBook::balance()['total']);
    }

    public function test_enabling_guests_requires_password(): void
    {
        Setting::putMany([AppSettings::GUEST_ENABLED => false, AppSettings::GUEST_PASSWORD => '']);
        $this->actingAs($this->kassier())->put('/backend/gaeste', ['guest_enabled' => '1', 'guest_password' => ''])
            ->assertSessionHasErrors(['guest_password' => __('guest.password_required')]);
        $this->assertFalse(AppSettings::guestOpen());

        // Switching off without a password is fine.
        $this->put('/backend/gaeste', ['guest_password' => ''])->assertSessionHas('status');
    }

    public function test_used_token_cannot_be_claimed_twice(): void
    {
        $data = $this->sale();
        $token = $data[FormToken::FIELD];
        // A parallel request already claimed the token (session still lists it).
        Cache::add('form-token:'.hash('sha256', 'guest|'.$token), 1, 60);

        $this->post('/gast', $data)->assertSessionHas('warning');
        $this->assertSame(0, GuestSale::query()->count());
    }

    public function test_guests_are_never_blocked_by_the_stock(): void
    {
        $this->stock(3);
        $this->get('/gast')->assertOk()->assertSee('max="10"', false);

        $this->post('/gast', $this->sale(['quantity' => 4]))->assertRedirect(route('guest.thanks'));
        $this->post('/gast', $this->sale(['quantity' => 1]))->assertRedirect(route('guest.thanks'));

        $this->assertSame(5, (int) GuestSale::query()->sum('beers'));
        $this->assertSame(-2, BeerStock::current());
    }

    private function stock(int $beers): void
    {
        CashEntry::query()->create(['type' => 'expense_beer', 'date' => '2026-10-01', 'amount' => -100 * $beers, 'beers' => $beers, 'method' => 'cash', 'note' => 'Kauf']);
    }

    private function sale(array $overrides = []): array
    {
        return $this->tokened('guest', array_merge(['code' => 'gast-1234', 'quantity' => 1, 'method' => 'cash'], $overrides));
    }
}
