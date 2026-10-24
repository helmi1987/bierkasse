<?php

namespace Tests\Feature;

use App\Livewire\Backend\DepositSuggestionsForm;
use App\Models\Setting;
use App\Services\Ledger;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class DepositSuggestionsBackendTest extends TestCase
{
    use RefreshDatabase;

    /** Tab "Einzahlungsvorschläge" of "Kasse" (Livewire component since 1.20.0). */
    private function form(): Testable
    {
        return Livewire::test(DepositSuggestionsForm::class);
    }

    public function test_kassier_edits_and_user_is_forbidden(): void
    {
        $this->actingAs($this->user())->get('/admin/kasse?tab=vorschlaege')->assertForbidden();
        Livewire::test(DepositSuggestionsForm::class)->assertForbidden();

        $this->actingAs($this->kassier())->get('/admin/einzahlungsvorschlaege')->assertRedirect(route('backend.cash', ['tab' => 'vorschlaege']));
        $this->get('/admin/kasse?tab=vorschlaege')->assertOk()
            ->assertSee('20 Biere · CHF 30.00')->assertSee('100 Biere · CHF 150.00')->assertSee('wire:submit="save"', false);
        $this->form()->set('mode', 'manual')->set('amounts', '15, 30 45')->call('save')->assertHasNoErrors()->assertSee(__('settings.saved'));
        $this->assertSame([1500, 3000, 4500], Setting::get(AppSettings::DEPOSIT_SUGGESTIONS));

        $this->form()->assertSee('10 Biere · CHF 15.00')->assertSet('amounts', '15.00, 30.00, 45.00')->assertSet('mode', 'manual');
    }

    public function test_preselected_suggestion_can_be_chosen(): void
    {
        $this->actingAs($this->kassier());
        $this->form()->set('mode', 'manual')->set('amounts', '21, 30, 51, 60')->set('default', '30')->call('save')->assertHasNoErrors();
        $this->assertSame(3000, AppSettings::depositDefault());
        $this->form()->assertSet('default', '30.00')->assertSee('vorausgewählt');

        $this->actingAs($this->user())->get('/konto')->assertOk()
            ->assertSee('value="30.00"', false)->assertSee('14 Biere')->assertSee('34 Biere');

        $this->actingAs($this->kassier());
        $this->form()->set('mode', 'manual')->set('amounts', '21, 30')->set('default', '45')->call('save')->assertHasErrors('default');
        $this->assertSame(3000, AppSettings::depositDefault());

        $this->form()->set('mode', 'auto')->set('default', '')->call('save')->assertHasNoErrors();
        $this->assertNull(AppSettings::depositDefault());
    }

    public function test_admin_can_edit_and_switch_back_to_automatic(): void
    {
        $this->actingAs($this->admin());
        $this->form()->set('mode', 'manual')->set('amounts', '15')->call('save')->assertHasNoErrors();
        $this->form()->set('mode', 'auto')->call('save')->assertHasNoErrors();
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
            $this->form()->set('mode', 'manual')->set('amounts', (string) $input)->call('save')->assertHasErrors('amounts')->assertSee($message);
        }
        $this->form()->set('mode', 'manual')->set('amounts', '')->call('save')->assertHasErrors('amounts');
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
