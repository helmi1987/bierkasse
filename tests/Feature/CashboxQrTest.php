<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\GuestSale;
use App\Models\Setting;
use App\Services\BeerStock;
use App\Services\CashBook;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CashboxQrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Setting::putMany([AppSettings::CASHBOX_QR_ENABLED => true, AppSettings::TWINT_PHONE => '079 123 45 67']);
        CashEntry::query()->create(['type' => 'expense_beer', 'date' => '2026-10-01', 'amount' => -3000, 'beers' => 30, 'method' => 'cash', 'note' => 'Kauf']);
    }

    public function test_cash_code_books_cash_guest_sale_without_twint_data(): void
    {
        $code = AppSettings::cashboxQrCode('cash');
        $this->get('/kasse/'.$code)->assertOk()->assertSee('Kein Konto? Bar bezahlt')->assertDontSee('079 123 45 67');

        $this->post('/kasse/'.$code, $this->tokened('cashbox', ['quantity' => 3, 'method' => 'twint']))->assertRedirect(route('cashbox', $code));
        $this->get('/kasse/'.$code)->assertSee('3 Biere erfasst (CHF 4.50)');

        $this->assertDatabaseHas('guest_sales', ['beers' => 3, 'amount' => 450, 'method' => 'cash', 'source' => 'cashbox', 'booked_by' => null]);
        $this->assertSame(-3000 + 450, CashBook::balance()['cash']);
        $this->assertSame(27, BeerStock::current());
    }

    public function test_twint_code_books_twint_and_shows_copy_button(): void
    {
        $code = AppSettings::cashboxQrCode('twint');
        $this->get('/kasse/'.$code)->assertOk()->assertSee('Ich habe getwintet')->assertSee('data-copy-text="079 123 45 67"', false);
        $this->post('/kasse/'.$code, $this->tokened('cashbox', ['quantity' => 2]));
        $this->assertDatabaseHas('guest_sales', ['beers' => 2, 'method' => 'twint', 'source' => 'cashbox']);

        Setting::put(AppSettings::TWINT_ENABLED, false);
        $this->get('/kasse/'.$code)->assertNotFound();
    }

    public function test_invalid_disabled_or_regenerated_code_is_404(): void
    {
        $code = AppSettings::cashboxQrCode('cash');
        $this->get('/kasse/falsch')->assertNotFound();
        $this->post('/kasse/falsch', $this->tokened('cashbox', ['quantity' => 1]))->assertNotFound();

        AppSettings::regenerateCashboxQrCode('cash');
        $this->get('/kasse/'.$code)->assertNotFound();

        Setting::put(AppSettings::CASHBOX_QR_ENABLED, false);
        $this->get('/kasse/'.AppSettings::cashboxQrCode('cash'))->assertNotFound();
        $this->assertSame(0, GuestSale::query()->count());
    }

    public function test_stock_zero_never_blocks(): void
    {
        $code = AppSettings::cashboxQrCode('cash');
        foreach ([10, 10, 10, 2] as $beers) {
            $this->post('/kasse/'.$code, $this->tokened('cashbox', ['quantity' => $beers]))->assertRedirect(route('cashbox', $code));
        }
        $this->assertSame(-2, BeerStock::current());
        $this->get('/kasse/'.$code)->assertOk()->assertDontSee('disabled');
    }

    public function test_backend_sticker_svg_regenerate_and_label(): void
    {
        $kassier = $this->kassier();
        $this->actingAs($kassier)->get('/backend/kassen-qr/cash')->assertNotFound();
        $svg = $this->actingAs($kassier)->get('/backend/gaeste/kassen-qr/cash?download=1')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="kassen-qr-bar.svg"');
        $this->assertStringContainsString('Bar bezahlt', $svg->getContent());
        $this->actingAs($kassier)->get('/backend/gaeste/kassen-qr/twint')->assertOk()->assertSee('Ich habe getwintet');
        $this->actingAs($this->user())->get('/backend/gaeste/kassen-qr/cash')->assertForbidden();

        $sheet = $this->actingAs($kassier)->get('/backend/gaeste/kassen-qr-a4/mixed?download=1')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="kassen-qr-a4-bar-twint.svg"')->getContent();
        $this->assertStringContainsString('width="210mm" height="297mm"', $sheet);
        $this->assertSame(2, substr_count($sheet, '>Bar bezahlt<'));
        $this->assertSame(2, substr_count($sheet, '>Ich habe getwintet<'));
        $this->assertSame(4, substr_count($sheet, 'stroke-dasharray'));
        $this->actingAs($kassier)->get('/backend/gaeste/kassen-qr-a4/andere')->assertNotFound();
        $this->actingAs($kassier)->get('/backend/gaeste')->assertSee('data-png-width="945"', false);

        $old = AppSettings::cashboxQrCode('twint');
        $this->actingAs($kassier)->post('/backend/gaeste/kassen-qr/twint/neuer-code')->assertSessionHas('status');
        $this->assertNotSame($old, AppSettings::cashboxQrCode('twint'));

        $this->post('/kasse/'.AppSettings::cashboxQrCode('cash'), $this->tokened('cashbox', ['quantity' => 1]));
        $this->actingAs($kassier)->get('/backend/gaeste')->assertOk()->assertSee('Kassen-QR');

        $this->actingAs($kassier)->put('/backend/gaeste', ['guest_user_booking' => '1'])->assertSessionHas('status');
        $this->assertFalse((bool) AppSettings::get(AppSettings::CASHBOX_QR_ENABLED));
    }
}
