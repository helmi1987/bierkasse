<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use Pdo\Mysql;
use RuntimeException;
use Throwable;

/**
 * Pure-PHP database dump and restore (no mysqldump/mysql client needed).
 *
 * Dump format: plain SQL. Every statement ends with ";" at the end of a line and
 * string literals never contain a raw line break, so the restore can split the
 * file line by line (it still tracks quotes to be safe).
 */
class DatabaseBackup
{
    /** File name of backups written by db:backup (also the only names the backend serves). */
    public const FILE_PATTERN = '/^bierkasse-\d{4}-\d{2}-\d{2}-\d{6}\.sql\.gz$/';

    public const END_MARKER = '-- end of dump';

    private const BATCH = 200;

    public function __construct(private ?string $connection = null) {}

    public static function directory(): string
    {
        return storage_path('app/backups');
    }

    public static function filename(): string
    {
        return 'bierkasse-'.now()->format('Y-m-d-His').'.sql.gz';
    }

    public static function isBackupName(string $name): bool
    {
        return $name === basename($name) && preg_match(self::FILE_PATTERN, $name) === 1;
    }

    /**
     * Backups in the directory, newest first.
     *
     * @return list<array{name: string, size: int, time: int}>
     */
    public static function list(?string $directory = null): array
    {
        $directory ??= self::directory();
        if (! is_dir($directory)) {
            return [];
        }
        $files = [];
        foreach (scandir($directory) ?: [] as $name) {
            $path = $directory.'/'.$name;
            if (self::isBackupName($name) && is_file($path)) {
                $files[] = ['name' => $name, 'size' => (int) filesize($path), 'time' => (int) filemtime($path)];
            }
        }
        // The name contains the timestamp, so sorting by name is chronological.
        usort($files, fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $files;
    }

    /**
     * Deletes all but the newest $keep backups in the directory.
     *
     * @return list<string> deleted file names
     */
    public static function prune(int $keep, ?string $directory = null): array
    {
        $directory ??= self::directory();
        $deleted = [];
        foreach (array_slice(self::list($directory), max(0, $keep)) as $file) {
            if (@unlink($directory.'/'.$file['name'])) {
                $deleted[] = $file['name'];
            }
        }

        return $deleted;
    }

    public function connection(): Connection
    {
        return DB::connection($this->connection);
    }

    /**
     * Throws if the connection fails or its driver is not supported.
     */
    public function assertSupported(): void
    {
        $db = $this->connection();
        $this->family($db);
        $db->getPdo();
    }

    /**
     * Streams the dump to $write (called with SQL chunks).
     *
     * @param  callable(string): void  $write
     * @return array{tables: int, rows: int}
     */
    public function dump(callable $write): array
    {
        $db = $this->connection();

        return match ($this->family($db)) {
            'mysql' => $this->dumpMysql($db, $write),
            'sqlite' => $this->dumpSqlite($db, $write),
        };
    }

    /**
     * Executes a dump (gzip or plain SQL) statement by statement on the connection.
     *
     * @return int number of executed statements
     */
    public function restore(string $file): int
    {
        $db = $this->connection();
        $family = $this->family($db);
        $this->verify($file, $family);

        $pdo = $db->getPdo();
        $backslash = $family === 'mysql';
        $gz = $this->open($file);
        $buffer = '';
        $quote = null;
        $count = 0;
        try {
            while (($line = gzgets($gz)) !== false) {
                if ($buffer === '' && (str_starts_with($line, '--') || trim($line) === '')) {
                    continue;
                }
                $buffer .= $line;
                $this->scan($line, $quote, $backslash);
                if ($quote === null && str_ends_with(rtrim($line), ';')) {
                    $pdo->exec(rtrim($buffer));
                    $buffer = '';
                    $count++;
                }
            }
        } catch (Throwable $e) {
            if ($family === 'sqlite') {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable) {
                    // No open transaction.
                }
            }
            throw $e;
        } finally {
            gzclose($gz);
        }
        if (trim($buffer) !== '') {
            throw new RuntimeException('Dump ends with an unterminated statement.');
        }

        return $count;
    }

    private function family(Connection $db): string
    {
        $driver = $db->getDriverName();

        return match ($driver) {
            'mysql', 'mariadb' => 'mysql',
            'sqlite' => 'sqlite',
            default => throw new RuntimeException("Database backup is not supported for driver \"{$driver}\" (only MySQL/MariaDB and SQLite)."),
        };
    }

    /**
     * Checks driver and completeness before anything is executed.
     */
    private function verify(string $file, string $family): void
    {
        $gz = $this->open($file);
        $driver = null;
        $last = '';
        try {
            while (($line = gzgets($gz)) !== false) {
                if ($driver === null && preg_match('/^-- Driver: (\w+)/', $line, $m)) {
                    $driver = $m[1];
                }
                if (trim($line) !== '') {
                    $last = rtrim($line);
                }
            }
        } finally {
            gzclose($gz);
        }
        if ($driver === null) {
            throw new RuntimeException('Not a Bierkasse dump (no "-- Driver:" header).');
        }
        if ($driver !== $family) {
            throw new RuntimeException("Dump was made with driver {$driver}, target connection is {$family}.");
        }
        if ($last !== self::END_MARKER) {
            throw new RuntimeException('Dump is incomplete (end marker missing).');
        }
    }

    /**
     * @return resource
     */
    private function open(string $file)
    {
        // gzopen also reads uncompressed files transparently.
        $gz = is_file($file) ? @gzopen($file, 'rb') : false;
        if ($gz === false) {
            throw new RuntimeException("Cannot read {$file}.");
        }

        return $gz;
    }

    /**
     * Tracks whether the line ends inside a quoted string or identifier.
     */
    private function scan(string $line, ?string &$quote, bool $backslash): void
    {
        $len = strlen($line);
        $i = 0;
        while ($i < $len) {
            if ($quote === null) {
                $i += strcspn($line, "'\"`", $i);
                if ($i < $len) {
                    $quote = $line[$i];
                    $i++;
                }

                continue;
            }
            $stops = $quote.($backslash && $quote !== '`' ? '\\' : '');
            $i += strcspn($line, $stops, $i);
            if ($i >= $len) {
                break;
            }
            if ($line[$i] === '\\') {
                $i += 2;

                continue;
            }
            // A doubled quote closes and reopens, which ends in the same state.
            $quote = null;
            $i++;
        }
    }

    private function header(Connection $db, string $family): string
    {
        $clean = fn ($v) => str_replace(["\r", "\n"], ' ', (string) $v);

        return '-- '.$clean(config('app.name')).' database backup'."\n"
            .'-- Date: '.now()->toIso8601String()."\n"
            .'-- Database: '.$clean($db->getDatabaseName())."\n"
            .'-- Driver: '.$family."\n"
            .'-- Laravel '.app()->version().', PHP '.PHP_VERSION."\n"
            .'-- Contains all data of this installation (incl. password hashes and encrypted settings; APP_KEY needed to decrypt). Keep it safe.'."\n\n";
    }

    /**
     * @param  callable(string): void  $write
     * @return array{tables: int, rows: int}
     */
    private function dumpMysql(Connection $db, callable $write): array
    {
        $pdo = $db->getPdo();
        $buffered = class_exists(Mysql::class) ? Mysql::ATTR_USE_BUFFERED_QUERY : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        // TIMESTAMP values depend on the session time zone; dump and restore in UTC.
        $oldZone = $pdo->query('SELECT @@session.time_zone')->fetchColumn();
        $pdo->exec("SET time_zone = '+00:00'");

        $quote = function ($v) use ($pdo): string {
            if ($v === null) {
                return 'NULL';
            }
            if (is_int($v) || is_float($v)) {
                return (string) $v;
            }
            $v = (string) $v;
            if (! mb_check_encoding($v, 'UTF-8')) {
                return '0x'.bin2hex($v);
            }

            // Keep every literal on one line (\n and \r are valid MySQL escapes).
            return str_replace(["\n", "\r"], ['\\n', '\\r'], $pdo->quote($v));
        };

        $write($this->header($db, 'mysql')
            ."SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n"
            ."SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
            ."SET @OLD_TIME_ZONE=@@TIME_ZONE, TIME_ZONE='+00:00';\n\n");

        $rows = 0;
        try {
            foreach ($tables as [$table]) {
                $id = '`'.str_replace('`', '``', $table).'`';
                $create = $pdo->query('SHOW CREATE TABLE '.$id)->fetch(PDO::FETCH_NUM)[1];
                $write("DROP TABLE IF EXISTS {$id};\n{$create};\n");

                $pdo->setAttribute($buffered, false);
                try {
                    $stmt = $pdo->query('SELECT * FROM '.$id);
                    $rows += $this->writeRows($stmt, $id, fn ($c) => '`'.str_replace('`', '``', $c).'`', $quote, $write);
                    $stmt->closeCursor();
                } finally {
                    $pdo->setAttribute($buffered, true);
                }
                $write("\n");
            }
        } finally {
            $pdo->exec('SET time_zone = '.$pdo->quote((string) $oldZone));
        }
        $write("SET TIME_ZONE=@OLD_TIME_ZONE;\nSET SQL_MODE=@OLD_SQL_MODE;\nSET UNIQUE_CHECKS=1;\nSET FOREIGN_KEY_CHECKS=1;\n".self::END_MARKER."\n");

        return ['tables' => count($tables), 'rows' => $rows];
    }

    /**
     * @param  callable(string): void  $write
     * @return array{tables: int, rows: int}
     */
    private function dumpSqlite(Connection $db, callable $write): array
    {
        $pdo = $db->getPdo();
        $ident = fn ($name) => '"'.str_replace('"', '""', $name).'"';
        $quote = function ($v) use ($pdo): string {
            if ($v === null) {
                return 'NULL';
            }
            if (is_int($v) || is_float($v)) {
                return (string) $v;
            }
            $v = (string) $v;
            if (! mb_check_encoding($v, 'UTF-8')) {
                return "X'".bin2hex($v)."'";
            }
            if (strpbrk($v, "\r\n") !== false) {
                // SQLite has no escape sequences; keep the literal on one line as hex text.
                return "CAST(X'".bin2hex($v)."' AS TEXT)";
            }

            return $pdo->quote($v);
        };

        $objects = $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY CASE type WHEN 'table' THEN 0 WHEN 'index' THEN 1 WHEN 'view' THEN 2 ELSE 3 END, name")
            ->fetchAll(PDO::FETCH_ASSOC);
        $hasSequence = (bool) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'sqlite_sequence'")->fetchColumn();

        $write($this->header($db, 'sqlite')."PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n\n");

        $tables = 0;
        $rows = 0;
        foreach ($objects as $object) {
            $name = $ident($object['name']);
            $sql = $object['sql'];
            if ($object['type'] === 'table') {
                $tables++;
                $write("DROP TABLE IF EXISTS {$name};\n{$sql};\n");
                $stmt = $pdo->query('SELECT * FROM '.$name);
                $rows += $this->writeRows($stmt, $name, $ident, $quote, $write);
                $stmt->closeCursor();
                $write("\n");
            } else {
                $drop = ['index' => 'INDEX', 'view' => 'VIEW', 'trigger' => 'TRIGGER'][$object['type']] ?? null;
                if ($drop !== null) {
                    $write("DROP {$drop} IF EXISTS {$name};\n{$sql};\n");
                }
            }
        }
        if ($hasSequence) {
            $write("DELETE FROM sqlite_sequence;\n");
            $stmt = $pdo->query('SELECT * FROM sqlite_sequence');
            $this->writeRows($stmt, 'sqlite_sequence', $ident, $quote, $write);
            $stmt->closeCursor();
        }
        $write("\nCOMMIT;\nPRAGMA foreign_keys=ON;\n".self::END_MARKER."\n");

        return ['tables' => $tables, 'rows' => $rows];
    }

    /**
     * Writes batched INSERT statements for all rows of $stmt.
     *
     * @param  callable(string): string  $ident
     * @param  callable(mixed): string  $quote
     * @param  callable(string): void  $write
     */
    private function writeRows(\PDOStatement $stmt, string $table, callable $ident, callable $quote, callable $write): int
    {
        $count = 0;
        $batch = [];
        $columns = null;
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $columns ??= '('.implode(', ', array_map($ident, array_keys($row))).')';
            $batch[] = '('.implode(', ', array_map($quote, $row)).')';
            $count++;
            if (count($batch) >= self::BATCH) {
                $write("INSERT INTO {$table} {$columns} VALUES\n".implode(",\n", $batch).";\n");
                $batch = [];
            }
        }
        if ($batch !== []) {
            $write("INSERT INTO {$table} {$columns} VALUES\n".implode(",\n", $batch).";\n");
        }

        return $count;
    }
}
