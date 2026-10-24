<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\QrCode;
use App\Models\Setting;
use App\Support\AppSettings;
use App\Support\RegistrationLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * First QR codes are created by admin paths only (audited); kassier GETs never create codes.
 */
class QrCodeFirstTest extends TestCase
{
    use RefreshDatabase;

    private function openRegistration(): void
    {
        Setting::put(AppSettings::REGISTRATION_MODE, AppSettings::MODE_PASSWORD);
        Setting::put(AppSettings::REGISTRATION_PASSWORD, 'durst');
    }

    public function test_kassier_gets_create_no_codes_and_show_empty_state(): void
    {
        $this->openRegistration();
        $kassier = $this->kassier();
        $this->actingAs($kassier)->get('/backend/gaeste')->assertOk()->assertSee(__('qr.none_kassier'));
        $this->get('/backend/gaeste/qr')->assertOk()->assertSee(__('qr.none_kassier'));
        $this->get('/backend/registrierung-qr')->assertOk()->assertSee(__('qr.none_kassier'));
        $this->get('/backend/qr-codes')->assertOk();
        $this->get('/konto/einladen')->assertOk();
        $this->assertSame(0, QrCode::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'qr_code.create')->count());
        $this->assertSame(route('register'), RegistrationLink::url(true));
    }

    public function test_public_pages_work_without_codes(): void
    {
        Setting::putMany([AppSettings::CASHBOX_QR_ENABLED => true]);
        $this->get('/kasse/unknown')->assertNotFound();
        $this->assertContains($this->get('/gast')->getStatusCode(), [200, 302, 403, 404]);
        $this->get('/registrieren')->assertOk();
        $this->assertSame(0, QrCode::query()->count());
    }

    public function test_admin_management_page_creates_first_codes_with_audit_once(): void
    {
        $this->openRegistration();
        $admin = $this->admin();
        $this->actingAs($admin)->get('/backend/verwaltung/qr-codes')->assertOk();
        $this->assertSame(4, QrCode::query()->count());
        $this->assertSame(4, QrCode::query()->where('created_by', $admin->id)->count());
        $entries = AuditLog::query()->where('action', 'qr_code.create')->get();
        $this->assertCount(4, $entries);
        $this->assertSame([$admin->id], $entries->pluck('actor_id')->unique()->values()->all());

        $this->get('/backend/verwaltung/qr-codes')->assertOk();
        $this->assertSame(4, QrCode::query()->count());
        $this->assertSame(4, AuditLog::query()->where('action', 'qr_code.create')->count());
    }

    public function test_admin_saving_registration_mode_creates_flyer_code(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        QrCode::ensureFirstForAdmin($admin);
        $this->assertSame(0, QrCode::query()->where('kind', QrCode::KIND_FLYER)->count());
        $this->openRegistration();
        QrCode::ensureFirstForAdmin($admin);
        $this->assertSame(1, QrCode::query()->where('kind', QrCode::KIND_FLYER)->where('created_by', $admin->id)->count());
    }

    public function test_active_requires_boolean_and_unchanged_writes_no_audit(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        QrCode::ensureFirst(QrCode::KIND_GUEST, $admin);
        $code = QrCode::newestActive(QrCode::KIND_GUEST);

        $this->put(route('backend.qr-codes.active', $code))->assertSessionHasErrors('active');
        $this->put(route('backend.qr-codes.active', $code), ['active' => 'maybe'])->assertSessionHasErrors('active');
        $this->assertTrue($code->fresh()->isActive());

        // Already active: no change, no audit entry.
        $this->put(route('backend.qr-codes.active', $code), ['active' => 1])->assertSessionMissing('status');
        $this->assertSame(0, AuditLog::query()->whereIn('action', ['qr_code.activate', 'qr_code.deactivate'])->count());
    }

    public function test_deactivating_the_last_active_code_warns(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        QrCode::ensureFirst(QrCode::KIND_GUEST, $admin);
        $first = QrCode::newestActive(QrCode::KIND_GUEST);
        $second = QrCode::generate(QrCode::KIND_GUEST, null, $admin);

        $this->put(route('backend.qr-codes.active', $first), ['active' => 0])
            ->assertSessionHas('status', __('qr.deactivated'))->assertSessionMissing('warning');
        $this->put(route('backend.qr-codes.active', $second), ['active' => 0])
            ->assertSessionHas('status', __('qr.deactivated'))->assertSessionHas('warning', __('qr.last_deactivated.guest'));
        $this->assertSame(2, AuditLog::query()->where('action', 'qr_code.deactivate')->count());
    }

    public function test_lock_timeout_in_ensure_first_is_skipped_silently(): void
    {
        $lock = \Mockery::mock(\Illuminate\Contracts\Cache\Lock::class);
        $lock->shouldReceive('block')->andThrow(new \Illuminate\Contracts\Cache\LockTimeoutException);
        \Illuminate\Support\Facades\Cache::shouldReceive('lock')->andReturn($lock);

        QrCode::ensureFirst(QrCode::KIND_GUEST, $this->admin());

        $this->assertSame(0, QrCode::query()->count());
    }
}
