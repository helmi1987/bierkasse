<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\Audit;
use App\Services\DatabaseBackup;
use App\Services\Ledger;
use App\Support\AppSettings;
use App\Support\AuditPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function last(string $action): AuditLog
    {
        return AuditLog::query()->where('action', $action)->latest('id')->firstOrFail();
    }

    public function test_consume_and_undo_are_logged(): void
    {
        $this->setPrice(150);
        $this->setDefaultLimit(10000);
        $user = $this->user();
        $this->actingAs($user);

        $this->post('/konto/bier', $this->tokened('consume', ['quantity' => 2]))->assertSessionHas('status');
        $tx = Transaction::query()->sole();
        $entry = $this->last('consume');
        $this->assertSame($user->id, (int) $entry->actor_id);
        $this->assertSame($user->username, $entry->actor_name);
        $this->assertSame('Transaction', $entry->subject_type);
        $this->assertSame($tx->id, (int) $entry->subject_id);
        $this->assertSame(-300, $entry->newValues()['amount']);
        $this->assertSame(2, $entry->newValues()['quantity']);

        $this->post("/konto/bier/{$tx->id}/rueckgaengig")->assertSessionHas('status');
        $this->assertSame(-300, $this->last('consume.undo')->oldValues()['amount']);
    }

    public function test_deposit_confirm_and_reject_are_logged(): void
    {
        $user = $this->user();
        $kassier = $this->kassier();
        $a = Ledger::reportDeposit($user, 1000, 'cash');
        $b = Ledger::reportDeposit($user, 500, 'twint');
        $this->assertSame(2, AuditLog::query()->where('action', 'deposit.report')->count());

        $this->actingAs($kassier);
        $this->post("/backend/einzahlungen/{$a->id}/bestaetigen")->assertSessionHas('status');
        $this->post("/backend/einzahlungen/{$b->id}/ablehnen")->assertSessionHas('status');

        $confirm = $this->last('deposit.confirm');
        $this->assertSame($kassier->username, $confirm->actor_name);
        $this->assertEquals(['status' => 'pending', 'confirmed_by' => null, 'confirmed_at' => null, 'user_id' => $user->id], $confirm->oldValues());
        $this->assertSame('confirmed', $confirm->newValues()['status']);
        $this->assertSame('rejected', $this->last('deposit.reject')->newValues()['status']);
    }

    public function test_settings_change_masks_secrets(): void
    {
        $this->actingAs($this->admin());
        $this->put('/backend/einstellungen', [
            'beer_price' => '2.00',
            'default_limit' => '20',
            'registration_mode' => 'password',
            'registration_password' => 'geheim-1234',
            'mail_transport' => 'smtp',
            'smtp_host' => 'smtp.example.org',
            'smtp_password' => 'smtp-geheim',
        ])->assertSessionHasNoErrors();

        $entry = $this->last('settings.update');
        $new = $entry->newValues();
        $this->assertSame(200, $new['beer_price']);
        $this->assertSame('smtp.example.org', $new['mail.smtp_host']);
        $this->assertSame(Audit::MASK, $new['registration_password']);
        $this->assertSame(Audit::MASK, $new['mail.smtp_password']);

        $raw = AuditLog::query()->get()->map(fn ($e) => $e->old_values.$e->new_values)->implode('');
        $this->assertStringNotContainsString('geheim-1234', $raw);
        $this->assertStringNotContainsString('smtp-geheim', $raw);

        // Internal state keys are not logged.
        $count = AuditLog::query()->count();
        Setting::put(AppSettings::STOCK_LOW_WARNED, true);
        $this->assertSame($count, AuditLog::query()->count());
    }

    public function test_role_change_password_reset_and_login_failed(): void
    {
        $admin = $this->admin();
        $user = $this->user(['username' => 'hans']);
        $this->actingAs($admin);

        $this->put("/backend/benutzer/{$user->id}/rolle", ['role' => 'kassier'])->assertSessionHas('status');
        $role = $this->last('user.role');
        $this->assertSame(['role' => 'user'], $role->oldValues());
        $this->assertSame(['role' => 'kassier'], $role->newValues());

        $this->put("/backend/benutzer/{$user->id}/passwort", ['password' => 'neues-passwort', 'password_confirmation' => 'neues-passwort'])->assertSessionHas('status');
        $reset = $this->last('user.password_reset');
        $this->assertSame(Audit::MASK, $reset->newValues()['password']);
        $this->assertStringNotContainsString('neues-passwort', (string) $reset->new_values);

        $this->post('/abmelden');
        $this->post('/anmelden', ['username' => 'hans', 'password' => 'falsches-passwort'])->assertSessionHasErrors('username');
        $failed = $this->last('auth.login_failed');
        $this->assertSame(['username' => 'hans'], $failed->newValues());
        $this->assertNull($failed->actor_name);
        $this->assertStringNotContainsString('falsches-passwort', (string) $failed->new_values);

        $this->post('/anmelden', ['username' => 'hans', 'password' => 'neues-passwort']);
        $this->assertSame('hans', $this->last('auth.login')->actor_name);
    }

    public function test_backup_download_is_logged(): void
    {
        $this->actingAs($this->admin());
        $this->post('/backend/wartung/backup')->assertOk();

        $this->assertMatchesRegularExpression('/^bierkasse-.*\.sql\.gz$/', $this->last('backup.download')->newValues()['file']);
    }

    public function test_entries_cannot_be_changed_or_deleted_through_eloquent(): void
    {
        Audit::log('test.entry', null, [], ['a' => 1]);
        $entry = AuditLog::query()->sole();

        foreach ([
            fn () => $entry->forceFill(['action' => 'x'])->save(),
            fn () => $entry->delete(),
            fn () => AuditLog::query()->update(['action' => 'x']),
            fn () => AuditLog::query()->delete(),
            fn () => (new AuditLog)->forceFill(['action' => 'x'])->save(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected LogicException');
            } catch (LogicException) {
                // expected
            }
        }
        $this->assertSame('test.entry', AuditLog::query()->sole()->action);
    }

    public function test_integrity_check_ok_and_detects_tampering(): void
    {
        $admin = $this->admin();
        foreach (range(1, 3) as $i) {
            Audit::log('test.entry', null, [], ['n' => $i, 'text' => 'Bier für Zürich'], $admin);
        }
        $this->assertNull(Audit::verify());

        $this->actingAs($admin);
        $this->post('/backend/protokoll/pruefen')->assertSessionHas('status');

        // Change a row behind the app's back.
        $id = AuditLog::query()->where('action', 'test.entry')->orderBy('id')->skip(1)->value('id');
        DB::table('audit_logs')->where('id', $id)->update(['new_values' => '{"n":99}']);
        $this->assertSame(['id' => $id, 'reason' => 'changed'], Audit::verify());
        $this->post('/backend/protokoll/pruefen')->assertSessionHas('error', __('audit.verify_broken', ['id' => $id, 'reason' => __('audit.reason.changed')]));
    }

    public function test_integrity_check_detects_deleted_rows(): void
    {
        foreach (range(1, 3) as $i) {
            Audit::log('test.entry', null, [], ['n' => $i]);
        }
        $ids = AuditLog::query()->orderBy('id')->pluck('id');

        DB::table('audit_logs')->where('id', $ids[1])->delete();
        $this->assertSame(['id' => $ids[2], 'reason' => 'gap'], Audit::verify());
    }

    public function test_integrity_check_detects_deleted_last_row(): void
    {
        foreach (range(1, 2) as $i) {
            Audit::log('test.entry', null, [], ['n' => $i]);
        }
        DB::table('audit_logs')->where('id', AuditLog::query()->max('id'))->delete();
        $this->assertSame(['id' => null, 'reason' => 'tail'], Audit::verify());
    }

    public function test_backup_and_restore_keep_the_chain(): void
    {
        foreach (range(1, 3) as $i) {
            Audit::log('test.entry', null, [], ['n' => $i]);
        }
        $this->artisan('db:backup')->assertSuccessful();
        $file = DatabaseBackup::directory().'/'.DatabaseBackup::list()[0]['name'];

        $target = $this->testStorage.'/restore.sqlite';
        touch($target);
        config(['database.connections.restore' => ['driver' => 'sqlite', 'database' => $target, 'prefix' => '', 'foreign_key_constraints' => true]]);
        $this->artisan('db:restore', ['file' => $file, '--connection' => 'restore', '--force' => true])->assertSuccessful();

        $rows = fn (string $c) => DB::connection($c)->table('audit_logs')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $this->assertSame($rows(config('database.default')), $rows('restore'));
        $this->assertSame(
            (array) DB::table('audit_chain')->first(),
            (array) DB::connection('restore')->table('audit_chain')->first(),
        );
    }

    public function test_audit_page_for_kassier_and_admin_with_filters_and_detail(): void
    {
        $admin = $this->admin(['username' => 'chef']);
        $other = $this->kassier(['username' => 'kasse']);
        Audit::log('deposit.confirm', null, [], ['amount' => 1000], $admin);
        Audit::log('user.role', null, ['role' => 'user'], ['role' => 'admin'], $other);

        $this->actingAs($this->user());
        $this->get('/backend/protokoll')->assertForbidden();
        $this->post('/backend/protokoll/pruefen')->assertForbidden();
        $this->get('/backend/protokoll/export')->assertForbidden();

        $this->actingAs($other);
        $this->get('/backend')->assertOk()->assertSee(route('backend.audit'));
        $this->get('/backend/protokoll')->assertOk()->assertSee('user.role');

        $this->actingAs($admin);
        $this->get('/backend/protokoll')->assertOk()->assertSee('deposit.confirm')->assertSee('user.role');
        $this->get('/backend/protokoll?actor='.$other->id)->assertOk()->assertSee('user.role')->assertDontSee('<code>deposit.confirm</code>', false);
        $this->get('/backend/protokoll?action=deposit')->assertOk()->assertSee('<code>deposit.confirm</code>', false)->assertDontSee('<code>user.role</code>', false);
        $this->get('/backend/protokoll?q=kasse')->assertOk()->assertSee('<code>user.role</code>', false)->assertDontSee('<code>deposit.confirm</code>', false);
        $today = now()->format('Y-m-d');
        $this->get("/backend/protokoll?from={$today}&to={$today}")->assertOk()->assertSee('<code>user.role</code>', false);
        $this->get('/backend/protokoll?to=2000-01-01')->assertOk()->assertSee(__('audit.none'));

        $entry = AuditLog::query()->where('action', 'user.role')->sole();
        $this->get("/backend/protokoll/{$entry->id}")->assertOk()->assertSee('<td>admin</td>', false)->assertSee($entry->hash);

        $csv = $this->get('/backend/protokoll/export?action=user')->assertOk()->streamedContent();
        $this->assertStringContainsString('user.role', $csv);
        $this->assertStringNotContainsString('deposit.confirm', $csv);

        // No route changes or deletes entries.
        $this->delete("/backend/protokoll/{$entry->id}")->assertStatus(405);
    }

    public function test_list_shows_german_labels_subject_names_and_summary(): void
    {
        $admin = $this->admin(['username' => 'chef']);
        $target = $this->user(['username' => 'hans', 'first_name' => 'Hans', 'last_name' => 'Muster']);
        Audit::log('user.role', $target, ['role' => 'user'], ['role' => 'kassier'], $admin);
        $tx = Ledger::reportDeposit($target, 1250, 'cash');
        Audit::log('custom.unknown', null, [], ['foo' => 'bar'], $admin);

        $this->actingAs($admin);
        $this->get('/backend/protokoll')->assertOk()
            ->assertSee(__('audit.details'))
            ->assertSee('Rolle geändert')->assertSee('<code>user.role</code>', false)
            ->assertSee('Rolle: user → kassier')
            ->assertSee('hans (Hans Muster)')
            ->assertSee('Einzahlung gemeldet')
            ->assertSee('Einzahlung CHF 12.50 · hans (Hans Muster)')
            ->assertSee('CHF 12.50')
            // Unknown code: raw code as label.
            ->assertSee('<code>custom.unknown</code>', false)
            // Filter dropdown: German label, the code stays the value.
            ->assertSee('<option value="deposit" >Einzahlung</option>', false)
            ->assertSee('<option value="user" >Benutzer</option>', false);
        $this->assertNotNull($tx->id);
    }

    public function test_list_loads_subjects_in_batches(): void
    {
        $admin = $this->admin();
        foreach (range(1, 20) as $i) {
            $u = $this->user();
            Audit::log('user.role', $u, ['role' => 'user'], ['role' => 'kassier'], $admin);
        }

        $this->actingAs($admin);
        DB::enableQueryLog();
        $this->get('/backend/protokoll')->assertOk();
        $userQueries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'from "users" where "users"."id" in'));
        $this->assertGreaterThanOrEqual(1, count($userQueries));
        $this->assertLessThanOrEqual(2, count($userQueries));
    }

    public function test_unknown_subject_falls_back_to_type_and_id(): void
    {
        $admin = $this->admin();
        $gone = $this->user();
        Audit::log('user.status', $gone, ['status' => 'active'], ['status' => 'left'], $admin);
        DB::table('users')->where('id', $gone->id)->delete();
        Audit::log('feedback.delete', null, ['user_id' => 999999, 'type' => 'idea'], [], $admin);
        $row = AuditLog::query()->latest('id')->first();

        $this->actingAs($admin);
        $this->get('/backend/protokoll')->assertOk()->assertSee('Benutzer #'.$gone->id);
        $this->get("/backend/protokoll/{$row->id}")->assertOk()
            ->assertSee('Feedback gelöscht')
            ->assertSee('gelöschter Benutzer #999999');
    }

    public function test_detail_formats_values(): void
    {
        $admin = $this->admin(['username' => 'chef', 'first_name' => null, 'last_name' => null]);
        $user = $this->user(['username' => 'hans', 'first_name' => null, 'last_name' => null]);
        Audit::log('settings.update', null, [
            'beer_price' => 150, 'gifts_enabled' => false, 'app_name' => null, 'deposit_suggestions' => [1000, 2000],
        ], [
            'beer_price' => 200, 'gifts_enabled' => true, 'app_name' => 'Kasse', 'deposit_suggestions' => [500],
            'registration_password' => 'geheim', 'twint_recipients' => [['name' => 'Anna', 'phone' => '079']],
        ], $admin);
        $settings = AuditLog::query()->latest('id')->first();
        Audit::log('deposit.confirm', null, ['status' => 'pending', 'confirmed_by' => null, 'user_id' => $user->id], [
            'status' => 'confirmed', 'confirmed_by' => $admin->id, 'confirmed_at' => '2026-10-24T10:05:00.000000Z', 'user_id' => $user->id,
        ], $admin);
        $deposit = AuditLog::query()->latest('id')->first();

        $this->actingAs($admin);
        $this->get("/backend/protokoll/{$settings->id}")->assertOk()
            ->assertSee('Einstellungen geändert')->assertSee('<code>settings.update</code>', false)
            ->assertSee('Bierpreis')->assertSee('<td>CHF 1.50</td>', false)->assertSee('<td>CHF 2.00</td>', false)
            ->assertSee('<td>nein</td>', false)->assertSee('<td>ja</td>', false)
            ->assertSee('<td>–</td>', false)
            ->assertSee('CHF 10.00, CHF 20.00')
            ->assertSee(__('audit.masked'))->assertDontSee('geheim</td>', false)
            ->assertSee('name: Anna, phone: 079');

        $this->get("/backend/protokoll/{$deposit->id}")->assertOk()
            ->assertSee('Bestätigt von')->assertSee('<td>chef</td>', false)
            ->assertSee('<td>hans</td>', false)
            // UTC 10:05 → Zurich 12:05 (summer time).
            ->assertSee('24.10.2026 12:05');
    }

    public function test_presenter_summary_is_limited(): void
    {
        $admin = $this->admin();
        Audit::log('settings.update', null, [], ['maintenance_message' => str_repeat('x', 200)], $admin);
        $entry = AuditLog::query()->latest('id')->first();

        $summary = AuditPresenter::for([$entry])->summary($entry);
        $this->assertLessThanOrEqual(AuditPresenter::SUMMARY_MAX + 3, mb_strlen($summary));
        $this->assertStringStartsWith('Wartungs-Mitteilung: xxx', $summary);
    }
}
