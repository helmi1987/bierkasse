<?php

namespace Tests\Feature;

use App\Support\AppSettings;
use App\Support\BackendNav;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavOrderTest extends TestCase
{
    use RefreshDatabase;

    /** Tab labels of the sub navigation in the shown order. */
    private function tabs(string $html): array
    {
        preg_match('/<nav class="subnav".*?<\/nav>/s', $html, $m);
        preg_match_all('/<a [^>]*>([^<]+)/', $m[0] ?? '', $links);

        return array_map('trim', $links[1]);
    }

    public function test_admin_sets_default_and_kassier_overrides_for_own_account(): void
    {
        $kassier = $this->kassier();
        $this->actingAs($this->kassier())->get('/backend/navigation')->assertForbidden();

        $this->actingAs($this->admin());
        $this->get('/backend/navigation')->assertOk()->assertSee('data-sortable', false);
        $this->put('/backend/navigation', ['order' => [
            'kassier' => ['audit', 'cash', 'bogus'],
            'backend' => ['maintenance'],
        ]])->assertSessionHas('status');
        $this->assertSame(['audit', 'cash', 'dashboard'], array_slice(BackendNav::defaults()['kassier'], 0, 3));
        $this->assertSame('maintenance', BackendNav::defaults()['backend'][0]);
        $this->assertSame(['Wartung', 'Einstellungen'], array_slice($this->tabs($this->get('/backend/einstellungen')->getContent()), 0, 2));

        // Kassier sees the default, then the own order; only the "Kassier" group can be ordered.
        $this->actingAs($kassier);
        $this->assertSame(['Protokoll', 'Kasse'], array_slice($this->tabs($this->get('/backend')->getContent()), 0, 2));
        $this->get('/konto/einstellungen')->assertOk()->assertSee('name="order[kassier][]"', false)->assertDontSee('name="order[backend][]"', false);
        $this->put('/konto/navigation', ['order' => ['kassier' => ['stock'], 'backend' => ['sso']]])->assertSessionHas('status');
        $this->assertSame(['kassier'], array_keys($kassier->fresh()->nav_order));
        $this->assertSame('Lager', $this->tabs($this->get('/backend')->getContent())[0]);

        $this->delete('/konto/navigation')->assertSessionHas('status');
        $this->assertNull($kassier->fresh()->nav_order);
        $this->assertSame('Protokoll', $this->tabs($this->get('/backend')->getContent())[0]);

        // Admin resets the default.
        $this->actingAs($this->admin())->delete('/backend/navigation');
        $this->assertSame(array_keys(BackendNav::ITEMS['kassier']), BackendNav::defaults()['kassier']);
        $this->assertSame([], (array) AppSettings::get(AppSettings::NAV_ORDER));
    }

    public function test_users_cannot_order_tabs(): void
    {
        $this->actingAs($this->user());
        $this->put('/konto/navigation', ['order' => ['kassier' => ['stock']]])->assertForbidden();
        $this->get('/konto/einstellungen')->assertOk()->assertDontSee('data-sortable', false);
    }

    public function test_built_in_order_and_admin_tabs_for_qr_management_and_audit(): void
    {
        $this->assertSame(['dashboard', 'cash', 'stock', 'rounds', 'guests', 'invitations', 'gifts', 'stats', 'users', 'qr', 'deposit_suggestions', 'audit'], array_keys(BackendNav::ITEMS['kassier']));
        $this->assertSame(['settings', 'feedback', 'sso', 'nav', 'qr_admin', 'audit_admin', 'maintenance'], array_keys(BackendNav::ITEMS['backend']));

        $this->actingAs($this->admin())->get('/backend/verwaltung/protokoll')->assertOk()
            ->assertSee('href="'.route('backend.audit-admin').'" class="active"', false);
        $this->actingAs($this->kassier())->get('/backend/verwaltung/protokoll')->assertForbidden();
        $this->get('/backend/protokoll')->assertOk();
    }
}
