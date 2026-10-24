<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashEntry;
use App\Models\GuestSale;
use App\Models\QrCode;
use App\Models\Setting;
use App\Services\BeerStock;
use App\Services\CashBook;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
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
        $code = $this->code('cash');
        $this->get('/kasse/'.$code)->assertOk()->assertSee('Kein Konto? Bar bezahlt')->assertDontSee('079 123 45 67');
        $this->get('/kasse/'.$code)->assertSee('href="'.route('login').'">Abbrechen</a>', false);
        $this->actingAs($this->user())->get('/')->assertRedirect(route('account.dashboard'));
        auth()->logout();

        $this->post('/kasse/'.$code, $this->tokened('cashbox', ['quantity' => 3, 'method' => 'twint']))->assertRedirect(route('cashbox', $code));
        $this->get('/kasse/'.$code)->assertSee('3 Biere erfasst (CHF 4.50)');

        $this->assertDatabaseHas('guest_sales', ['beers' => 3, 'amount' => 450, 'method' => 'cash', 'source' => 'cashbox', 'booked_by' => null]);
        $this->assertSame(-3000 + 450, CashBook::balance()['cash']);
        $this->assertSame(27, BeerStock::current());
    }

    public function test_twint_code_books_twint_and_shows_copy_button(): void
    {
        $code = $this->code('twint');
        $this->get('/kasse/'.$code)->assertOk()->assertSee('Ich habe getwintet')->assertSee('data-copy-text="079 123 45 67"', false);
        $this->post('/kasse/'.$code, $this->tokened('cashbox', ['quantity' => 2]));
        $this->assertDatabaseHas('guest_sales', ['beers' => 2, 'method' => 'twint', 'source' => 'cashbox']);

        Setting::put(AppSettings::TWINT_ENABLED, false);
        $this->get('/kasse/'.$code)->assertNotFound();
    }

    public function test_invalid_disabled_or_deactivated_code_is_404_and_several_can_be_active(): void
    {
        $code = $this->code('cash');
        $this->get('/kasse/falsch')->assertNotFound();
        $this->post('/kasse/falsch', $this->tokened('cashbox', ['quantity' => 1]))->assertNotFound();

        // A second code: both are valid.
        $second = QrCode::generate(QrCode::KIND_CASH, 'Kasse 2')->code;
        $this->get('/kasse/'.$code)->assertOk();
        $this->get('/kasse/'.$second)->assertOk();

        QrCode::findActive(QrCode::KIND_CASH, $code)->update(['deactivated_at' => now()]);
        $this->get('/kasse/'.$code)->assertNotFound();
        $this->get('/kasse/'.$second)->assertOk();

        Setting::put(AppSettings::CASHBOX_QR_ENABLED, false);
        $this->get('/kasse/'.$second)->assertNotFound();
        $this->assertSame(0, GuestSale::query()->count());
    }

    public function test_stock_zero_never_blocks(): void
    {
        $code = $this->code('cash');
        foreach ([10, 10, 10, 2] as $beers) {
            $this->post('/kasse/'.$code, $this->tokened('cashbox', ['quantity' => $beers]))->assertRedirect(route('cashbox', $code));
        }
        $this->assertSame(-2, BeerStock::current());
        $this->get('/kasse/'.$code)->assertOk()->assertDontSee('disabled');
    }

    public function test_backend_stickers_kassier_sees_admin_manages(): void
    {
        $kassier = $this->kassier();
        $admin = $this->admin();
        QrCode::ensureFirst(QrCode::KIND_CASH, $admin);
        QrCode::ensureFirst(QrCode::KIND_TWINT, $admin);
        $page = $this->actingAs($kassier)->get('/admin/qr-codes/kassen-qr')->assertOk()->assertDontSee('Neuer Bar-Code')->assertDontSee(__('qr.deactivate'));
        $cash = QrCode::newestActive(QrCode::KIND_CASH);
        $twint = QrCode::newestActive(QrCode::KIND_TWINT);
        $page->assertSee(route('backend.guests.cashbox', $cash))->assertSee('data-png-width="945"', false);

        $svg = $this->get(route('backend.guests.cashbox', ['qrCode' => $cash, 'download' => 1]))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="kassen-qr-bar-'.$cash->id.'.svg"')->getContent();
        $this->assertStringContainsString('Bar bezahlt', $svg);
        $this->get(route('backend.guests.cashbox', $twint))->assertOk()->assertSee('Ich habe getwintet');
        $this->actingAs($this->user())->get(route('backend.guests.cashbox', $cash))->assertForbidden();

        $sheet = $this->actingAs($kassier)->get(route('backend.guests.cashbox.mixed', ['download' => 1]))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="kassen-qr-a4-bar-twint.svg"')->getContent();
        $this->assertStringContainsString('width="210mm" height="297mm"', $sheet);
        $this->assertSame(2, substr_count($sheet, '>Bar bezahlt<'));
        $this->assertSame(2, substr_count($sheet, '>Ich habe getwintet<'));
        $this->assertSame(4, substr_count($sheet, 'stroke-dasharray'));
        $one = $this->get(route('backend.guests.cashbox.sheet', $twint))->assertOk()->getContent();
        $this->assertSame(4, substr_count($one, '>Ich habe getwintet<'));

        // Only admins create, rename, deactivate and reactivate.
        $this->post('/admin/qr-codes', ['kind' => QrCode::KIND_CASH])->assertForbidden();
        $this->put(route('backend.qr-codes.active', $cash), ['active' => 0])->assertForbidden();
        $this->actingAs($admin)->post('/admin/qr-codes', ['kind' => QrCode::KIND_CASH, 'label' => 'Kühlschrank'])->assertSessionHas('status');
        $this->put(route('backend.qr-codes.active', $cash), ['active' => 0])->assertSessionHas('status');
        $this->put(route('backend.qr-codes.label', $twint), ['label' => 'Tresen'])->assertSessionHas('status');
        $this->assertSame('Tresen', $twint->fresh()->label);
        // Sticker page (also for admins): active codes only, no management; the QR page shows admins the management with history.
        $this->get('/admin/qr-codes/kassen-qr')->assertOk()->assertSee('Kühlschrank')->assertDontSee(__('qr.deactivate'))->assertSee(route('backend.qr-codes.index').'#verwaltung');
        $this->get('/admin/qr-codes')->assertOk()->assertSee('Kühlschrank')->assertSee(__('qr.activate'))->assertSee(__('qr.deactivate'));
        $this->actingAs($kassier)->get('/admin/qr-codes')->assertOk()->assertDontSee(__('qr.activate'))->assertDontSee(__('qr.deactivate'))->assertDontSee('Kühlschrank');
        $this->actingAs($admin);
        // Deactivated: hidden from kassiers, the admin still sees it.
        $this->get(route('backend.guests.cashbox', $cash))->assertOk();
        $this->actingAs($kassier)->get(route('backend.guests.cashbox', $cash))->assertNotFound();
        $this->get('/admin/qr-codes/kassen-qr')->assertDontSee('src="'.route('backend.guests.cashbox', $cash).'"', false);
        $this->actingAs($admin)->put(route('backend.qr-codes.active', $cash), ['active' => 1]);
        $this->assertTrue($cash->fresh()->isActive());
        foreach (['qr_code.create', 'qr_code.deactivate', 'qr_code.activate', 'qr_code.label'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->exists(), $action);
        }

        // The switch is in Admin > Einstellungen > Funktionen (admins only).
        $this->actingAs($kassier)->put('/admin/einstellungen/funktionen', $this->featureForm())->assertForbidden();
        $this->assertTrue((bool) AppSettings::get(AppSettings::CASHBOX_QR_ENABLED));
        $this->actingAs($admin)->put('/admin/einstellungen/funktionen', $this->featureForm())->assertSessionHas('status');
        $this->assertFalse((bool) AppSettings::get(AppSettings::CASHBOX_QR_ENABLED));
    }

    public function test_qr_overview_tab_for_kassier_and_admin(): void
    {
        QrCode::ensureFirst(QrCode::KIND_CASH);
        QrCode::generate(QrCode::KIND_CASH)->update(['deactivated_at' => now()]);
        $this->actingAs($this->kassier())->get('/admin/qr-codes')->assertOk()
            ->assertSee('class="active">QR-Codes', false)->assertSee('1 aktiv')->assertDontSee('deaktiviert');
        // Admins: same page plus the management parts (history of deactivated codes).
        $this->actingAs($this->admin())->get('/admin/qr-codes')->assertOk()
            ->assertSee(route('backend.registration.qr'))->assertSee(route('backend.guests.qr'))->assertSee(route('backend.qr-codes.cashbox'))
            ->assertSee('Deaktivierte Codes (Historie) (1)')
            ->assertSee('href="'.route('backend.qr-codes.index').'" class="active"', false);
        $this->get('/admin/registrierung-qr')->assertOk()->assertSee('class="active">QR-Codes', false);
        $this->get('/admin/verwaltung/qr-codes')->assertRedirect(route('backend.qr-codes.index'));
        $this->actingAs($this->user())->get('/admin/qr-codes')->assertForbidden();
    }

    public function test_migration_moves_former_codes_from_settings(): void
    {
        Setting::putMany(['cashbox_qr_cash_token' => 'oldCashCode123', 'registration_flyer_code' => 'oldFlyer456']);
        $migration = require database_path('migrations/2026_10_23_000100_create_qr_codes_table.php');
        Schema::drop('qr_codes');
        $migration->up();
        Setting::flushCache();

        $this->assertSame('cash', AppSettings::cashboxQrMethod('oldCashCode123'));
        $this->assertNotNull(QrCode::findActive(QrCode::KIND_FLYER, 'oldFlyer456'));
        $this->assertNull(AppSettings::get('cashbox_qr_cash_token'));
    }

    private function code(string $method): string
    {
        $kind = $method === 'cash' ? QrCode::KIND_CASH : QrCode::KIND_TWINT;
        QrCode::ensureFirst($kind);

        return QrCode::newestActive($kind)->code;
    }
}
