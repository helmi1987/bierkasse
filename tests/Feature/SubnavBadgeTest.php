<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubnavBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function subnav(string $html): string
    {
        preg_match('/<nav class="subnav".*?<\/nav>/s', $html, $m);

        return $m[0] ?? '';
    }

    public function test_pending_items_badge_on_overview_tab_only_when_pending(): void
    {
        $user = $this->user();
        $kassier = $this->kassier();

        $this->actingAs($kassier);
        $this->assertStringNotContainsString('class="badge"', $this->subnav($this->get('/backend/kasse')->getContent()));

        $this->actingAs($user)->post('/konto/einzahlung', $this->tokened('deposit', ['amount' => '20.50', 'method' => 'twint']))->assertSessionHas('status');

        $nav = $this->subnav($this->actingAs($kassier)->get('/backend/kasse')->getContent());
        $this->assertMatchesRegularExpression('/>\s*Übersicht\s*<span class="badge"[^>]*>1<\/span>/u', $nav);
        $this->assertSame(1, substr_count($nav, 'class="badge"'));
    }
}
