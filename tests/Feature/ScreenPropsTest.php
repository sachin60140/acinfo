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
     * Work for the screens that only draw a grid when they have some.
     *
     * Give to Vendor, the two return screens and the board all show an empty
     * state instead of a table when there is nothing to put in one — correctly,
     * and it mounts nothing. This test ran against whatever the database
     * happened to hold and passed for months, until the last unassigned file
     * was given to a vendor: then Give to Vendor had nothing to offer and a
     * working screen was recorded as having lost its component.
     *
     * Rolled back with everything else; see DatabaseTransactions above.
     */
    private function seedWork(): void
    {
        /*
         * Made rather than looked for. This used to take whatever was in the
         * database and return quietly when there was nothing, which on an empty
         * one meant every screen below was compared against no rows at all —
         * a golden file full of shapes that were never drawn.
         */
        $customer = $this->anyParty('customer');
        $vendor = $this->anyParty('vendor');
        $type = $this->anyWorkType();

        /*
         * A customer who is also a vendor, linked, so the Entry screen draws a
         * set-off's other account rather than an empty list — which would
         * record no shape, and pass whatever the rows turned into.
         */
        if (\App\Models\PartyLedgerModel::canSetOff()) {
            $works = new \App\Models\PartyModel;
            $works->party_type = 'vendor';
            $works->name = 'Props Linked Works';
            $works->mobile = '92200'.random_int(10000, 99999);
            $works->is_active = 1;
            $works->save();

            $dealer = new \App\Models\PartyModel;
            $dealer->party_type = 'customer';
            $dealer->name = 'Props Linked Dealer';
            $dealer->mobile = '92300'.random_int(10000, 99999);
            $dealer->is_active = 1;
            $dealer->linked_vendor_id = $works->id;
            $dealer->save();
        }

        /*
         * A customer who owes, from long ago, for the Collection List: the
         * files below post nothing to the ledger, so on an empty database it
         * would record no row's shape at all — and being the oldest debt, it
         * is the row recorded here and on every other database alike.
         */
        $owing = new \App\Models\PartyModel;
        $owing->party_type = 'customer';
        $owing->name = 'Props Owing Customer';
        $owing->mobile = '92400'.random_int(10000, 99999);
        $owing->is_active = 1;
        $owing->save();

        \Illuminate\Support\Facades\DB::table('party_ledger')->insert([
            'party_id' => $owing->id,
            'txn_date' => '2000-01-01',
            'entry_type' => 'debit',
            'amount' => 500,
            'particular' => 'Opening Balance',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * A client too. The client ledger's own screens are in the list below,
         * and a screen with no rows hands its component an empty array rather
         * than a row's worth of shape — which reads as the shape having changed.
         */
        if (! \App\Models\ClientModel::query()->exists()) {
            $client = new \App\Models\ClientModel;
            $client->name = 'Props Client';
            $client->mobile = '92100'.random_int(10000, 99999);
            $client->password = \Illuminate\Support\Facades\Hash::make('password-for-tests');
            $client->address = 'Nowhere in particular';
            $client->save();
        }

        // One still in hand and unassigned, one out with a vendor: between them
        // every screen below has a row to draw.
        foreach ([null, $vendor?->id] as $vendorId) {
            $file = new \App\Models\WorkFileModel;
            $file->file_no = 'F-PROPS-'.uniqid();
            $file->received_date = now()->toDateString();
            $file->registration_no = 'BR01PR'.random_int(1000, 9999);
            $file->description = 'Props fixture';
            $file->work_type_id = $type->id;
            $file->customer_id = $customer->id;
            $file->customer_amount = 1000;
            $file->vendor_id = $vendorId;
            $file->vendor_amount = $vendorId ? 800 : null;
            $file->vendor_date = $vendorId ? now()->toDateString() : null;
            $file->status = $vendorId ? \App\Models\WorkFileModel::DISPATCHED : 'in_office';
            $file->save();

            $item = new \App\Models\WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $file->work_type_id;
            $item->customer_amount = $file->customer_amount;
            $item->vendor_amount = $file->vendor_amount;
            $item->status = $file->status;
            $item->save();
        }

        /*
         * And one that is through, with its papers still here. Hand Over Papers
         * is a screen about finished work nobody has collected; with none of
         * that it draws its empty state and mounts no component at all, which
         * is not the same thing as a screen that stopped working.
         */
        $done = new \App\Models\WorkFileModel;
        $done->file_no = 'F-PROPS-'.uniqid();
        $done->received_date = now()->toDateString();
        $done->registration_no = 'BR01PR'.random_int(1000, 9999);
        $done->description = 'Props fixture, approved';
        $done->work_type_id = $type->id;
        $done->customer_id = $customer->id;
        $done->customer_amount = 1000;
        $done->status = \App\Models\WorkFileModel::APPROVED;
        $done->save();

        $doneItem = new \App\Models\WorkFileItemModel;
        $doneItem->work_file_id = $done->id;
        $doneItem->work_type_id = $type->id;
        $doneItem->customer_amount = 1000;
        $doneItem->status = \App\Models\WorkFileModel::APPROVED;
        $doneItem->approved_on = now()->toDateString();
        $doneItem->save();

        // And one the office is doing itself, for In-house Work to list.
        $kept = new \App\Models\WorkFileModel;
        $kept->file_no = 'F-PROPS-'.uniqid();
        $kept->received_date = now()->toDateString();
        $kept->registration_no = 'BR01PR'.random_int(1000, 9999);
        $kept->description = 'Props fixture, in-house';
        $kept->work_type_id = $type->id;
        $kept->customer_id = $customer->id;
        $kept->customer_amount = 1000;
        $kept->status = 'in_office';
        $kept->save();

        $keptItem = new \App\Models\WorkFileItemModel;
        $keptItem->work_file_id = $kept->id;
        $keptItem->work_type_id = $type->id;
        $keptItem->customer_amount = 1000;
        $keptItem->status = 'in_office';
        $keptItem->kept_in_house_on = now()->toDateString();
        $keptItem->save();

        /*
         * And a customer who owes something, for the statement. It offers a
         * balance reminder only then, and a database where the first party
         * happened to be settled — a fresh one on the build server — would
         * read as the reminder having gone missing.
         */
        \Illuminate\Support\Facades\DB::table('party_ledger')->insert([
            'party_id' => $customer->id,
            'txn_date' => now()->toDateString(),
            'entry_type' => 'debit',
            'amount' => 1000,
            'particular' => 'Props fixture charge',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->owing = $customer->id;

        // And a payment of theirs, for the screen that adjusts one against files.
        $this->payment = \Illuminate\Support\Facades\DB::table('party_ledger')->insertGetId([
            'party_id' => $customer->id,
            'txn_date' => now()->toDateString(),
            'entry_type' => 'credit',
            'amount' => 400,
            'payment_mode' => 'UPI',
            'particular' => 'Props fixture payment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The customer seedWork() left owing, whose statement is recorded. */
    private ?int $owing = null;

    /** A payment of theirs. */
    private ?int $payment = null;
    /**
     * Every screen that mounts something, with a URL that has data behind it.
     */
    private function screens(): array
    {
        $this->seedWork();

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
            'party-statement' => 'admin/party/statement/'.$this->owing,
            'party-adjust' => 'admin/party/adjust/'.$this->payment,
            'files' => 'admin/files',
            /*
             * Filtered variants, because a view variable used only inside an
             * @if is invisible to a test that only ever loads the plain screen.
             * Moving the files list's props into its controller left $work
             * behind, referenced solely in the branch that names the active
             * status filter — so the screen was fine until someone filtered it.
             */
            'files-filtered' => 'admin/files?status=cancelled',
            'files-open' => 'admin/files?status=open',
            'files-awaiting-handover' => 'admin/files?status=awaiting_handover',
            'files-awaiting-audit' => 'admin/files?status=awaiting_audit',
            'files-papers-pending' => 'admin/files?status=papers_pending',
            'files-dated' => 'admin/files?from=2026-01-01&to=2026-12-31',
            'file-receive' => 'admin/file/receive',
            'file-assign' => 'admin/file/assign',
            'file-in-house' => 'admin/file/in-house',
            'file-vendor-return' => 'admin/file/vendor-return',
            'file-customer-return' => 'admin/file/customer-return',
            'file-hand-over' => 'admin/file/handover',
            'paper-audit' => 'admin/file/audit',
            'file-papers' => $file ? 'admin/file/'.$file->id.'/papers' : null,
            'file-status' => 'admin/file/status',
            'file-edit' => $file ? 'admin/file/edit/'.$file->id : null,
            'work-types' => 'admin/work-types',
            'paper-types' => 'admin/paper-types',
            'report-customer' => 'admin/reports/files?party_type=customer',
            'report-vendor' => 'admin/reports/files?party_type=vendor',
            'report-collection' => 'admin/reports/collection',
            // The old book: Add, Receipt and Payment are closed and mount
            // nothing now (see CloseClientLedgerController); its list,
            // statements and logins stay.
            'client-list' => 'admin/view-clients',
            'client-password' => $client ? 'admin/client/password/'.$client->id : null,
            'client-statement' => $client ? 'admin/client/statement/'.$client->id : null,
        ]);
    }

    /**
     * The shape of a value: its structure, without the data that fills it.
     *
     * A row's figures change every time someone books a file; the fact that a
     * row carries a 'billed' key which holds a number does not.
     */
    /**
     * A list with nothing in it is not a list of a different shape.
     *
     * shape() describes a list by its first entry, so a screen with rows reads
     * as ['list_of' => …] and the same screen with none reads as '[]'. What is
     * recorded is the shape rows have when there are any; a database that holds
     * none — a fresh checkout, a build server — has nothing to disagree about,
     * and failing there would say the screen had changed when only the data had.
     *
     * Only in that direction. A screen that grew rows where the record has none
     * is a real difference and still fails, and on any machine with data in it
     * the comparison is as strict as it ever was.
     */
    private function allowingEmptyLists($expected, $actual)
    {
        if (is_array($expected) && array_key_exists('list_of', $expected) && $actual === '[]') {
            return $expected;
        }

        /*
         * A nullable field reads as whatever this database happens to hold:
         * 'string' where there is a note and 'null' where there is not. The key
         * is what is being pinned, not which of the two a given row has.
         */
        if (is_string($expected) && is_string($actual) && ($expected === 'null' || $actual === 'null')) {
            return $expected;
        }

        if (! is_array($expected) || ! is_array($actual)) {
            return $actual;
        }

        foreach ($actual as $key => $value) {
            if (array_key_exists($key, $expected)) {
                $actual[$key] = $this->allowingEmptyLists($expected[$key], $value);
            }
        }

        return $actual;
    }

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
     * A screen served as a page and the same screen served as data are the same
     * screen.
     *
     * This is the property the whole approach rests on. The reason for building
     * the payload once in the controller, rather than writing a second set of
     * endpoints for the router to call, is that two implementations drift — and
     * a column added to the page and forgotten in the API is a report that
     * disagrees with itself depending on how you arrived at it.
     *
     * Nothing enforces that by construction; Screen could be changed tomorrow to
     * filter one representation and not the other. So it is asserted, on every
     * screen that has moved, rather than checked once by hand.
     */
    public function test_a_screen_served_as_json_matches_the_same_screen_served_as_a_page(): void
    {
        $this->actingAs($this->admin());

        $checked = 0;

        foreach ($this->screens() as $name => $url) {
            $json = $this->getJson($url);

            // Only the screens that have moved to Screen answer with JSON; the
            // rest still return their Blade page, and are not a failure yet.
            if (! str_contains((string) $json->headers->get('content-type'), 'json')) {
                continue;
            }

            $json->assertOk();
            $payload = $json->json();

            $this->assertArrayHasKey('props', $payload, "$name: JSON carries no props");
            $this->assertArrayHasKey('mount', $payload, "$name: JSON names no component");

            $mounts = $this->shapesOnRaw($url);

            $this->assertArrayHasKey(
                $payload['mount'],
                $mounts,
                "$name: the JSON names {$payload['mount']} but the page does not mount it"
            );

            $this->assertSame(
                $mounts[$payload['mount']],
                $payload['props'],
                "$name hands its component one thing as a page and another as data. ".
                'They come from the same array in the controller, so this means Screen is filtering one and not the other.'
            );

            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'no screen answered with JSON — the check is not running');
    }

    /**
     * The mount points on a page with their props decoded but not reduced.
     */
    private function shapesOnRaw(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();

        preg_match_all('#data-vue="([\w-]+)"\s+data-props="(.*?)"\s*>#s', $html, $found, PREG_SET_ORDER);

        $out = [];

        foreach ($found as $mount) {
            $out[$mount[1]] = json_decode(html_entity_decode($mount[2], ENT_QUOTES, 'UTF-8'), true);
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
                $this->allowingEmptyLists($expected, $current[$name]),
                "$name hands its component a different shape than before. If the change is intended, ".
                'regenerate with REGENERATE_SCREEN_PROPS=1 and read the diff.'
            );
        }

        foreach (array_keys($current) as $name) {
            $this->assertArrayHasKey($name, $golden, "$name is new — regenerate the recorded shapes");
        }
    }
}
