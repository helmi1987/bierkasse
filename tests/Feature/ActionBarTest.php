<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ledger;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Bar with total and submit button of the round, gift and guest forms (1.38.0): at the top and sticky by default,
 * at the end of the form and not sticky when the area's switch is off.
 */
class ActionBarTest extends TestCase
{
    use RefreshDatabase;

    private User $giver;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setPrice(150);
        $this->giver = $this->user(['first_name' => 'Gabi']);
        $this->colleague = $this->user(['first_name' => 'Anna']);
        Ledger::bookDeposit($this->giver, 3000, 'cash', $this->kassier());
        $this->actingAs($this->giver);
    }

    /** @return array<string, array{string, string, string, string}> testid, switch, total marker, last form field */
    public static function areas(): array
    {
        return [
            'round' => ['round', AppSettings::ROUNDS_BAR_TOP, 'data-round-count', 'name="message"'],
            'gift' => ['gift', AppSettings::GIFTS_BAR_TOP, 'data-gift-count', 'name="gift_message"'],
            'guest' => ['guest-booking', AppSettings::GUEST_BAR_TOP, 'data-total', 'name="guest_name"'],
        ];
    }

    #[DataProvider('areas')]
    public function test_bar_is_at_the_top_and_sticky_by_default(string $testid, string $switch, string $total, string $field): void
    {
        $form = $this->form($testid);

        $this->assertSame(1, substr_count($form, 'class="action-bar is-sticky"'));
        $this->assertSame(1, substr_count($form, $total));
        $this->assertSame(1, substr_count($form, 'aria-live="polite"'));
        $this->assertLessThan(strpos($form, $field), strpos($form, 'action-bar is-sticky'));
        $this->assertLessThan(strpos($form, $field), strpos($form, $total));
    }

    #[DataProvider('areas')]
    public function test_bar_is_at_the_end_and_not_sticky_when_switched_off(string $testid, string $switch, string $total, string $field): void
    {
        Setting::put($switch, false);
        $form = $this->form($testid);

        $this->assertSame(1, substr_count($form, 'class="action-bar"'));
        $this->assertStringNotContainsString('is-sticky', $form);
        $this->assertSame(1, substr_count($form, $total));
        $this->assertGreaterThan(strpos($form, $field), strpos($form, 'class="action-bar"'));
        $this->assertGreaterThan(strpos($form, $field), strpos($form, $total));
    }

    public function test_switches_are_independent_of_each_other(): void
    {
        Setting::put(AppSettings::GIFTS_BAR_TOP, false);

        $this->assertStringContainsString('action-bar is-sticky', $this->form('round'));
        $this->assertStringNotContainsString('is-sticky', $this->form('gift'));
        $this->assertStringContainsString('action-bar is-sticky', $this->form('guest-booking'));
    }

    public function test_guest_total_follows_the_quantity_after_a_failed_booking(): void
    {
        $this->post('/konto/gast', $this->tokened('guest-booking', ['guest_quantity' => 3, 'guest_method' => 'nope']))
            ->assertSessionHasErrors('guest_method');

        $form = $this->form('guest-booking');
        $this->assertStringContainsString('<strong data-total>'.chf(450).'</strong>', $form);
    }

    public function test_submitting_works_the_same_with_the_bars_at_the_end(): void
    {
        foreach ([AppSettings::ROUNDS_BAR_TOP, AppSettings::GIFTS_BAR_TOP, AppSettings::GUEST_BAR_TOP] as $key) {
            Setting::put($key, false);
        }

        $this->post('/konto/runde', $this->tokened('round', ['recipients' => [$this->colleague->id], 'extra' => 0]))->assertSessionHas('status');
        $this->assertSame(-150, (int) $this->giver->transactions()->where('type', 'round')->latest('id')->value('amount'));

        $this->post('/konto/runde', $this->tokened('round', ['recipients' => [$this->colleague->id], 'extra' => 0, 'include_self' => '1']))->assertSessionHas('status');
        $this->assertSame(-300, (int) $this->giver->transactions()->where('type', 'round')->latest('id')->value('amount'));

        $this->post('/konto/verschenken', $this->tokened('gift', ['gift_recipients' => [$this->colleague->id], 'gift_quantity' => 2]))->assertSessionHas('status');
        $this->assertSame(300, $this->colleague->refresh()->balance());

        $this->post('/konto/gast', $this->tokened('guest-booking', ['guest_quantity' => 2, 'guest_method' => 'cash']))->assertSessionHas('status');
        $this->assertDatabaseHas('guest_sales', ['beers' => 2, 'amount' => 300, 'booked_by' => $this->giver->id]);
    }

    public function test_switching_an_area_off_still_hides_it_and_keeps_the_other_switch(): void
    {
        Setting::put(AppSettings::GIFTS_ENABLED, false);

        $this->get('/konto')->assertOk()->assertDontSee('data-testid="gift"', false)->assertDontSee('data-gift-submit', false);
        $this->assertTrue(AppSettings::giftsBarTop());
    }

    public function test_admin_saves_the_switches_with_an_audit_entry(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/einstellungen')->assertOk()
            ->assertSeeInOrder(['name="rounds_enabled"', 'name="rounds_bar_top"', 'name="gifts_enabled"', 'name="gifts_bar_top"', 'name="guest_user_booking"', 'name="guest_bar_top"'], false)
            ->assertSee(__('settings.bar_top'));

        $this->assertTrue(AppSettings::roundsBarTop());
        $this->assertTrue(AppSettings::giftsBarTop());
        $this->assertTrue(AppSettings::guestBarTop());

        $this->put(route('backend.settings.features'), $this->featureForm(['rounds_bar_top' => '0', 'guest_bar_top' => '0']))->assertRedirect();

        $this->assertFalse(AppSettings::roundsBarTop());
        $this->assertTrue(AppSettings::giftsBarTop());
        $this->assertFalse(AppSettings::guestBarTop());
        $this->assertTrue(AppSettings::roundsAllowed());
        $this->assertTrue(AppSettings::guestUserBooking());

        $this->assertSame('Runde spendieren: Knöpfe oben fixieren', __('audit.fields.rounds_bar_top'));
        $new = AuditLog::query()->where('action', 'settings.update')->latest('id')->first()->newValues();
        $this->assertFalse($new['rounds_bar_top']);
        $this->assertFalse($new['guest_bar_top']);
    }

    public function test_only_admins_reach_the_switches(): void
    {
        $this->actingAs($this->kassier())->put(route('backend.settings.features'), $this->featureForm(['rounds_bar_top' => '0']))->assertForbidden();
        $this->assertTrue(AppSettings::roundsBarTop());
    }

    /** The form of an area on the dashboard, as sent to the browser. */
    private function form(string $testid): string
    {
        $html = $this->get('/konto')->assertOk()->getContent();
        $section = substr($html, strpos($html, 'data-testid="'.$testid.'"'));
        $start = strpos($section, '<form');

        return substr($section, $start, strpos($section, '</form>') - $start);
    }
}
