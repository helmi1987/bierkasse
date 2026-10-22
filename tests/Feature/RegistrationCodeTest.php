<?php

namespace Tests\Feature;

use App\Mail\KassierNotice;
use App\Models\AuditLog;
use App\Models\RegistrationCode;
use App\Models\Setting;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\Qr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationCodeTest extends TestCase
{
    use RefreshDatabase;

    private function data(string $code, array $overrides = []): array
    {
        return array_merge([
            RegistrationCode::QUERY => $code,
            'username' => 'neu.user',
            'first_name' => 'Neu',
            'email' => 'neu@example.org',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ], $overrides);
    }

    private function code(string $label = 'Kaffeeküche'): RegistrationCode
    {
        return RegistrationCode::generate($label, null);
    }

    public function test_only_admins_manage_codes(): void
    {
        $code = $this->code();
        foreach ([$this->user(), $this->kassier()] as $who) {
            $this->actingAs($who);
            $this->post('/backend/registrierung-qr/codes', ['label' => 'X'])->assertForbidden();
            $this->put("/backend/registrierung-qr/codes/{$code->id}/sperre", ['locked' => 1])->assertForbidden();
        }
        $this->assertSame(1, RegistrationCode::query()->count());
    }

    public function test_admin_creates_deactivates_and_reactivates(): void
    {
        $this->actingAs($this->admin());
        $this->post('/backend/registrierung-qr/codes', ['label' => 'Flyer Küche'])->assertSessionHas('status');
        $code = RegistrationCode::query()->sole();
        $this->assertSame(RegistrationCode::CODE_LENGTH, strlen($code->code));
        // Stored encrypted; the hash is used for the lookup.
        $this->assertStringNotContainsString($code->code, (string) DB::table('registration_codes')->value('code'));

        $this->get('/backend/registrierung-qr')->assertOk()->assertSee('Flyer Küche')
            ->assertSee(Qr::dataUri($code->url(), 480), false);

        $this->put("/backend/registrierung-qr/codes/{$code->id}/sperre", ['locked' => 1]);
        $this->assertTrue($code->fresh()->isLocked());
        $this->get('/backend/registrierung-qr')->assertOk()->assertSee('deaktiviert')->assertSee('Kein aktiver Registrierungscode');
        // Kassiers only see active codes and cannot manage them.
        $this->actingAs($this->kassier())->get('/backend/registrierung-qr')->assertOk()->assertDontSee('Flyer Küche')->assertDontSee('Code erstellen');
        $this->actingAs(User::query()->where('role', 'admin')->first());
        $this->put("/backend/registrierung-qr/codes/{$code->id}/sperre", ['locked' => 0]);
        $this->assertFalse($code->fresh()->isLocked());

        // Nothing is deleted any more.
        $this->delete("/backend/registrierung-qr/codes/{$code->id}")->assertStatus(404);
        $this->assertSame(1, RegistrationCode::query()->count());
        foreach (['registration_code.create', 'registration_code.lock', 'registration_code.unlock'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->exists(), $action);
        }
    }

    public function test_registration_with_code_in_closed_mode_is_active_and_notifies(): void
    {
        Mail::fake();
        Setting::put(AppSettings::REGISTRATION_MODE, AppSettings::MODE_CLOSED);
        $this->admin(['email' => 'admin@example.org']);
        $code = $this->code();

        $this->get('/registrieren?'.RegistrationCode::QUERY.'='.$code->code)->assertOk()
            ->assertSee('name="'.RegistrationCode::QUERY.'"', false)->assertDontSee('name="code"', false);
        $this->post('/registrieren', $this->data($code->code))->assertRedirect(route('account.dashboard'));

        $user = User::query()->where('username', 'neu.user')->sole();
        $this->assertTrue($user->isActive());
        $this->assertSame($code->id, $user->registration_code_id);
        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => ($m->rows['Registrierungscode'] ?? null) === 'Kaffeeküche');

        // Several people with the same code.
        auth()->logout();
        $this->post('/registrieren', $this->data($code->code, ['username' => 'zweite', 'email' => 'zwei@example.org']))->assertRedirect(route('account.dashboard'));
        $this->assertSame(2, $code->users()->count());
    }

    public function test_mail_follows_the_switch(): void
    {
        Mail::fake();
        Setting::put(AppSettings::NOTIFY_KASSIER, false);
        $this->admin(['email' => 'admin@example.org']);
        $code = $this->code();

        $this->post('/registrieren', $this->data($code->code))->assertRedirect(route('account.dashboard'));
        Mail::assertNothingSent();
    }

    public function test_locked_or_deleted_code_leads_to_start_page_with_hint(): void
    {
        $code = $this->code();
        $plain = $code->code;
        $code->forceFill(['locked_at' => now()])->save();

        $this->get('/registrieren?'.RegistrationCode::QUERY.'='.$plain)->assertRedirect(route('login'))
            ->assertSessionHas('warning', 'Dieser Registrierungscode ist gesperrt.');
        $this->post('/registrieren', $this->data($plain))->assertRedirect(route('login'));
        $this->assertFalse(User::query()->where('username', 'neu.user')->exists());

        $code->delete();
        $this->get('/registrieren?'.RegistrationCode::QUERY.'='.$plain)->assertRedirect(route('login'))
            ->assertSessionHas('warning', 'Dieser Registrierungscode ist nicht mehr gültig.');
        $this->followingRedirects()->get('/registrieren?'.RegistrationCode::QUERY.'='.$plain)->assertSee('nicht mehr gültig');
    }

    public function test_deleting_a_code_keeps_its_accounts(): void
    {
        $code = $this->code();
        $this->post('/registrieren', $this->data($code->code))->assertRedirect(route('account.dashboard'));
        $code->delete();

        $user = User::query()->where('username', 'neu.user')->sole();
        $this->assertTrue($user->isActive());
        $this->assertNull($user->registration_code_id);
    }
}
