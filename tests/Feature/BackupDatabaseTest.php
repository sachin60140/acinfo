<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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
