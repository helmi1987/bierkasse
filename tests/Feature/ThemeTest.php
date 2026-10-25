<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeTest extends TestCase
{
    use RefreshDatabase;

    private function save(User $user, string $theme)
    {
        return $this->actingAs($user)->put('/konto/profil', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.org', 'theme' => $theme]);
    }

    public function test_theme_choices_are_stored_and_auto_resets_to_null(): void
    {
        $user = $this->user();

        $this->save($user, 'dark')->assertSessionHasNoErrors();
        $this->assertSame('dark', $user->fresh()->theme);
        $this->save($user, 'light');
        $this->assertSame('light', $user->fresh()->theme);
        $this->save($user, 'auto');
        $this->assertNull($user->fresh()->theme);
    }

    public function test_invalid_theme_is_rejected(): void
    {
        $user = $this->user();

        $this->save($user, 'blue')->assertSessionHasErrors('theme');
        $this->assertNull($user->fresh()->theme);
    }

    public function test_theme_change_writes_no_audit_entry(): void
    {
        $user = $this->user(['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.org']);
        $before = \DB::table('audit_logs')->count();

        $this->save($user, 'dark');

        $this->assertSame($before, \DB::table('audit_logs')->count());
    }

    public function test_data_theme_attribute_only_for_fixed_choice(): void
    {
        $user = $this->user(['theme' => 'dark']);
        $this->actingAs($user)->get('/konto/einstellungen')->assertOk()
            ->assertSee('<html lang="de-CH" data-theme="dark">', false)
            ->assertDontSee('prefers-color-scheme: light', false);

        $user->forceFill(['theme' => 'light'])->save();
        $this->actingAs($user)->get('/konto/einstellungen')->assertSee('data-theme="light"', false);

        $user->forceFill(['theme' => null])->save();
        $this->actingAs($user)->get('/konto/einstellungen')->assertOk()
            ->assertDontSee('data-theme', false)
            ->assertSee('prefers-color-scheme: dark', false);
    }

    public function test_guest_pages_stay_automatic(): void
    {
        $this->get('/')->assertOk()->assertDontSee('data-theme', false);
    }
}
