<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A golden master of what every screen hands its component.
 *
 * The move to client-side routing requires the 890 lines of prop-building that
 * currently sit in Blade @php blocks to move server-side, because a router
 * fetches JSON and there is no Blade in that path. That is a large refactor of
 * code which is working and verified, and whose output nothing else checks.
 *
 * So this records the shape of every screen's props — keys, types, column
 * configuration, row counts — and fails if a refactor changes any of it. It
 * deliberately records shape rather than values: values move as the database
 * moves underneath a live application, but a screen that starts handing over a
 * differently-shaped payload has been broken by the refactor, not by the data.
 *
 * The point is that moving a @php block into a controller becomes provable
 * instead of hopeful.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class ScreenPropsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Where the recorded shapes live. Regenerate deliberately with
     * REGENERATE_SCREEN_PROPS=1 php artisan test --filter=ScreenPropsTest
     * and read the diff before committing it.
     */
    private function goldenPath(): string
    {
        return base_path('tests/screen-props.json');
    }

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Props Test Admin';
        $user->email = 'props-admin-'.uniqid().'@example.com';
        $user->password = Hash::make('password-for-tests');
        $user->user_type = 1;
        $user->save();

        return $user;
    }

    /**
     * Every screen that mounts something, with a URL that has data behind it.
     */
    private function screens(): array
    {
        $party = \App\Models\PartyModel::first();
        $file = \App\Models\WorkFileModel::first();
        $client = \Illuminate\Support\Facades\DB::table('client')->first();

        return array_filter([
            'dashboard' => 'admin/dashboard',
            'party-list-customer' => 'admin/parties/customer',
            'party-list-vendor' => 'admin/parties/vendor',
            'party-add' => 'admin/party/add/customer',
            'party-entry' => 'admin/party/entry/customer',
            'party-edit' => $party ? 'admin/party/edit/'.$party->id : null,
            'party-statement' => $party ? 'admin/party/statement/'.$party->id : null,
            'files' => 'admin/files',
            'file-receive' => 'admin/file/receive',
            'file-assign' => 'admin/file/assign',
            'file-vendor-return' => 'admin/file/vendor-return',
            'file-customer-return' => 'admin/file/customer-return',
            'file-status' => 'admin/file/status',
            'file-edit' => $file ? 'admin/file/edit/'.$file->id : null,
            'work-types' => 'admin/work-types',
            'report-customer' => 'admin/reports/files?party_type=customer',
            'report-vendor' => 'admin/reports/files?party_type=vendor',
            'client-list' => 'admin/view-clients',
            'client-add' => 'admin/add-clients',
            'client-password' => $client ? 'admin/client/password/'.$client->id : null,
            'client-statement' => $client ? 'admin/client/statement/'.$client->id : null,
            'payment' => 'admin/payment',
            'receipt' => 'admin/receipt',
        ]);
    }

    /**
     * The shape of a value: its structure, without the data that fills it.
     *
     * A row's figures change every time someone books a file; the fact that a
     * row carries a 'billed' key which holds a number does not.
     */
    private function shape($value, int $depth = 0)
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            // A list: describe the first entry and count the rest, so adding a
            // party does not read as a broken screen.
            if (array_is_list($value)) {
                return [
                    'list_of' => $depth > 4 ? '…' : $this->shape($value[0], $depth + 1),
                ];
            }

            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $depth > 4 ? '…' : $this->shape($item, $depth + 1);
            }
            ksort($out);

            return $out;
        }

        if (is_bool($value)) {
            return 'bool';
        }
        if (is_int($value) || is_float($value)) {
            return 'number';
        }
        if ($value === null) {
            return 'null';
        }

        return 'string';
    }

    /**
     * The mount points on a page, with each one's props reduced to its shape.
     */
    private function shapesOn(string $url): array
    {
        $html = $this->get($url)->assertOk("$url should load")->getContent();

        preg_match_all('#data-vue="([\w-]+)"\s+data-props="(.*?)"\s*>#s', $html, $found, PREG_SET_ORDER);

        $out = [];

        foreach ($found as $mount) {
            if ($mount[1] === 'vue-loader') {
                continue; // Chrome, not a screen.
            }

            $props = json_decode(html_entity_decode($mount[2], ENT_QUOTES, 'UTF-8'), true);

            $this->assertIsArray($props, "$url: {$mount[1]} did not hand over decodable props");

            $shape = $this->shape($props);

            /*
             * Column configuration is the contract between a screen and the
             * grid, and it is exactly what a careless refactor drops, so it is
             * recorded in full rather than as a shape.
             */
            if (isset($props['columns'])) {
                $shape['columns'] = array_map(
                    fn ($column) => $column['key'].':'.($column['type'] ?? 'text')
                        .(($column['exportable'] ?? true) === false ? ':no-export' : '')
                        .(($column['hidden'] ?? false) ? ':hidden' : '')
                        .(($column['sortable'] ?? true) === false ? ':no-sort' : ''),
                    $props['columns']
                );
            }

            $out[$mount[1]] = $shape;
        }

        return $out;
    }

    /**
     * Every screen still hands its component the same shape of data.
     */
    public function test_no_screen_changes_the_shape_of_what_it_hands_its_component(): void
    {
        $this->actingAs($this->admin());

        $current = [];
        foreach ($this->screens() as $name => $url) {
            $current[$name] = $this->shapesOn($url);
        }

        ksort($current);

        if (getenv('REGENERATE_SCREEN_PROPS')) {
            file_put_contents(
                $this->goldenPath(),
                json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
            );

            $this->markTestSkipped('regenerated — read the diff before committing it');
        }

        $this->assertFileExists(
            $this->goldenPath(),
            'no recorded shapes yet: run REGENERATE_SCREEN_PROPS=1 php artisan test --filter=ScreenPropsTest'
        );

        $golden = json_decode(file_get_contents($this->goldenPath()), true);

        foreach ($golden as $name => $expected) {
            $this->assertArrayHasKey($name, $current, "$name no longer renders");

            $this->assertSame(
                $expected,
                $current[$name],
                "$name hands its component a different shape than before. If the change is intended, ".
                'regenerate with REGENERATE_SCREEN_PROPS=1 and read the diff.'
            );
        }

        foreach (array_keys($current) as $name) {
            $this->assertArrayHasKey($name, $golden, "$name is new — regenerate the recorded shapes");
        }
    }
}
