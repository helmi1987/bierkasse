<?php

namespace Tests\Feature;

use App\Models\Setting;
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
        $this->actingAs($this->kassier())->get('/admin/navigation')->assertForbidden();

        $this->actingAs($this->admin());
        $this->get('/admin/navigation')->assertOk()->assertSee('data-sortable', false);
        $this->put('/admin/navigation', ['order' => [
            'kassier' => ['audit', 'cash', 'bogus'],
            'backend' => ['maintenance'],
        ]])->assertSessionHas('status');
        $this->assertSame(['audit', 'cash', 'dashboard'], array_slice(BackendNav::defaults()['kassier'], 0, 3));
        $this->assertSame('maintenance', BackendNav::defaults()['backend'][0]);
        $this->assertSame(['Wartung', 'Einstellungen'], array_slice($this->tabs($this->get('/admin/einstellungen')->getContent()), 0, 2));

        // Kassier sees the default, then the own order; only the "Kassier" group can be ordered.
        $this->actingAs($kassier);
        $this->assertSame(['Protokoll', 'Kasse'], array_slice($this->tabs($this->get('/admin')->getContent()), 0, 2));
        $this->get('/konto/einstellungen')->assertOk()->assertSee('name="order[kassier][]"', false)->assertDontSee('name="order[backend][]"', false);
        $this->put('/konto/navigation', ['order' => ['kassier' => ['stock'], 'backend' => ['sso']]])->assertSessionHas('status');
        $this->assertSame(['kassier'], array_keys($kassier->fresh()->nav_order));
        $this->assertSame('Lager', $this->tabs($this->get('/admin')->getContent())[0]);

        $this->delete('/konto/navigation')->assertSessionHas('status');
        $this->assertNull($kassier->fresh()->nav_order);
        $this->assertSame('Protokoll', $this->tabs($this->get('/admin')->getContent())[0]);

        // Admin resets the default.
        $this->actingAs($this->admin())->delete('/admin/navigation');
        $this->assertSame(array_keys(BackendNav::ITEMS['kassier']), BackendNav::defaults()['kassier']);
        $this->assertSame([], (array) AppSettings::get(AppSettings::NAV_ORDER));
    }

    public function test_users_cannot_order_tabs(): void
    {
        $this->actingAs($this->user());
        $this->put('/konto/navigation', ['order' => ['kassier' => ['stock']]])->assertForbidden();
        $this->get('/konto/einstellungen')->assertOk()->assertDontSee('data-sortable', false);
    }

    public function test_built_in_order_entscheid_2026_10_24(): void
    {
        $this->assertSame(['dashboard', 'cash', 'stock', 'users', 'bookings', 'qr', 'audit'], array_keys(BackendNav::ITEMS['kassier']));
        $this->assertSame(['settings', 'feedback', 'sso', 'nav', 'maintenance'], array_keys(BackendNav::ITEMS['backend']));

        $this->actingAs($this->kassier());
        $this->assertSame(['Übersicht', 'Kasse', 'Lager', 'Benutzer', 'Buchungen', 'QR-Codes', 'Protokoll'], $this->tabs($this->get('/admin')->getContent()));
        $this->actingAs($this->admin());
        $this->assertSame(['Einstellungen', 'Feedback', 'SSO', 'Navigation', 'Wartung'], $this->tabs($this->get('/admin/einstellungen')->getContent()));
        // Audit log and QR codes are one page each, in the kassier group (also for admins).
        $this->get('/admin/verwaltung/protokoll')->assertRedirect(route('backend.audit'));
        $this->get('/admin/protokoll')->assertOk()->assertSee('href="'.route('backend.audit').'" class="active"', false);
        $this->get('/admin/qr-codes')->assertOk()->assertSee('href="'.route('backend.qr-codes.index').'" class="active"', false);
        $this->get('/admin/gaeste/qr')->assertOk()->assertSee('href="'.route('backend.qr-codes.index').'" class="active"', false);
        $this->get('/admin/kasse?tab=vorschlaege')->assertOk()->assertSee('href="'.route('backend.cash').'" class="active"', false);
    }

    public function test_stored_orders_from_before_1_20_are_migrated(): void
    {
        // Default order of the admin and a personal order with the old keys (rounds, guests, gifts, stats, ...).
        Setting::put(AppSettings::NAV_ORDER, [
            'kassier' => ['audit', 'rounds', 'cash', 'invitations', 'gifts', 'stats', 'users'],
            'backend' => ['maintenance', 'qr_admin', 'audit_admin', 'settings'],
        ]);
        $this->assertSame(['audit', 'bookings', 'cash', 'users', 'dashboard', 'stock', 'qr'], BackendNav::defaults()['kassier']);
        $this->assertSame(['maintenance', 'settings', 'feedback', 'sso', 'nav'], BackendNav::defaults()['backend']);

        $kassier = $this->kassier();
        $kassier->forceFill(['nav_order' => ['kassier' => ['stock', 'deposit_suggestions', 'guests', 'qr', 'stats']]])->save();
        $this->assertSame(['stock', 'bookings', 'qr', 'dashboard', 'cash', 'users', 'audit'], BackendNav::order('kassier', $kassier->fresh()));
        $this->actingAs($kassier);
        $this->assertSame(['Lager', 'Buchungen', 'QR-Codes', 'Übersicht', 'Kasse', 'Benutzer', 'Protokoll'], $this->tabs($this->get('/admin')->getContent()));
        // The settings page lists the new tabs only.
        $this->get('/konto/einstellungen')->assertOk()->assertSee('value="bookings"', false)->assertDontSee('value="rounds"', false)
            ->assertDontSee('value="deposit_suggestions"', false);
    }
}
