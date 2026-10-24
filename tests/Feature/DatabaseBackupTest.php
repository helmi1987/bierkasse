<?php

namespace Tests\Feature;

use App\Services\DatabaseBackup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = 'bierkasse-2026-10-01-120000';

    private function seedProbe(): void
    {
        Schema::create('backup_probe', function (Blueprint $table) {
            $table->id();
            $table->text('text')->nullable();
            $table->integer('number')->nullable();
            $table->float('amount')->nullable();
            $table->binary('data')->nullable();
        });
        $texts = [
            "Zeile 1\nZeile 2\r\nZeile 3",
            "O'Reilly \"zitiert\" `tick`",
            "ends with a semicolon;\n-- not a comment;\n",
            'Prost 🍺 Grüezi',
            'back\\slash \\n literal',
            null,
            '',
            '0042',
        ];
        foreach ($texts as $i => $text) {
            DB::table('backup_probe')->insert(['text' => $text, 'number' => $i - 3, 'amount' => $i + 0.25, 'data' => null]);
        }
        // More rows than one INSERT batch.
        DB::table('backup_probe')->insert(array_map(fn ($i) => ['text' => "row {$i}", 'number' => $i, 'amount' => null, 'data' => null], range(1, 450)));
        // Deleting the highest id checks that the autoincrement counter survives.
        DB::table('backup_probe')->whereIn('id', [3, 458])->delete();
        $this->user(['username' => 'zoë']);
        $this->admin(['username' => "o'chef"]);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function snapshot(string $connection): array
    {
        $db = DB::connection($connection);
        $out = [];
        $names = $db->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name");
        foreach ($names as $row) {
            $name = $row->name;
            $out[$name] = array_map(fn ($r) => (array) $r, $db->select('SELECT * FROM "'.$name.'" ORDER BY rowid'));
            $types = array_map(fn ($c) => 'typeof("'.$c->name.'")', $db->select('PRAGMA table_info("'.$name.'")'));
            $out[$name.':types'] = array_map(fn ($r) => array_values((array) $r), $db->select('SELECT '.implode(', ', $types).' FROM "'.$name.'" ORDER BY rowid'));
        }
        $out['sqlite_sequence'] = array_map(fn ($r) => (array) $r, $db->select('SELECT * FROM sqlite_sequence ORDER BY name'));
        $out['schema'] = array_map(fn ($r) => (array) $r, $db->select("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY type, name"));

        return $out;
    }

    private function backupFile(string $name = self::NAME.'.sql.gz', string $content = "-- dump\n"): string
    {
        File::ensureDirectoryExists(DatabaseBackup::directory());
        $path = DatabaseBackup::directory().'/'.$name;
        file_put_contents($path, gzencode($content));

        return $path;
    }

    public function test_command_writes_a_gzip_dump(): void
    {
        $this->seedProbe();
        Carbon::setTestNow('2026-10-21 14:05:09');

        $this->artisan('db:backup')->expectsOutputToContain('Tables:')->assertSuccessful();

        $file = DatabaseBackup::directory().'/bierkasse-2026-10-21-140509.sql.gz';
        $this->assertFileExists($file);
        $sql = gzdecode((string) file_get_contents($file));
        $this->assertIsString($sql);
        $this->assertStringContainsString('-- Driver: sqlite', $sql);
        $this->assertStringContainsString('Contains all data of this installation', $sql);
        $this->assertStringContainsString('CREATE TABLE "users"', $sql);
        $this->assertStringContainsString('INSERT INTO "users"', $sql);
        $this->assertStringContainsString('INSERT INTO "backup_probe"', $sql);
        $this->assertStringEndsWith(DatabaseBackup::END_MARKER."\n", $sql);
        $this->assertSame([], glob(DatabaseBackup::directory().'/*.part'));
    }

    public function test_keep_prunes_only_older_matching_backups(): void
    {
        $this->backupFile('bierkasse-2026-01-01-000000.sql.gz');
        $this->backupFile('bierkasse-2026-02-01-000000.sql.gz');
        $this->backupFile('bierkasse-2026-03-01-000000.sql.gz');
        $this->backupFile('other.sql.gz');
        Carbon::setTestNow('2026-10-21 14:05:09');

        $this->artisan('db:backup', ['--keep' => 2])->assertSuccessful();

        $names = array_map('basename', glob(DatabaseBackup::directory().'/*'));
        sort($names);
        $this->assertSame(['bierkasse-2026-03-01-000000.sql.gz', 'bierkasse-2026-10-21-140509.sql.gz', 'other.sql.gz'], $names);
        $this->artisan('db:backup', ['--keep' => 0])->assertFailed();
    }

    public function test_command_with_custom_path(): void
    {
        $target = $this->testStorage.'/elsewhere/dump.sql.gz';
        File::ensureDirectoryExists(dirname($target));

        $this->artisan('db:backup', ['--path' => $target])->assertSuccessful();

        $this->assertStringContainsString(DatabaseBackup::END_MARKER, (string) gzdecode((string) file_get_contents($target)));
    }

    public function test_restore_round_trip_is_exact(): void
    {
        $this->seedProbe();
        $this->artisan('db:backup')->assertSuccessful();
        $file = DatabaseBackup::directory().'/'.DatabaseBackup::list()[0]['name'];

        $target = $this->testStorage.'/restore.sqlite';
        touch($target);
        config(['database.connections.restore' => [
            'driver' => 'sqlite', 'database' => $target, 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        // Existing data in the target is replaced.
        DB::connection('restore')->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, junk TEXT)');
        DB::connection('restore')->insert("INSERT INTO users (junk) VALUES ('x')");

        $this->artisan('db:restore', ['file' => $file, '--connection' => 'restore', '--force' => true])->assertSuccessful();

        $restored = $this->snapshot('restore');
        $this->assertSame($this->snapshot(config('database.default')), $restored);
        $texts = array_column($restored['backup_probe'], 'text');
        $this->assertContains("Zeile 1\nZeile 2\r\nZeile 3", $texts);
        $this->assertContains('Prost 🍺 Grüezi', $texts);
        $this->assertContains(null, $texts);
        $this->assertCount(456, $restored['backup_probe']);

        // Autoincrement continues after the highest id as in the original.
        $next = DB::connection('restore')->table('backup_probe')->insertGetId(['text' => 'new']);
        $this->assertSame(459, $next);
    }

    public function test_restore_asks_for_confirmation_and_rejects_bad_files(): void
    {
        $this->artisan('db:backup')->assertSuccessful();
        $file = DatabaseBackup::directory().'/'.DatabaseBackup::list()[0]['name'];

        $this->artisan('db:restore', ['file' => $file])
            ->expectsConfirmation("Restore {$file} into sqlite (sqlite, database :memory:)? All tables contained in the dump are dropped and replaced.", 'no')
            ->assertFailed();

        $sql = (string) gzdecode((string) file_get_contents($file));
        $truncated = $this->testStorage.'/truncated.sql.gz';
        file_put_contents($truncated, gzencode(substr($sql, 0, (int) (strlen($sql) / 2))));
        $this->artisan('db:restore', ['file' => $truncated, '--force' => true])
            ->expectsOutputToContain('incomplete')->assertFailed();

        $mysql = $this->testStorage.'/mysql.sql';
        file_put_contents($mysql, str_replace('-- Driver: sqlite', '-- Driver: mysql', $sql));
        $this->artisan('db:restore', ['file' => $mysql, '--force' => true])
            ->expectsOutputToContain('driver mysql')->assertFailed();

        $this->artisan('db:restore', ['file' => $this->testStorage.'/missing.sql.gz', '--force' => true])->assertFailed();
    }

    public function test_unsupported_driver_is_rejected(): void
    {
        config(['database.connections.pg_test' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'x', 'username' => 'x', 'password' => '']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not supported for driver "pgsql"');
        (new DatabaseBackup('pg_test'))->dump(fn () => null);
    }

    public function test_admin_downloads_a_streamed_gzip_backup(): void
    {
        $this->seedProbe();
        Log::spy();
        Carbon::setTestNow('2026-10-21 14:05:09');
        $this->actingAs($this->admin(['username' => 'chef']));

        $response = $this->post('/admin/wartung/backup')->assertOk();
        $response->assertHeader('Content-Type', 'application/gzip');
        $this->assertStringContainsString('bierkasse-2026-10-21-140509.sql.gz', (string) $response->headers->get('Content-Disposition'));
        $sql = gzdecode($response->streamedContent());
        $this->assertIsString($sql);
        $this->assertStringContainsString('INSERT INTO "backup_probe"', $sql);
        $this->assertStringEndsWith(DatabaseBackup::END_MARKER."\n", $sql);
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx = []) => $msg === 'Maintenance: backup downloaded' && $ctx['admin'] === 'chef');
        // Nothing is stored on the server.
        $this->assertSame([], DatabaseBackup::list());
    }

    public function test_admin_lists_downloads_and_deletes_stored_backups(): void
    {
        $path = $this->backupFile(self::NAME.'.sql.gz', "-- stored dump\n");
        Log::spy();
        $this->actingAs($this->admin(['username' => 'chef']));

        $this->get('/admin/wartung')->assertOk()
            ->assertSee('Datenbank-Backup')
            ->assertSee('Backup herunterladen')
            ->assertSee(self::NAME.'.sql.gz')
            ->assertSee(route('backend.maintenance.backup.file', self::NAME));

        $response = $this->get('/admin/wartung/backup/'.self::NAME)->assertOk();
        $response->assertDownload(self::NAME.'.sql.gz');
        $this->assertSame("-- stored dump\n", gzdecode((string) file_get_contents($response->baseResponse->getFile()->getPathname())));

        $this->delete('/admin/wartung/backup/'.self::NAME)->assertSessionHas('status');
        $this->assertFileDoesNotExist($path);
        $this->delete('/admin/wartung/backup/'.self::NAME)->assertNotFound();
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx = []) => $msg === 'Maintenance: stored backup downloaded' && $ctx['admin'] === 'chef');
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx = []) => $msg === 'Maintenance: stored backup deleted' && $ctx['file'] === self::NAME.'.sql.gz');
    }

    public function test_only_backup_files_can_be_downloaded(): void
    {
        $this->backupFile();
        file_put_contents($this->testStorage.'/app/secret.txt', 'secret');
        $this->actingAs($this->admin());

        foreach ([
            '/admin/wartung/backup/..%2Fsecret.txt',
            '/admin/wartung/backup/..%2F..%2F.env',
            '/admin/wartung/backup/%2E%2E',
            '/admin/wartung/backup/'.self::NAME.'.sql.gz',
            '/admin/wartung/backup/'.self::NAME.'%2F..%2F..%2Fsecret',
            '/admin/wartung/backup/bierkasse-2026-01-01-000000',
        ] as $url) {
            $this->get($url)->assertNotFound();
            $this->delete($url)->assertNotFound();
        }
        $this->assertFileExists($this->testStorage.'/app/secret.txt');
        $this->assertFalse(DatabaseBackup::isBackupName('../'.self::NAME.'.sql.gz'));
    }

    public function test_users_and_kassiers_cannot_use_backups(): void
    {
        $path = $this->backupFile();

        foreach ([$this->user(), $this->kassier()] as $who) {
            $this->actingAs($who);
            $this->post('/admin/wartung/backup')->assertForbidden();
            $this->get('/admin/wartung/backup/'.self::NAME)->assertForbidden();
            $this->delete('/admin/wartung/backup/'.self::NAME)->assertForbidden();
        }
        $this->assertFileExists($path);

        auth()->logout();
        $this->post('/admin/wartung/backup')->assertRedirect();
        $this->get('/admin/wartung/backup/'.self::NAME)->assertRedirect();
    }
}
