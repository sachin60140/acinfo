<?php

namespace App\Console\Commands;

use App\Support\Backups;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Writes the whole database to a file that can be fed back into MySQL.
 *
 * This application is a ledger. What it holds — what each customer owes, what
 * each vendor is owed, which files went out on which day — exists in one place
 * and nowhere else, and a dropped table, a bad migration or a host that loses a
 * disk takes all of it. Nothing here backed any of it up.
 *
 * Written by hand rather than shelling out to mysqldump, because shared hosting
 * usually does not have mysqldump and a backup that only runs on a developer's
 * laptop is not a backup. It reads through the application's own connection, so
 * it uses the credentials already in .env and nothing has to be told twice.
 *
 * Run it from cron, once a night:
 *
 *   cd /home/<user>/domains/<site> && php artisan db:backup --keep=14
 *
 * And restore with:
 *
 *   gunzip < storage/app/backups/<file>.sql.gz | mysql -u <user> -p <database>
 *
 * A backup nobody has restored is a guess. BackupDatabaseTest restores every
 * dump it writes into a scratch database and compares it, row for row.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup
        {--keep=14 : How many backups to keep, newest first}
        {--path= : Where to write, if not storage/app/backups}
        {--connection= : Which database connection to back up}';

    protected $description = 'Write the database to a restorable .sql.gz file, and prune old ones';

    /** Rows read and written at a time, so memory stays flat as the tables grow. */
    private const CHUNK = 500;

    public function handle(): int
    {
        $connection = DB::connection($this->option('connection') ?: null);
        $database = $connection->getDatabaseName();

        // Where the dashboard's Last Backup tile looks, unless told otherwise.
        $directory = $this->option('path') ?: Backups::directory();

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error('Cannot create '.$directory);

            return self::FAILURE;
        }

        $name = $database.'-'.date('Y-m-d-Hi').'.sql.gz';
        $final = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$name;

        /*
         * Written under another name and renamed at the end.
         *
         * A backup interrupted half way through — the host reboots, the process
         * is killed — must not be left sitting in the folder looking like the
         * night's backup. Only a finished file gets the name.
         */
        $partial = $final.'.writing';
        $out = gzopen($partial, 'wb6');

        if (! $out) {
            $this->error('Cannot write '.$partial);

            return self::FAILURE;
        }

        try {
            $tables = $this->tables($connection, $database);

            $this->write($out, "-- $database, written ".date('Y-m-d H:i:s')." by artisan db:backup\n");
            $this->write($out, "-- Restore: gunzip < this-file | mysql -u <user> -p $database\n\n");
            $this->write($out, "SET NAMES utf8mb4;\n");
            $this->write($out, "SET FOREIGN_KEY_CHECKS = 0;\n");
            // The tables are written in whatever order they come back in, so a
            // row may point at a parent that has not been written yet.
            $this->write($out, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

            $rows = 0;

            foreach ($tables as $table) {
                $rows += $this->dumpTable($connection, $out, $table);
            }

            $this->write($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
        } catch (\Throwable $e) {
            gzclose($out);
            @unlink($partial);

            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        gzclose($out);

        if (! rename($partial, $final)) {
            @unlink($partial);
            $this->error('Cannot finish '.$final);

            return self::FAILURE;
        }

        // It is the whole ledger in one file. Nobody else on the host needs it.
        @chmod($final, 0600);

        $this->info(sprintf(
            '%s — %d tables, %s rows, %s',
            $name,
            count($tables),
            number_format($rows),
            Backups::readable((int) filesize($final))
        ));

        $this->prune($directory, $database);

        return self::SUCCESS;
    }

    /**
     * The real tables, without the views.
     *
     * A view holds nothing of its own, and writing one back as a table would
     * turn a derived answer into stale data that looks like a record.
     *
     * @return array<int, string>
     */
    private function tables($connection, string $database): array
    {
        $names = [];

        foreach ($connection->select('SHOW FULL TABLES') as $row) {
            $row = (array) $row;

            if (strtoupper((string) end($row)) === 'VIEW') {
                continue;
            }

            $names[] = (string) reset($row);
        }

        sort($names);

        return $names;
    }

    /** One table: how to build it, then what is in it. Returns the row count. */
    private function dumpTable($connection, $out, string $table): int
    {
        $create = (array) $connection->selectOne('SHOW CREATE TABLE '.$this->quoteName($table));

        $this->write($out, "\n--\n-- $table\n--\n\n");
        $this->write($out, 'DROP TABLE IF EXISTS '.$this->quoteName($table).";\n");
        $this->write($out, ((string) end($create)).";\n\n");

        $count = 0;
        $values = [];

        foreach ($connection->table($table)->cursor() as $row) {
            $row = (array) $row;

            if ($count === 0) {
                $columns = implode(', ', array_map(fn ($c) => $this->quoteName($c), array_keys($row)));
                $insert = 'INSERT INTO '.$this->quoteName($table)." ($columns) VALUES\n";
            }

            $values[] = '('.implode(', ', array_map(fn ($v) => $this->literal($connection, $v), $row)).')';
            $count++;

            if (count($values) >= self::CHUNK) {
                $this->write($out, $insert.implode(",\n", $values).";\n");
                $values = [];
            }
        }

        if ($values) {
            $this->write($out, $insert.implode(",\n", $values).";\n");
        }

        return $count;
    }

    /**
     * One value, as SQL.
     *
     * Anything that is not valid UTF-8 is written as hex rather than quoted:
     * a quoted blob depends on the client's charset to survive the round trip,
     * and 0x… does not depend on anything.
     */
    private function literal($connection, $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $value = (string) $value;

        if ($value !== '' && ! mb_check_encoding($value, 'UTF-8')) {
            return '0x'.bin2hex($value);
        }

        return $connection->getPdo()->quote($value);
    }

    /** A name, quoted the way MySQL quotes its own. */
    private function quoteName(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private function write($out, string $sql): void
    {
        if (gzwrite($out, $sql) === false) {
            throw new \RuntimeException('Cannot write to the backup file — is the disk full?');
        }
    }

    /**
     * Keeps the newest few and deletes the rest.
     *
     * Only files this command wrote for this database, matched by name: a
     * backup folder is exactly where somebody keeps the one good copy they took
     * by hand before a risky release, and deleting that would be worse than
     * keeping too many.
     *
     * By the whole name, not the prefix. A copy named by hand —
     * "<database>-before-the-release.sql.gz" — matched the prefix, sorted
     * above every dated one because letters come after digits, and was kept
     * as the newest while a real night's backup was deleted in its place.
     */
    private function prune(string $directory, string $database): void
    {
        $keep = max(1, (int) $this->option('keep'));

        // Newest first: the names carry the date, so they sort by age.
        $files = array_reverse(Backups::written($directory, $database));

        foreach (array_slice($files, $keep) as $old) {
            if (@unlink($old)) {
                $this->line('  removed '.basename($old));
            }
        }

        /*
         * And what an earlier run killed part way left behind — the host, a
         * time limit, Ctrl+C — which no other step ever removes. This one
         * finished, so those are history; an hour old at least, so a run
         * still going alongside is not taken for one.
         */
        foreach (Backups::abandoned($directory, $database) as $partial) {
            if (@unlink($partial)) {
                $this->line('  removed unfinished '.basename($partial));
            }
        }
    }
}
