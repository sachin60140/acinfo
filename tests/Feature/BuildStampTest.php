<?php

namespace Tests\Feature;

use App\Models\PartyModel;
use App\Models\User;
use App\Support\Build;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The build stamp in the footer.
 *
 * It exists to answer one question after a deploy — did that land? — so the
 * only property worth testing is that it moves when the code does. A stamp that
 * is always there and always says the same thing is worse than none: it gets
 * believed.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class BuildStampTest extends TestCase
{
    use DatabaseTransactions;

    /** A throwaway project tree, so nothing here stats the real one. */
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        Build::forget();

        $this->tmp = sys_get_temp_dir().'/build-'.uniqid();
        mkdir($this->tmp.'/.git', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tmp);

        Build::forget();

        parent::tearDown();
    }

    private function rmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir.'/'.$entry;

            is_dir($path) ? $this->rmdir($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private function write(string $relative, string $contents, ?int $mtime = null): string
    {
        $path = $this->tmp.'/'.$relative;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $contents);

        if ($mtime !== null) {
            touch($path, $mtime);
        }

        return $path;
    }

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Build Admin';
        $user->email = 'build-'.uniqid().'@example.com';
        $user->password = Hash::make('password-for-tests');
        $user->user_type = 1;
        $user->save();

        return $user;
    }

    // ------------------------------------------------------------ the version

    public function test_the_version_comes_from_the_version_file(): void
    {
        $this->assertSame('1.1.1', Build::readVersion($this->write('VERSION', "1.1.1\n")));
    }

    public function test_a_v_prefix_in_the_file_is_not_printed_twice(): void
    {
        // The label adds its own "v", so a file that carries one must not
        // produce "vv1.1.1".
        $this->assertSame('2.0.3', Build::readVersion($this->write('VERSION', "v2.0.3\n")));
    }

    public function test_a_missing_or_unreadable_version_says_so_rather_than_guessing(): void
    {
        $this->assertSame('0.0.0', Build::readVersion($this->tmp.'/nothing-here'));
        $this->assertSame('0.0.0', Build::readVersion($this->write('VERSION', "not a version\n")));
    }

    // -------------------------------------------------------------- the stamp

    /**
     * The whole point. A pull rewrites the file the branch is kept in, so its
     * modified time is the moment this code arrived — and the stamp has to
     * follow it rather than sit where it was first written.
     */
    public function test_the_stamp_follows_the_branch_git_is_on(): void
    {
        $this->write('.git/HEAD', "ref: refs/heads/main\n", 1_600_000_000);
        $this->write('.git/refs/heads/main', str_repeat('a', 40)."\n", 1_700_000_000);

        $this->assertSame(1_700_000_000, Build::newestMtime($this->tmp));

        // What a pull does: the same file, rewritten later.
        touch($this->tmp.'/.git/refs/heads/main', 1_800_000_000);

        $this->assertSame(
            1_800_000_000,
            Build::newestMtime($this->tmp),
            'the stamp did not move when the branch did'
        );
    }

    /**
     * git folds loose refs into one file when it tidies up, and a checkout that
     * has been gc'd has no refs/heads/main to stat at all.
     */
    public function test_a_repository_with_packed_refs_still_has_a_stamp(): void
    {
        $this->write('.git/HEAD', "ref: refs/heads/main\n", 1_600_000_000);
        $this->write('.git/packed-refs', str_repeat('b', 40)." refs/heads/main\n", 1_750_000_000);

        $this->assertSame(1_750_000_000, Build::newestMtime($this->tmp));
    }

    public function test_a_detached_head_still_has_a_stamp(): void
    {
        // No "ref:" line — HEAD names a commit directly.
        $this->write('.git/HEAD', str_repeat('c', 40)."\n", 1_720_000_000);

        $this->assertSame(1_720_000_000, Build::newestMtime($this->tmp));
    }

    /**
     * A copy deployed without .git — uploaded rather than pulled — still has to
     * say something, and the asset manifest moves on every front-end build.
     */
    public function test_a_tree_with_no_git_falls_back_to_what_it_has(): void
    {
        $this->write('public/build/manifest.json', '{}', 1_690_000_000);
        $this->write('composer.lock', '{}', 1_680_000_000);

        $this->assertSame(1_690_000_000, Build::newestMtime($this->tmp));
    }

    public function test_a_tree_with_nothing_to_go_on_says_nothing(): void
    {
        $this->assertNull(Build::newestMtime($this->tmp), 'no invented timestamp');
    }

    /**
     * The newest wins, whichever of the candidates it happens to be.
     *
     * The newest is deliberately the branch ref, which is looked at last. With
     * the manifest newest instead — the first candidate checked — a version
     * that simply kept the first file it found would pass this and still be
     * frozen, which is what the first draft of this test did.
     */
    public function test_the_newest_of_the_candidates_is_the_one_used(): void
    {
        $this->write('public/build/manifest.json', '{}', 1_600_000_000);
        $this->write('.git/HEAD', "ref: refs/heads/main\n", 1_610_000_000);
        $this->write('.git/refs/heads/main', str_repeat('a', 40)."\n", 1_900_000_000);

        $this->assertSame(1_900_000_000, Build::newestMtime($this->tmp));
    }

    /**
     * HEAD is read off disk and turned into a path. It names a file inside the
     * git directory, so it may only ever look like one.
     */
    public function test_a_head_that_points_outside_the_repository_is_ignored(): void
    {
        /*
         * The escape has to land on a file that is really there, or the test
         * passes because the path happens not to exist rather than because it
         * was refused — which is what the first draft of this did, pointing at
         * /etc/passwd on a machine that has no /etc.
         */
        $this->write('outside.txt', 'not part of the repository', 1_999_999_000);

        $this->write('.git/HEAD', "ref: refs/../../outside.txt\n", 1_600_000_000);

        $this->assertSame(
            1_999_999_000,
            @filemtime($this->tmp.'/.git/refs/../../outside.txt'),
            'the traversal really does reach that file'
        );

        // And it is still HEAD's own mtime that is used.
        $this->assertSame(1_600_000_000, Build::newestMtime($this->tmp));
    }

    // -------------------------------------------------------------- the label

    public function test_the_label_reads_the_way_it_is_meant_to(): void
    {
        $this->assertMatchesRegularExpression(
            '/^v\d+(\.\d+)* · \d{8}-\d{4}$/u',
            Build::label(),
            'v1.1.1 · 20260907-2121'
        );
    }

    public function test_the_stamp_is_written_in_the_timezone_the_app_uses(): void
    {
        /*
         * filemtime returns a Unix timestamp, and PHP's own default timezone is
         * not the application's — formatting it raw put the stamp five and a
         * half hours behind every other time this application prints.
         */
        $this->assertSame(config('app.timezone'), Build::at()->timezone->getName());
    }

    // ------------------------------------------------------------ on the page

    public static function footers(): array
    {
        return [
            'admin' => ['admin/dashboard', 'admin'],
            'client portal' => ['user/dashboard', 'client'],
            'customer portal' => ['customer/dashboard', 'customer'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('footers')]
    public function test_every_signed_in_area_shows_which_build_it_is(string $url, string $as): void
    {
        $request = match ($as) {
            'admin' => $this->actingAs($this->admin()),
            'client' => $this->withSession(['userid' => \App\Models\ClientModel::query()->value('id')]),
            'customer' => $this->withSession(['customer_id' => $this->customer()->id]),
        };

        $body = $request->get($url)->assertOk()->getContent();

        $this->assertStringContainsString(Build::label(), $body, "$as has no build stamp");
    }

    private function customer(): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = 'customer';
        $party->name = 'Build Stamp Customer';
        $party->mobile = '9200000001';
        $party->is_active = 1;
        $party->save();

        return $party;
    }
}
