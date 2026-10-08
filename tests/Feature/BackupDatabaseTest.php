<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The backup, restored.
 *
 * A dump nobody has fed back into MySQL is a guess, so these tests do not read
 * the file and check it looks about right: they build a database, back it up,
 * restore it into an empty one and compare the two, row for row and byte for
 * byte. If the escaping is wrong on one apostrophe, the restore says so.
 *
 * One property here is not covered and cannot easily be: the command writes to
 * <name>.writing and renames it at the end, so a backup killed half way through
 * is never left in the folder looking like the night's backup. Proving that
 * needs a process killed mid-dump, which is a slow, timing-dependent test for a
 * two-line guard — so it is stated here instead of asserted.
 *
 * No DatabaseTransactions here, deliberately. CREATE DATABASE commits whatever
 * transaction is open in MySQL, so a test that wrapped itself in one and then
 * built a scratch database would quietly commit its fixtures into the live
 * ledger. Nothing in here writes to the application's own tables — it makes its
 * own databases and drops them again.
 */
class BackupDatabaseTest extends TestCase
{
    private string $folder;

    /** @var array<int, string> */
    private array $databases = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/backups-'.uniqid());
        File::ensureDirectoryExists($this->folder);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        foreach ($this->databases as $name) {
            // A session still inside a transaction on the scratch database holds
            // its tables, and DROP DATABASE would wait for it — for a year, by
            // MySQL's default — instead of letting a failed test fail.
            DB::disconnect($name);
            DB::disconnect($name.'_office');

            DB::statement('DROP DATABASE IF EXISTS `'.$name.'`');
        }

        parent::tearDown();
    }

    /** A scratch database, and a connection pointing at it. */
    private function scratch(string $tag): string
    {
        $name = 'acinfo_bk_'.$tag.'_'.substr(uniqid(), -8);

        DB::statement('CREATE DATABASE `'.$name.'`');
        $this->databases[] = $name;

        config(['database.connections.'.$name => array_merge(
            config('database.connections.'.config('database.default')),
            ['database' => $name]
        )]);

        return $name;
    }

    private function on(string $database)
    {
        return DB::connection($database);
    }

    /** A second session on the same database: somebody else in the office, saving. */
    private function office(string $database)
    {
        config(['database.connections.'.$database.'_office' => config('database.connections.'.$database)]);

        return DB::connection($database.'_office');
    }

    /**
     * Yesterday's payment, adjusted against its file, in two tables named as
     * the ledger's are — so party_ledger is read before party_ledger_allocation,
     * as it is in the real one.
     */
    private function ledger(string $tag): string
    {
        $source = $this->scratch($tag);

        $this->on($source)->unprepared('CREATE TABLE `party_ledger` (`id` int unsigned NOT NULL AUTO_INCREMENT,
            `amount` decimal(12,2) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
        $this->on($source)->unprepared('CREATE TABLE `party_ledger_allocation` (`id` int unsigned NOT NULL AUTO_INCREMENT,
            `entry_id` int unsigned NOT NULL, `amount` decimal(12,2) NOT NULL, PRIMARY KEY (`id`),
            CONSTRAINT `fk_entry` FOREIGN KEY (`entry_id`) REFERENCES `party_ledger` (`id`)) ENGINE=InnoDB');

        $yesterday = $this->on($source)->table('party_ledger')->insertGetId(['amount' => 1000]);
        $this->on($source)->table('party_ledger_allocation')->insert(['entry_id' => $yesterday, 'amount' => 1000]);

        return $source;
    }

    /** Today's payment and its adjustment, saved together as the Entry screen saves them. */
    private function payToday($office): void
    {
        $office->transaction(function () use ($office) {
            $today = $office->table('party_ledger')->insertGetId(['amount' => 500]);
            $office->table('party_ledger_allocation')->insert(['entry_id' => $today, 'amount' => 500]);
        });
    }

    private function backup(string $database, array $options = []): int
    {
        return $this->artisan('db:backup', array_merge([
            '--connection' => $database,
            '--path' => $this->folder,
        ], $options))->run();
    }

    /** @return array<int, string> */
    private function written(): array
    {
        $files = glob($this->folder.DIRECTORY_SEPARATOR.'*.sql.gz') ?: [];
        sort($files);

        return $files;
    }

    /** Feeds a dump back into a database, the way the restore line in the file does. */
    private function restore(string $file, string $database): void
    {
        $sql = (string) gzdecode((string) file_get_contents($file));
        $connection = $this->on($database);

        foreach (preg_split('/;\n/', $sql) as $statement) {
            $statement = trim($statement);

            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }

            $connection->unprepared($statement);
        }
    }

    // ------------------------------------------------------------- the round trip

    /**
     * Everything that has ever broken a hand-rolled dump, in one table.
     *
     * The apostrophe in a customer's name, the backslash in a Windows path
     * somebody pasted into a remark, the newline in an address, the rupee
     * amounts, the Devanagari, and a semicolon at the end of a line — which is
     * exactly what the restore splits statements on.
     */
    public function test_a_database_comes_back_exactly_as_it_went_in(): void
    {
        $source = $this->scratch('src');

        $this->on($source)->unprepared("
            CREATE TABLE `awkward` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `note` text COLLATE utf8mb4_unicode_ci,
                `amount` decimal(12,2) DEFAULT NULL,
                `seen_on` date DEFAULT NULL,
                `raw` blob,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $notes = [
            "O'Brien & Co.",
            'C:\\xampp\\htdocs — a pasted path',
            "two\nlines",
            "a tab\there",
            'सुमन जी, पटना',
            'ended with a semicolon;',
            "and one;\nfollowed by a newline",
            '"double quoted"',
            '',
            null,
        ];

        foreach ($notes as $i => $note) {
            $this->on($source)->table('awkward')->insert([
                'note' => $note,
                'amount' => $i % 2 ? null : 1234.56,
                'seen_on' => $i % 3 ? null : '2026-09-18',
                'raw' => $i === 0 ? "\x00\x01\x02binary\xff" : null,
            ]);
        }

        $this->assertSame(0, $this->backup($source), 'the backup did not succeed');

        $target = $this->scratch('dst');
        $this->restore($this->written()[0], $target);

        $before = $this->on($source)->table('awkward')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $after = $this->on($target)->table('awkward')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->assertEquals($before, $after, 'the restored rows are not what was backed up');
        $this->assertCount(count($notes), $after);

        /*
         * The bytes that are not text are written as hex.
         *
         * They survive this restore either way, because it goes back through
         * the same PDO connection at the same charset. The restore in the file's
         * own header does not: `mysql` reads the dump as utf8mb4 text, and a
         * quoted \xff is a byte that charset has no letter for. 0x… is not text
         * and cannot be re-read as any.
         */
        $sql = (string) gzdecode((string) file_get_contents($this->written()[0]));

        $this->assertStringContainsString('0x'.bin2hex("\x00\x01\x02binary\xff"), $sql, 'a blob was written as text');

        // The table itself came back as it was, not as MySQL's defaults.
        $create = function ($db) {
            $row = (array) $this->on($db)->selectOne('SHOW CREATE TABLE `awkward`');

            return end($row);
        };

        $this->assertSame($create($source), $create($target));
    }

    /** An empty table is still a table, and its shape is worth keeping. */
    public function test_a_table_with_nothing_in_it_still_comes_back(): void
    {
        $source = $this->scratch('empty');
        $this->on($source)->unprepared('CREATE TABLE `nothing_yet` (`id` int NOT NULL, PRIMARY KEY (`id`))');

        $this->backup($source);

        $target = $this->scratch('emptydst');
        $this->restore($this->written()[0], $target);

        $this->assertTrue($this->on($target)->getSchemaBuilder()->hasTable('nothing_yet'));
        $this->assertSame(0, $this->on($target)->table('nothing_yet')->count());
    }

    /**
     * A row pointing at a parent written later must not stop the restore.
     *
     * The tables go in by name, so a child can easily land before its parent.
     */
    public function test_rows_that_point_at_each_other_restore_in_any_order(): void
    {
        $source = $this->scratch('fk');

        $this->on($source)->unprepared('CREATE TABLE `zebra_parent` (`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
        $this->on($source)->unprepared('CREATE TABLE `alpha_child` (`id` int NOT NULL, `parent_id` int NOT NULL,
            PRIMARY KEY (`id`), KEY `p` (`parent_id`),
            CONSTRAINT `fk_alpha` FOREIGN KEY (`parent_id`) REFERENCES `zebra_parent` (`id`)) ENGINE=InnoDB');

        $this->on($source)->table('zebra_parent')->insert(['id' => 1]);
        $this->on($source)->table('alpha_child')->insert(['id' => 1, 'parent_id' => 1]);

        $this->assertSame(0, $this->backup($source));

        $target = $this->scratch('fkdst');
        $this->restore($this->written()[0], $target);

        $this->assertSame(1, $this->on($target)->table('alpha_child')->count());
    }

    // ---------------------------------------------------- while the office works

    /**
     * A payment saved while the backup is reading comes back whole, or not at all.
     *
     * The office does not stop for a backup — DEPLOY.md takes one before every
     * deploy, in working hours. Read one table after another, each at its own
     * moment, a payment saved in the second the dump takes landed in the tables
     * read after it and missed the ones read before. party_ledger goes before
     * party_ledger_allocation, so the restore brought back money adjusted
     * against a file with no payment behind it — and with the foreign-key
     * checks off for the restore, nothing said so.
     *
     * Twice: on a server as it comes, and on one whose sessions are set to read
     * committed, where a snapshot is quietly not one unless the transaction
     * asks for repeatable read itself.
     */
    #[DataProvider('isolationLevels')]
    public function test_a_payment_saved_while_the_backup_reads_is_not_restored_in_half(?string $isolation): void
    {
        $source = $this->ledger('tear');

        if ($isolation) {
            $this->on($source)->unprepared('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
        }

        // Somebody at the counter saves today's the moment party_ledger has been read.
        $office = $this->office($source);
        $saved = false;

        DB::listen(function (QueryExecuted $query) use ($source, $office, &$saved) {
            if ($saved || $query->connectionName !== $source || $query->sql !== 'select * from `party_ledger`') {
                return;
            }

            $saved = true;

            $this->payToday($office);
        });

        $this->assertSame(0, $this->backup($source), 'the backup did not succeed');
        $this->assertTrue($saved, 'nothing was saved while the backup was reading');

        $target = $this->scratch('teardst');
        $this->restore($this->written()[0], $target);

        $orphans = $this->on($target)->table('party_ledger_allocation as a')
            ->leftJoin('party_ledger as e', 'e.id', '=', 'a.entry_id')
            ->whereNull('e.id')
            ->count();

        $this->assertSame(0, $orphans, 'an adjustment came back without the payment it belongs to');
        $this->assertSame(
            $this->on($target)->table('party_ledger')->count(),
            $this->on($target)->table('party_ledger_allocation')->count(),
            'the payments and their adjustments came back from different moments'
        );
    }

    public static function isolationLevels(): array
    {
        return [
            'a server as it comes' => [null],
            'a server set to read committed' => ['READ COMMITTED'],
        ];
    }

    /**
     * The snapshot writes nothing, and is closed when the last table is read.
     *
     * A backup has no business changing the ledger, so a write slipped into
     * its snapshot is refused rather than committed with it. And once the
     * dump is done the connection is left as it was found — not still inside
     * a transaction, holding every table it read.
     */
    public function test_the_snapshot_writes_nothing_and_is_closed_when_done(): void
    {
        $source = $this->scratch('readonly');
        $this->on($source)->unprepared('CREATE TABLE `t` (`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
        $this->on($source)->table('t')->insert(['id' => 1]);

        $refused = null;

        DB::listen(function (QueryExecuted $query) use ($source, &$refused) {
            if ($refused !== null || $query->connectionName !== $source || $query->sql !== 'select * from `t`') {
                return;
            }

            try {
                $this->on($source)->table('t')->insert(['id' => 2]);
                $refused = false;
            } catch (QueryException) {
                $refused = true;
            }
        });

        $this->assertSame(0, $this->backup($source), 'the backup did not succeed');
        $this->assertTrue($refused, 'a write inside the backup\'s snapshot was allowed');

        // Closed: the same connection can write again, and another session sees it.
        $this->on($source)->table('t')->insert(['id' => 3]);

        $this->assertEquals([1, 3], $this->office($source)->table('t')->orderBy('id')->pluck('id')->all());
    }

    /** And a backup that fails half way lets go of its snapshot on the way out. */
    public function test_a_backup_that_fails_half_way_lets_go_of_its_snapshot(): void
    {
        $source = $this->scratch('failing');
        $this->on($source)->unprepared('CREATE TABLE `t` (`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');

        // The disk fills, say, just as the table has been read.
        DB::listen(function (QueryExecuted $query) use ($source) {
            if ($query->connectionName === $source && $query->sql === 'select * from `t`') {
                throw new \RuntimeException('Cannot write to the backup file — is the disk full?');
            }
        });

        $this->assertNotSame(0, $this->backup($source), 'a failed backup reported success');
        $this->assertSame([], $this->written(), 'a failed backup left a file that looks like one');

        $this->on($source)->table('t')->insert(['id' => 1]);

        $this->assertSame(1, $this->office($source)->table('t')->count(), 'the failed backup\'s snapshot is still open');
    }

    /**
     * A connection that drops part way fails the backup.
     *
     * When a query finds its connection gone, Laravel opens a new one and runs
     * the query again there, without a word. The snapshot went with the old
     * session, so every table after that was read at a later moment than the
     * ones before it — the half-a-payment backup all over again, and reported
     * as a success.
     */
    public function test_a_connection_that_drops_part_way_fails_the_backup(): void
    {
        $source = $this->ledger('dropped');
        $session = $this->on($source)->selectOne('SELECT CONNECTION_ID() AS id')->id;

        $office = $this->office($source);
        $dropped = false;

        DB::listen(function (QueryExecuted $query) use ($source, $office, $session, &$dropped) {
            if ($dropped || $query->connectionName !== $source || $query->sql !== 'select * from `party_ledger`') {
                return;
            }

            $dropped = true;

            // The server drops the backup's session — a restart, a host's time
            // limit — and today's payment is saved while it is gone.
            $office->unprepared('KILL '.(int) $session);

            $this->payToday($office);
        });

        $this->artisan('db:backup', ['--connection' => $source, '--path' => $this->folder])
            ->expectsOutputToContain('dropped part way')
            ->assertFailed()
            ->run();

        $this->assertTrue($dropped, 'the connection was never dropped');
        $this->assertSame([], $this->written(), 'a backup read across two sessions was kept');
    }

    /**
     * Not from inside a transaction somebody else opened.
     *
     * Starting a snapshot ends whatever transaction the connection is already
     * in — MySQL commits it, without asking. A backup run from inside one, a
     * test wrapped in DatabaseTransactions say, would have committed its
     * caller's half-done work. It refuses instead, saying why in words, and
     * leaves that transaction exactly as it was, still the caller's to keep or
     * to roll back.
     */
    public function test_it_will_not_end_a_transaction_it_did_not_open(): void
    {
        $source = $this->scratch('open');
        $this->on($source)->unprepared('CREATE TABLE `t` (`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');

        $this->on($source)->beginTransaction();
        $this->on($source)->table('t')->insert(['id' => 1]);

        $this->artisan('db:backup', ['--connection' => $source, '--path' => $this->folder])
            ->expectsOutputToContain('Cannot back up from inside an open transaction')
            ->assertFailed()
            ->run();

        $this->assertSame([], $this->written(), 'a refused backup left a file that looks like one');

        $this->assertSame(1, $this->on($source)->table('t')->count(), 'the caller\'s pending row was rolled back');
        $this->assertSame(0, $this->office($source)->table('t')->count(), 'the caller\'s pending row was committed');

        $this->on($source)->rollBack();

        $this->assertSame(0, $this->on($source)->table('t')->count());
    }

    // ------------------------------------------------------------ the real ledger

    /** The application's own database, every table of it. */
    public function test_the_ledger_is_backed_up_whole(): void
    {
        $this->assertSame(0, $this->artisan('db:backup', ['--path' => $this->folder])->run());

        $sql = (string) gzdecode((string) file_get_contents($this->written()[0]));

        foreach (['work_file', 'work_file_item', 'party', 'party_ledger', 'work_file_status_log', 'users'] as $table) {
            $this->assertStringContainsString('CREATE TABLE `'.$table.'`', $sql, "$table is not in the backup");
        }

        /*
         * Whether the rows travel is a question only a database with rows can
         * answer, and this test cannot make any: it runs without
         * DatabaseTransactions on purpose — see the note at the top of the class
         * — so a fixture written here would be committed into the live ledger.
         *
         * The structure above is checked either way. On a fresh checkout or a
         * build server there is simply nothing to carry, and saying so is more
         * honest than failing as though the backup were broken.
         */
        if (DB::table('work_file')->count() === 0) {
            $this->markTestSkipped('this database holds no work files to back up');
        }

        $this->assertStringContainsString('INSERT INTO `work_file` (', $sql, 'the files were not written');
    }

    // ------------------------------------------------------------------ the folder

    public function test_only_a_finished_backup_gets_the_name(): void
    {
        $source = $this->scratch('name');
        $this->on($source)->unprepared('CREATE TABLE `t` (`id` int NOT NULL)');

        $this->backup($source);

        $this->assertCount(1, $this->written());
        $this->assertSame([], glob($this->folder.DIRECTORY_SEPARATOR.'*.writing') ?: [],
            'a half-written file was left behind');
        $this->assertStringStartsWith($source.'-', basename($this->written()[0]));
    }

    public function test_it_keeps_the_newest_few_and_clears_the_rest(): void
    {
        $source = $this->scratch('prune');
        $this->on($source)->unprepared('CREATE TABLE `t` (`id` int NOT NULL)');

        // Nights gone by. The command names files by the minute, so they are
        // written by hand here rather than by waiting.
        foreach (['2026-09-10-0200', '2026-09-11-0200', '2026-09-12-0200', '2026-09-13-0200'] as $when) {
            file_put_contents($this->folder.DIRECTORY_SEPARATOR.$source.'-'.$when.'.sql.gz', 'older');
        }

        // Somebody's own copy, taken before a risky release.
        $byHand = $this->folder.DIRECTORY_SEPARATOR.'before-the-september-release.sql.gz';
        file_put_contents($byHand, 'kept');

        $this->backup($source, ['--keep' => 3]);

        $names = array_map('basename', $this->written());

        $this->assertCount(4, $names, 'the wrong number of files was kept');
        $this->assertContains(basename($byHand), $names, 'a backup this command did not write was deleted');
        $this->assertContains($source.'-2026-09-13-0200.sql.gz', $names, 'the newest of the old ones went');
        $this->assertNotContains($source.'-2026-09-10-0200.sql.gz', $names, 'the oldest was kept');
    }

    /**
     * A copy named by hand after the database — "<db>-before-the-release" —
     * starts like a backup of it. Matched by that prefix it sorted above every
     * dated one, letters coming after digits, so it was kept as the newest and
     * a real night's backup was deleted in its place.
     */
    public function test_a_copy_named_after_the_database_does_not_push_out_a_real_backup(): void
    {
        $source = $this->scratch('byname');
        $this->on($source)->unprepared('CREATE TABLE `t` (`id` int NOT NULL)');

        foreach (['2026-09-11-0200', '2026-09-12-0200', '2026-09-13-0200'] as $when) {
            file_put_contents($this->folder.DIRECTORY_SEPARATOR.$source.'-'.$when.'.sql.gz', 'older');
        }

        $byHand = $this->folder.DIRECTORY_SEPARATOR.$source.'-before-the-release.sql.gz';
        file_put_contents($byHand, 'kept');

        $this->backup($source, ['--keep' => 2]);

        $names = array_map('basename', $this->written());

        $this->assertContains(basename($byHand), $names, 'a copy this command did not write was deleted');
        $this->assertContains($source.'-2026-09-13-0200.sql.gz', $names, 'a real backup went and the copy was counted in its place');
        $this->assertNotContains($source.'-2026-09-12-0200.sql.gz', $names);
        $this->assertCount(3, $names, 'tonight\'s, the newest of the old ones, and the copy');
    }

    /**
     * A run killed part way leaves its half-written file, which nothing else
     * removes. A run that finishes clears those an hour old or more — and not
     * one that may still be being written alongside it.
     */
    public function test_a_finished_run_clears_what_a_killed_one_left_behind(): void
    {
        $source = $this->scratch('partial');
        $this->on($source)->unprepared('CREATE TABLE `t` (`id` int NOT NULL)');

        $killed = $this->folder.DIRECTORY_SEPARATOR.$source.'-2026-09-20-0130.sql.gz.writing';
        file_put_contents($killed, 'half');
        touch($killed, time() - 7200);

        $running = $this->folder.DIRECTORY_SEPARATOR.$source.'-2026-09-20-0131.sql.gz.writing';
        file_put_contents($running, 'still going');

        $this->assertSame(0, $this->backup($source));

        $this->assertFileDoesNotExist($killed);
        $this->assertFileExists($running);
    }

    public function test_a_database_that_cannot_be_read_leaves_nothing_behind(): void
    {
        config(['database.connections.nowhere' => array_merge(
            config('database.connections.'.config('database.default')),
            ['database' => 'acinfo_does_not_exist_'.uniqid()]
        )]);

        $this->assertNotSame(0, $this->artisan('db:backup', [
            '--connection' => 'nowhere',
            '--path' => $this->folder,
        ])->run(), 'a failed backup reported success');

        $this->assertSame([], $this->written(), 'a failed backup left a file that looks like one');
    }
}
