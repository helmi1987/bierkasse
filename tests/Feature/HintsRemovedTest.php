<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\Feedback;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Collapsible help texts removed again (1.33.2): every hint is plain visible text, no info toggle, no stored state.
 * The round buttons at the top (1.32.1) and the asset version on <body> (1.33.1) stay.
 */
class HintsRemovedTest extends TestCase
{
    use RefreshDatabase;

    private const TOGGLE_MARKERS = ['hint-toggle', 'data-hint', 'x-hint', 'hint-box'];

    private User $giver;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        CashEntry::query()->create(['type' => 'expense_beer', 'date' => '2026-10-01', 'amount' => -3000, 'beers' => 30, 'method' => 'cash', 'note' => 'Kauf']);
        $this->giver = $this->user(['first_name' => 'Gabi', 'last_name' => 'Geber']);
        Ledger::bookDeposit($this->giver, 3000, 'cash', $this->kassier());
    }

    /** @return array<string, string> page => plain hint markup expected on it */
    private function pages(): array
    {
        return [
            '/konto' => '<p class="muted">'.e(__('round.intro')).'</p>',
            '/konto/einstellungen' => '<p class="hint">'.e(__('account.stats_hint')).'</p>',
            '/statistik' => '<p class="hint">'.e(__('stats.ranking_hint')).'</p>',
            '/konto/feedback' => '<div class="hint">'.e(__('feedback.message_hint', ['min' => Feedback::MESSAGE_MIN, 'max' => Feedback::MESSAGE_MAX])).'</div>',
            '/konto/einladen' => '<p class="hint">'.e(__('account.invitation_hash_note')).'</p>',
        ];
    }

    public function test_hints_are_plain_visible_text_without_toggle(): void
    {
        $this->user(['first_name' => 'Anna']);
        $this->actingAs($this->giver);

        foreach ($this->pages() as $uri => $hint) {
            $html = $this->get($uri)->assertOk()->assertSee($hint, false)->getContent();
            foreach (self::TOGGLE_MARKERS as $marker) {
                $this->assertStringNotContainsString($marker, $html, $uri.': '.$marker);
            }
        }
    }

    public function test_hint_route_and_column_are_gone(): void
    {
        $this->actingAs($this->giver)->post('/konto/hinweise', ['key' => 'round', 'open' => '1'])->assertNotFound();
        $this->assertFalse(Schema::hasColumn('users', 'open_hints'));
    }

    public function test_round_buttons_and_livewire_page_marker_stay(): void
    {
        $this->user(['first_name' => 'Anna']);
        $this->actingAs($this->giver)->get('/konto')->assertOk()
            ->assertSeeInOrder(['data-round-submit', 'name="recipients[]"'], false)
            ->assertSee('data-livewire-page', false);
    }
}
