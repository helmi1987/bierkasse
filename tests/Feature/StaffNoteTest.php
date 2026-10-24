<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StaffNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_kassier_and_admin_save_note_encrypted_and_logged(): void
    {
        $user = $this->user(['username' => 'xy42']);

        $this->actingAs($this->kassier());
        $this->put(route('backend.users.note', $user), ['staff_note' => '  Hans Muster  '])->assertSessionHasNoErrors();
        $this->assertSame('Hans Muster', $user->fresh()->staff_note);
        $this->assertNotSame('Hans Muster', DB::table('users')->where('id', $user->id)->value('staff_note'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.note']);
        $this->assertStringContainsString('Hans Muster', (string) DB::table('audit_logs')->where('action', 'user.note')->value('new_values'));

        $this->get(route('backend.users.show', $user))->assertOk()->assertSee('value="Hans Muster"', false);
        $this->get(route('backend.users.index'))->assertOk()->assertSee('Hans Muster');

        $this->actingAs($this->admin());
        $this->put(route('backend.users.note', $user), ['staff_note' => str_repeat('x', 101)])->assertSessionHasErrors('staff_note');
        $this->put(route('backend.users.note', $user), ['staff_note' => ''])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->staff_note);
    }

    public function test_user_can_not_set_or_see_note(): void
    {
        $user = $this->user(['username' => 'xy42']);
        $user->forceFill(['staff_note' => 'Geheimer Hinweis'])->save();

        $this->actingAs($user);
        $this->put(route('backend.users.note', $user), ['staff_note' => 'x'])->assertForbidden();
        foreach (['/konto', '/konto/einstellungen', '/konto/verlauf'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('Geheimer Hinweis');
        }
        $this->assertArrayNotHasKey('staff_note', $user->toArray());
    }

    public function test_note_shown_for_pending_twint_deposits_only(): void
    {
        $twint = $this->user(['username' => 'xy42']);
        $twint->forceFill(['staff_note' => 'Hans Muster'])->save();
        $cash = $this->user(['username' => 'ab17']);
        $cash->forceFill(['staff_note' => 'Fritz Bar'])->save();

        $this->actingAs($twint)->post('/konto/einzahlung', $this->tokened('deposit', ['amount' => '10', 'method' => 'twint']))->assertSessionHasNoErrors();
        $this->actingAs($cash)->post('/konto/einzahlung', $this->tokened('deposit', ['amount' => '10', 'method' => 'cash']))->assertSessionHasNoErrors();

        $this->actingAs($this->kassier());
        $this->get('/admin')->assertOk()->assertSee('Hans Muster')->assertDontSee('Fritz Bar');
    }

    public function test_note_follows_name_unless_set_by_hand(): void
    {
        $user = $this->user(['first_name' => 'Hans', 'last_name' => null]);
        $this->assertSame('Hans', $user->staff_note);

        // Name extended: note follows.
        $this->actingAs($user)->put('/konto/profil', ['first_name' => 'Hans', 'last_name' => 'Muster', 'email' => $user->email])->assertSessionHasNoErrors();
        $this->assertSame('Hans Muster', $user->fresh()->staff_note);

        // Completely new name or emptied name: note stays.
        $this->put('/konto/profil', ['first_name' => 'Bierkönig', 'last_name' => '', 'email' => $user->email])->assertSessionHasNoErrors();
        $this->assertSame('Hans Muster', $user->fresh()->staff_note);
        $this->put('/konto/profil', ['first_name' => '', 'last_name' => '', 'email' => $user->email])->assertSessionHasNoErrors();
        $this->assertSame('Hans Muster', $user->fresh()->staff_note);

        // Set by hand: never overwritten.
        $hand = $this->user(['first_name' => 'Fritz', 'last_name' => null]);
        $hand->forceFill(['staff_note' => 'Fritz vom Stammtisch'])->save();
        $this->actingAs($hand)->put('/konto/profil', ['first_name' => 'Fritz', 'last_name' => 'Meier', 'email' => $hand->email])->assertSessionHasNoErrors();
        $this->assertSame('Fritz vom Stammtisch', $hand->fresh()->staff_note);

        // Registered without a name, name set later: note filled.
        $anon = $this->user(['first_name' => null, 'last_name' => null]);
        $this->assertNull($anon->staff_note);
        $this->actingAs($anon)->put('/konto/profil', ['first_name' => 'Anna', 'last_name' => 'Keller', 'email' => $anon->email])->assertSessionHasNoErrors();
        $this->assertSame('Anna Keller', $anon->fresh()->staff_note);
    }

    public function test_note_equal_to_name_is_not_shown_twice_in_list(): void
    {
        $this->user(['username' => 'xy42', 'first_name' => 'Hans', 'last_name' => 'Muster']);

        $this->actingAs($this->kassier());
        $this->get(route('backend.users.index'))->assertOk()->assertDontSee('data-testid="staff-note"', false);
    }
}
