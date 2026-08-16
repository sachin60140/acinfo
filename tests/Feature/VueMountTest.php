<?php

namespace Tests\Feature;

use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The seam between Blade and Vue.
 *
 * Every converted screen hands the browser a component name and a blob of JSON.
 * Nothing checks that the two agree: misspell a prop, forget one the component
 * declares required, or emit JSON that does not parse, and the page still
 * returns 200 with a perfectly empty space where the table should be. There is
 * no server error and no test failure — the only symptom is a blank screen
 * someone has to notice.
 *
 * These tests read what the pages actually render and what the components
 * actually declare, and check they line up. They are deliberately generic: a
 * screen converted next week is covered the day it is converted, without anyone
 * remembering to add a test for it.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class VueMountTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Mount Test Admin';
        $user->email = 'mount-admin-'.uniqid().'@example.com';
        $user->password = Hash::make('password-for-tests');
        $user->user_type = 1;
        $user->save();

        return $user;
    }

    /**
     * Which component each mount key resolves to, read from the registration map
     * rather than assumed, so a component registered under a different key is
     * caught rather than silently skipped.
     *
     * @return array<string, string> mount key => component file
     */
    private function registry(): array
    {
        // The map lives beside the mounting rather than in app.js, because
        // navigation needs it too when it starts a swapped-in screen.
        $js = file_get_contents(resource_path('js/mounts.js'));

        // import StatusBoard from './components/StatusBoard.vue';
        preg_match_all("#import\s+(\w+)\s+from\s+'\./components/([\w.]+\.vue)'#", $js, $imports, PREG_SET_ORDER);

        $files = [];
        foreach ($imports as $import) {
            $files[$import[1]] = $import[2];
        }

        // 'vue-status-board': StatusBoard,
        preg_match_all("#'([\w-]+)'\s*:\s*(\w+)\s*,#", $js, $pairs, PREG_SET_ORDER);

        $registry = [];
        foreach ($pairs as $pair) {
            if (isset($files[$pair[2]])) {
                $registry[$pair[1]] = $files[$pair[2]];
            }
        }

        return $registry;
    }

    /**
     * The props a component declares: name => required.
     *
     * @return array<string, bool>
     */
    private function declaredProps(string $componentFile): array
    {
        $source = file_get_contents(resource_path('js/components/'.$componentFile));

        if (! preg_match('/defineProps\(\{(.*?)\n\}\)/s', $source, $block)) {
            return [];
        }

        preg_match_all('/^\s{4}(\w+):\s*\{(.*)$/m', $block[1], $props, PREG_SET_ORDER);

        $declared = [];
        foreach ($props as $prop) {
            $declared[$prop[1]] = str_contains($prop[2], 'required: true');
        }

        return $declared;
    }

    /**
     * Every mount point on a page, with its props already decoded.
     *
     * @return array<int, array{key: string, props: array}>
     */
    private function mountsOn(string $url): array
    {
        $html = $this->get($url)->assertOk("$url should load")->getContent();

        preg_match_all('#data-vue="([\w-]+)"\s+data-props="(.*?)"\s*>#s', $html, $found, PREG_SET_ORDER);

        $mounts = [];
        foreach ($found as $mount) {
            $json = html_entity_decode($mount[2], ENT_QUOTES, 'UTF-8');
            $props = json_decode($json, true);

            $this->assertSame(
                JSON_ERROR_NONE,
                json_last_error(),
                "$url: data-props for {$mount[1]} is not valid JSON (".json_last_error_msg().'). '
                ."Vue receives nothing and the screen renders blank. First 300 chars: \n".substr($json, 0, 300)
            );

            $mounts[] = ['key' => $mount[1], 'props' => $props];
        }

        return $mounts;
    }

    /**
     * Every admin screen, so a newly converted one is covered without anyone
     * adding it here.
     */
    private function screens(): array
    {
        $this->actingAs($this->admin());

        $party = new PartyModel;
        $party->party_type = 'customer';
        $party->name = 'Mount Test Customer';
        $party->mobile = '9000000901';
        $party->is_active = 1;
        $party->save();

        $entry = new PartyLedgerModel;
        $entry->party_id = $party->id;
        $entry->txn_date = now()->toDateString();
        $entry->entry_type = 'debit';
        $entry->amount = 1200;
        $entry->particular = 'Mount test entry';
        $entry->save();

        return [
            'admin/dashboard',
            'admin/parties/customer',
            'admin/parties/vendor',
            'admin/party/add/customer',
            'admin/party/entry/customer',
            'admin/party/edit/'.$party->id,
            'admin/party/statement/'.$party->id,
            'admin/files',
            'admin/file/receive',
            'admin/file/assign',
            'admin/file/vendor-return',
            'admin/file/customer-return',
            'admin/file/status',
            'admin/work-types',
            'admin/reports/files',
            'admin/view-clients',
        ];
    }

    /**
     * A mount key with no component behind it renders an empty div. The page
     * looks like it loaded and the feature is simply absent.
     */
    public function test_every_mount_point_resolves_to_a_registered_component(): void
    {
        $registry = $this->registry();
        $this->assertNotEmpty($registry, 'no components are registered in app.js');

        $seen = [];

        foreach ($this->screens() as $screen) {
            foreach ($this->mountsOn($screen) as $mount) {
                $seen[] = $mount['key'];

                $this->assertArrayHasKey(
                    $mount['key'],
                    $registry,
                    "$screen mounts \"{$mount['key']}\" but nothing is registered under that key in app.js, ".
                    'so the browser renders an empty element and the screen silently loses this section.'
                );
            }
        }

        $this->assertNotEmpty($seen, 'no Vue mount points found on any screen — the parser is probably wrong');
    }

    /**
     * A required prop that never arrives leaves Vue with undefined where it
     * expected a URL or a token — the form posts nowhere, or fails CSRF.
     */
    public function test_every_mount_point_passes_the_props_its_component_requires(): void
    {
        $registry = $this->registry();

        foreach ($this->screens() as $screen) {
            foreach ($this->mountsOn($screen) as $mount) {
                if (! isset($registry[$mount['key']])) {
                    continue; // Reported by the test above.
                }

                $declared = $this->declaredProps($registry[$mount['key']]);
                $passed = array_keys($mount['props'] ?? []);

                foreach ($declared as $prop => $required) {
                    if ($required) {
                        $this->assertContains(
                            $prop,
                            $passed,
                            "$screen: {$mount['key']} declares \"$prop\" as required but the mount point does not pass it."
                        );
                    }
                }

                foreach ($passed as $prop) {
                    $this->assertArrayHasKey(
                        $prop,
                        $declared,
                        "$screen: {$mount['key']} is passed \"$prop\", which the component does not declare. ".
                        'Vue drops it, so whatever it was for does not happen.'
                    );
                }
            }
        }
    }

    /**
     * A form component renders its own hidden token, because a component mounted
     * onto a bare element has no server markup to slot one in. Miss it and every
     * submission from that screen is rejected as a CSRF failure.
     */
    public function test_form_components_receive_a_csrf_token_and_an_action(): void
    {
        $registry = $this->registry();

        foreach ($this->screens() as $screen) {
            foreach ($this->mountsOn($screen) as $mount) {
                if (! isset($registry[$mount['key']])) {
                    continue;
                }

                $declared = $this->declaredProps($registry[$mount['key']]);

                if (! array_key_exists('csrf', $declared)) {
                    continue; // Not a form.
                }

                $props = $mount['props'] ?? [];

                $this->assertNotEmpty($props['csrf'] ?? '', "$screen: {$mount['key']} got an empty CSRF token.");
                $this->assertNotEmpty($props['action'] ?? '', "$screen: {$mount['key']} got no action to post to.");
            }
        }
    }

    /**
     * The whole point of moving off DataTables was that its Buttons stack pulled
     * about a megabyte of jQuery, JSZip and pdfmake into every page showing a
     * table. If a converted screen still carries those tags the cost is back and
     * a stale initialiser may run against markup Vue now owns.
     */
    public function test_converted_screens_no_longer_load_the_datatables_stack(): void
    {
        foreach ($this->screens() as $screen) {
            $html = $this->get($screen)->assertOk()->getContent();

            if (! str_contains($html, 'data-vue="vue-')) {
                continue; // Not converted yet.
            }

            // A screen may mount a small component and still be a Blade table,
            // so only complain when the grid itself is on the page.
            if (! str_contains($html, 'data-vue="vue-') || ! $this->gridIsMounted($html)) {
                continue;
            }

            foreach (['dataTables.buttons', 'jszip', 'pdfmake', 'jquery.dataTables'] as $library) {
                $this->assertStringNotContainsString(
                    $library,
                    $html,
                    "$screen renders through DataGrid but still loads $library."
                );
            }

            $this->assertStringNotContainsString('.DataTable(', $html, "$screen still initialises DataTables.");
        }
    }

    private function gridIsMounted(string $html): bool
    {
        $registry = $this->registry();

        foreach ($registry as $key => $component) {
            if ($component === 'DataGrid.vue' && str_contains($html, 'data-vue="'.$key.'"')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The counterpart to the Blade check in DashboardTest.
     *
     * A component that renders a table has to turn it into a stack of cards on a
     * phone. Without it the table grows a sideways scrollbar and the right-hand
     * columns — which on this app are the money — sit off screen where nobody
     * scrolls to find them.
     *
     * The rule lives in the component's own stylesheet, so this reads the
     * stylesheet rather than the page: the CSS is in the bundle, not the HTML,
     * and a rendered page cannot show whether it survived.
     */
    public function test_table_components_stack_into_cards_on_narrow_screens(): void
    {
        $components = array_unique(array_values($this->registry()));

        $checked = 0;

        foreach ($components as $component) {
            $source = file_get_contents(resource_path('js/components/'.$component));

            // Only components that actually draw a table.
            if (! str_contains($source, '<table')) {
                continue;
            }

            $checked++;

            $this->assertMatchesRegularExpression(
                '/@media[^{]*max-width[^{]*\)\s*\{/',
                $source,
                "$component renders a table but has no narrow-screen rule."
            );

            $this->assertStringContainsString(
                'data-label',
                $source,
                "$component stacks on mobile but its cells carry no data-label, ".
                'so each value appears with no heading to say what it is.'
            );
        }

        $this->assertGreaterThan(0, $checked, 'no table components found — the check is not running');
    }

    /**
     * A screen must not be emptied by one bad byte in the data.
     *
     * json_encode() returns false rather than throwing on invalid UTF-8, and
     * Blade prints false as nothing — so the mount point gets an empty
     * data-props, the component is handed {}, and the table vanishes from a page
     * that still returns 200 with its header and totals intact.
     *
     * MySQL will not accept such a byte into these tables, so this is defence
     * rather than a reproduction: the columns are utf8mb4 and strict mode
     * rejects the insert. It stays because the cost is one function call and the
     * failure it prevents is both total and silent — and because data reaches
     * these screens from older tables and from imports that did not come through
     * Eloquent.
     */
    public function test_the_encoder_survives_a_byte_that_would_blank_a_screen(): void
    {
        // A lone continuation byte: valid in latin-1, not valid UTF-8.
        $row = ['name' => 'Rajesh '.chr(0xC3).' Kumar', 'balance' => 1500.0];

        $this->assertFalse(json_encode($row), 'the byte really does break json_encode');

        $encoded = \App\Support\VueProps::encode($row);
        $decoded = json_decode($encoded, true);

        $this->assertIsArray($decoded, 'the encoder must still produce usable JSON');
        $this->assertEquals(1500.0, $decoded['balance'], 'and the rest of the row must survive intact');
    }

    /**
     * Every view hands its props over through the encoder that survives that.
     */
    public function test_views_encode_props_through_the_safe_encoder(): void
    {
        foreach ((new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        )) as $file) {
            if ($file->isDir() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            $this->assertStringNotContainsString(
                'data-props="{{ json_encode(',
                $source,
                $file->getFilename().' encodes its props with plain json_encode, which returns false on a '
                .'bad byte and blanks the screen. Use \App\Support\VueProps::encode().'
            );
        }
    }

    /**
     * The date fields, which Vue must not re-render.
     *
     * The check itself is in resources/js/datefield-guard.mjs, because proving
     * it needs the Vue compiler: it reads the compiled render function and looks
     * for the field inside a cached subtree, rather than grepping for the words
     * "v-once" and hoping they are in the right place. The comment at the top of
     * that file explains what goes wrong without it — a date silently replaced
     * by the page-load default, saved without complaint.
     */
    public function test_no_component_re_renders_a_date_field(): void
    {
        $guard = resource_path('js/datefield-guard.mjs');
        $this->assertFileExists($guard);

        exec('node '.escapeshellarg($guard).' 2>&1', $output, $status);

        if ($status !== 0 && str_contains(implode("\n", $output), 'Cannot find package')) {
            $this->markTestSkipped('node modules are not installed');
        }

        $this->assertSame(0, $status, implode("\n", $output));
    }

    /**
     * Navigation without page loads is off unless the server turns it on.
     *
     * The built assets ship in the repository and deploy by git pull, so turning
     * it off in a hurry has to be one env change and a cache clear rather than a
     * rebuild. That only works if the flag genuinely reaches the page, and if it
     * defaults to off — a feature that arrives switched on by default is not a
     * feature that can be switched off.
     */
    public function test_swapped_navigation_is_off_by_default_and_reaches_the_page(): void
    {
        $this->actingAs($this->admin());

        config(['app.swap_navigation' => false]);
        $this->assertStringContainsString(
            'data-swap-nav="0"',
            $this->get('admin/dashboard')->assertOk()->getContent(),
            'the flag must reach the page, or it cannot be turned off from the server'
        );

        config(['app.swap_navigation' => true]);
        $this->assertStringContainsString(
            'data-swap-nav="1"',
            $this->get('admin/dashboard')->assertOk()->getContent()
        );
    }

    /**
     * Swapping a screen in replaces two regions, and needs both to exist on
     * every layout it might land on — otherwise it gives up and does a normal
     * page load, which works but silently loses the point.
     */
    public function test_both_layouts_carry_the_regions_navigation_swaps(): void
    {
        foreach (['admin', 'user'] as $area) {
            $layout = file_get_contents(resource_path("views/$area/layouts/app.blade.php"));

            $this->assertStringContainsString('id="main"', $layout, "$area layout has no main region");
        }

        foreach (['admin', 'user'] as $area) {
            $sidebar = file_get_contents(resource_path("views/$area/layouts/_sidebar.blade.php"));

            // The sidebar is swapped too because it carries the active-menu
            // state, which the server decides per page.
            $this->assertStringContainsString('class="sidebar"', $sidebar, "$area sidebar is not selectable");
        }
    }

    /**
     * The bundle is only on the page if the layout asks for it. Without the Vite
     * tags every converted screen is blank, everywhere, at once.
     */
    public function test_the_layout_loads_the_built_bundle(): void
    {
        $this->actingAs($this->admin());

        $html = $this->get('admin/dashboard')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#<script[^>]+src="[^"]*/build/assets/app-[\w-]+\.js"#',
            $html,
            'the dashboard does not load the built Vue bundle'
        );
    }
}
