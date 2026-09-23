<?php

namespace Tests\Feature;

use App\Support\Backups;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * When the ledger was last backed up, as the dashboard says it.
 *
 * Read from the names db:backup gives its files, in a folder of the test's own
 * — never the real storage/app/backups. It reads no database, so it needs
 * no transaction.
 */
class BackupStatusTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/backup-status-'.uniqid());
        File::ensureDirectoryExists($this->dir);

        $this->travelTo(now()->setDate(2026, 9, 23)->setTime(9, 0));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function backup(string $stamp, string $database = 'acinfo', string $suffix = ''): string
    {
        $path = $this->dir.DIRECTORY_SEPARATOR.$database.'-'.$stamp.'.sql.gz'.$suffix;
        file_put_contents($path, str_repeat('x', 2048));

        return $path;
    }

    private function latest(): array
    {
        return Backups::latest($this->dir, 'acinfo');
    }

    public function test_no_backup_at_all_is_red(): void
    {
        $latest = $this->latest();

        $this->assertNull($latest['at']);
        $this->assertNull($latest['days']);
        $this->assertSame('bad', $latest['tone']);
    }

    public function test_a_folder_that_is_not_there_is_no_backup_and_no_error(): void
    {
        $latest = Backups::latest($this->dir.DIRECTORY_SEPARATOR.'missing', 'acinfo');

        $this->assertNull($latest['at']);
        $this->assertSame('bad', $latest['tone']);
    }

    public function test_last_nights_backup_is_fine(): void
    {
        $this->backup('2026-09-23-0130');

        $latest = $this->latest();

        $this->assertSame('23-09-2026 01:30', $latest['at']->format('d-m-Y H:i'));
        $this->assertSame(0, $latest['days']);
        $this->assertSame(2048, $latest['bytes']);
        $this->assertSame('ok', $latest['tone']);
    }

    public function test_yesterdays_is_still_fine(): void
    {
        $this->backup('2026-09-22-2300');

        $this->assertSame(1, $this->latest()['days']);
        $this->assertSame('ok', $this->latest()['tone']);
    }

    public function test_two_days_is_amber_and_four_is_red(): void
    {
        $this->backup('2026-09-21-0130');
        $this->assertSame('warn', $this->latest()['tone']);

        $this->travelTo(now()->addDay());
        $this->assertSame('warn', $this->latest()['tone'], 'three days');

        $this->travelTo(now()->addDay());
        $this->assertSame(4, $this->latest()['days']);
        $this->assertSame('bad', $this->latest()['tone']);
    }

    public function test_the_newest_of_several_counts(): void
    {
        $this->backup('2026-09-10-0130');
        $this->backup('2026-09-23-0130');
        $this->backup('2026-09-15-0130');

        $this->assertSame('23-09-2026', $this->latest()['at']->format('d-m-Y'));
    }

    /**
     * A copy named by hand sorts above every dated one — letters come after
     * digits — so matched by prefix it read as the newest backup, and pruning
     * kept it and deleted a real one instead.
     */
    public function test_a_copy_named_by_hand_is_not_a_backup(): void
    {
        $this->backup('2026-09-01-0130');
        $this->backup('before-the-release', 'acinfo');

        $this->assertSame('01-09-2026', $this->latest()['at']->format('d-m-Y'));
        $this->assertSame([$this->dir.DIRECTORY_SEPARATOR.'acinfo-2026-09-01-0130.sql.gz'], Backups::written($this->dir, 'acinfo'));
    }

    public function test_a_half_written_file_or_another_database_is_not_a_backup(): void
    {
        $this->backup('2026-09-23-0130', 'acinfo', '.writing');
        $this->backup('2026-09-23-0130', 'acinfo_old');

        $this->assertNull($this->latest()['at']);
    }

    /**
     * The command and the tile read one folder, so a backup written without
     * --path is one the dashboard can see. Only reads the database.
     */
    public function test_the_command_writes_where_the_tile_looks(): void
    {
        config(['backups.path' => $this->dir]);

        $this->artisan('db:backup')->assertSuccessful();

        $this->assertNotNull(Backups::latest()['at'], 'written somewhere the tile does not look');
    }

    public function test_a_backup_left_half_written_is_noticed_after_an_hour(): void
    {
        $partial = $this->backup('2026-09-23-0130', 'acinfo', '.writing');

        touch($partial, now()->subMinutes(20)->getTimestamp());
        $this->assertFalse($this->latest()['unfinished'], 'may still be running');

        touch($partial, now()->subHours(2)->getTimestamp());
        $this->assertTrue($this->latest()['unfinished']);
    }
}
