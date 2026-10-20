<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Ledger;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepositSuggestionsBackendTest extends TestCase
{
    use RefreshDatabase;

    public function test_kassier_edits_and_user_is_forbidden(): void
    {
        $this->actingAs($this->user())->get('/backend/einzahlungsvorschlaege')->assertForbidden();
        $this->put('/backend/einzahlungsvorschlaege', ['mode' => 'manual', 'amounts' => '15'])->assertForbidden();

        $this->actingAs($this->kassier())->get('/backend/einzahlungsvorschlaege')->assertOk()
            ->assertSee('20 Biere · CHF 30.00')->assertSee('100 Biere · CHF 150.00');
        $this->put('/backend/einzahlungsvorschlaege', ['mode' => 'manual', 'amounts' => '15, 30 45'])->assertSessionHas('status');
        $this->assertSame([1500, 3000, 4500], Setting::get(AppSettings::DEPOSIT_SUGGESTIONS));

        $this->get('/backend/einzahlungsvorschlaege')->assertSee('10 Biere · CHF 15.00')->assertSee('value="15.00, 30.00, 45.00"', false);
    }

    public function test_preselected_suggestion_can_be_chosen(): void
    {
        $this->actingAs($this->kassier())->put('/backend/einzahlungsvorschlaege', ['mode' => 'manual', 'amounts' => '21, 30, 51, 60', 'default' => '30'])
            ->assertSessionHas('status');
        $this->assertSame(3000, AppSettings::depositDefault());
        $this->get('/backend/einzahlungsvorschlaege')->assertSee('value="30.00"', false)->assertSee('vorausgewählt');

        $this->actingAs($this->user())->get('/konto')->assertOk()
            ->assertSee('value="30.00"', false)->assertSee('14 Biere')->assertSee('34 Biere');

        $this->actingAs($this->kassier())->put('/backend/einzahlungsvorschlaege', ['mode' => 'manual', 'amounts' => '21, 30', 'default' => '45'])
            ->assertSessionHasErrors('default');
        $this->assertSame(3000, AppSettings::depositDefault());

        $this->put('/backend/einzahlungsvorschlaege', ['mode' => 'auto', 'default' => ''])->assertSessionHas('status');
        $this->assertNull(AppSettings::depositDefault());
    }

    public function test_admin_can_edit_and_switch_back_to_automatic(): void
    {
        $this->actingAs($this->admin())->put('/backend/einzahlungsvorschlaege', ['mode' => 'manual', 'amounts' => '15'])->assertSessionHas('status');
        $this->put('/backend/einzahlungsvorschlaege', ['mode' => 'auto'])->assertSessionHas('status');
        $this->assertNull(AppSettings::depositSuggestions());
    }

    public function test_validation(): void
    {
        $this->actingAs($this->kassier());
        $cases = [
            '25' => 'CHF 25.00 ist nicht durch den Bierpreis CHF 1.50 teilbar. Nächste gültige Beträge: CHF 24.00 / CHF 25.50.',
            '30, 15' => 'aufsteigend',
            '15, 15' => 'aufsteigend',
            '0' => 'kein gültiger Betrag',
            'abc' => 'kein gültiger Betrag',
            '3 6 9 12 15 18 21' => 'Höchstens 6',
        ];
        foreach ($cases as $input => $message) {
            $this->put('/backend/einzahlungsvorschlaege', ['mode' => 'manual', 'amounts' => (string) $input])->assertSessionHasErrors('amounts');
            $this->assertStringContainsString($message, session('errors')->getBag('default')->first('amounts'), (string) $input);
        }
        $this->put('/backend/einzahlungsvorschlaege', ['mode' => 'manual', 'amounts' => ''])->assertSessionHasErrors('amounts');
        $this->assertNull(AppSettings::depositSuggestions());
    }

    public function test_dashboard_uses_configured_amounts_with_minus(): void
    {
        $this->setDefaultLimit(1000);
        Setting::put(AppSettings::TWINT_PHONE, '079 123 45 67');
        Setting::put(AppSettings::DEPOSIT_SUGGESTIONS, [1500, 3000]);
        $user = $this->user();
        Ledger::consume($user, 3);

        $this->actingAs($user)->get('/konto')->assertOk()
            ->assertSee('10 Biere')->assertSee('CHF 19.50')->assertSee('inkl. Minus')
            ->assertSee('value="19.50"', false)->assertSee('CHF 34.50');
    }
}
