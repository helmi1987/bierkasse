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
}
