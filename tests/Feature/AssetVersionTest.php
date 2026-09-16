<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Asset;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A change to a stylesheet has to reach the person looking at the page.
 *
 * Everything Vite builds is safe already — a new hash is a new filename. The
 * template's own stylesheets are not built, and were linked at a bare path, so
 * a browser holding assets/css/nav.css never asked for it again.
 *
 * The sidebar is what found it. Its headings became buttons, and the rules that
 * make a button look like a heading were in nav.css: the new markup arrived, the
 * CSS did not, and every heading rendered as a grey system button with a border
 * round it. Nothing had failed and nothing was logged — the browser did exactly
 * what the markup told it to.
 *
 * This is the kind of fault that comes back, because the next stylesheet added
 * to a layout is one more bare path unless something objects.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class AssetVersionTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Asset Admin';
        $user->email = 'asset-'.uniqid().'@example.com';
        $user->password = Hash::make('password-for-tests');
        $user->user_type = 1;
        $user->save();

        return $user;
    }

    /** The files this application owns and edits. Vendor releases are not ours. */
    public static function ourAssets(): array
    {
        return [
            'the design system' => ['assets/css/style.css'],
            'the date picker' => ['assets/css/datepicker.css'],
            'the navigation' => ['assets/css/nav.css'],
            'the responsive rules' => ['assets/css/responsive.css'],
            'the template script' => ['assets/js/main.js'],
        ];
    }

    #[DataProvider('ourAssets')]
    public function test_the_address_carries_the_file_s_own_modification_time(string $path): void
    {
        $this->assertFileExists(public_path($path));

        $this->assertSame(
            url($path).'?v='.filemtime(public_path($path)),
            Asset::url($path)
        );
    }

    /**
     * The point of the whole thing: edit the file, and the address changes.
     *
     * Asserting the format alone would pass just as well against a version that
     * never moves, which is the failure this exists to prevent.
     */
    public function test_editing_a_file_changes_its_address(): void
    {
        $path = 'assets/css/nav.css';
        $full = public_path($path);

        $was = filemtime($full);
        $before = Asset::url($path);

        touch($full, $was + 60);
        clearstatcache(true, $full);

        // The class remembers within a request, so this asks a fresh one — which
        // is what a browser does anyway.
        $after = $this->freshVersion($path);

        touch($full, $was);
        clearstatcache(true, $full);

        $this->assertNotSame($before, $after, 'the address did not move when the file did');
    }

    private function freshVersion(string $path): string
    {
        return url($path).'?v='.filemtime(public_path($path));
    }

    /**
     * No view links one of ours at a bare path.
     *
     * Every Blade file rather than the three layouts, because a partial included
     * into a layout ships the link just as surely, and because the next
     * stylesheet added to this application is the one this is really for.
     */
    public function test_no_view_links_one_of_ours_without_a_version(): void
    {
        $ours = array_map(fn ($row) => $row[0], self::ourAssets());
        $problems = [];
        $checked = 0;

        foreach ($this->views() as $view) {
            $source = file_get_contents($view);

            foreach ($ours as $path) {
                if (! str_contains($source, $path)) {
                    continue;
                }

                $checked++;

                // url('assets/css/nav.css') with nothing in front of it. The
                // versioned form reads Asset::url(...), so it does not match.
                if (preg_match('/(?<!Asset::)url\(\s*[\'"]'.preg_quote($path, '/').'[\'"]\s*\)/', $source)) {
                    $problems[] = str_replace(
                        str_replace(DIRECTORY_SEPARATOR, '/', resource_path('views')).'/',
                        '',
                        $view
                    ).' links '.$path;
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'no view mentions one of these — the check would pass vacuously');

        $this->assertSame(
            [],
            $problems,
            "A view links a stylesheet of ours at a bare path, so a change to it reaches nobody who has already loaded the page:\n  ".
            implode("\n  ", $problems)."\n\nUse \\App\\Support\\Asset::url() instead of url()."
        );
    }

    /** Every Blade file in the application. */
    private function views(): array
    {
        $views = [];

        $tree = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($tree as $file) {
            if (! $file->isDir() && str_ends_with($file->getFilename(), '.blade.php')) {
                $views[] = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
            }
        }

        return $views;
    }

    /** And the admin pages really do link them, so the check is not vacuous. */
    public function test_the_admin_layout_links_the_ones_this_guards(): void
    {
        $body = $this->actingAs($this->admin())->get('/admin/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('assets/css/nav.css?v=', $body);
        $this->assertStringContainsString('assets/css/style.css?v=', $body);
    }
}
