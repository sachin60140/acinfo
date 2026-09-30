<?php

namespace Tests\Feature;

use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The status board with a vendor chosen, the rate hints on Give to Vendor,
 * Given To on the customer-wise Work Report, and the vehicle history — each
 * asked of the works, not the folder's own vendor.
 *
 * Asked for by the owner on 2026-09-28, with the rule that anything vendor-wise
 * counts only the works given to that vendor. Asked of the folder's vendor:
 *
 *  - a vendor's view of the board listed the office's own work beside theirs,
 *    under the folder's status; the In-house view missed work the office kept
 *    on a folder a vendor had part of; a folder split between two was under
 *    neither;
 *  - a rate typed on work the office kept was offered as paid to the vendor
 *    who had the rest;
 *  - Given To named a folder a vendor held part of as wholly theirs, and one
 *    split between two as In-house.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class BoardByHolderTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $sharma;

    private PartyModel $shailendra;

    private WorkTypeModel $hpt;

    private WorkTypeModel $tr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Board Admin';
        $this->admin->email = 'board-holder-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer', 'Customer');
        $this->sharma = $this->party('vendor', 'Sharma');
        $this->shailendra = $this->party('vendor', 'Shailendra');

        $this->hpt = $this->workType('HPT');
        $this->tr = $this->workType('TR');
    }

    private function party(string $type, string $name): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = $name.' '.uniqid();
        $party->mobile = '9'.random_int(600000000, 999999999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function workType(string $name): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    /**
     * A folder and its works, each [type, vendor or null, rate, given on, status, approved on].
     *
     * @param  array<int, array>  $works
     */
    private function folder(array $works, string $plate = ''): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-BBH-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = $plate ?: 'BR06BH'.random_int(1000, 9999);
        $file->work_type_id = $works[0][0]->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 0;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        foreach ($works as $work) {
            [$type, $vendor, $rate, $on, $status, $approved] = $work + [2 => null, 3 => null, 4 => null, 5 => null];

            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = 2000;
            $item->vendor_id = $vendor?->id;
            $item->vendor_amount = $rate;
            $item->vendor_date = $vendor ? ($on ?? '2026-09-02') : null;
            $item->status = $status ?? ($vendor ? WorkFileModel::DISPATCHED : WorkFileModel::IN_OFFICE);
            $item->approved_on = $approved;
            $item->save();
        }

        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    /** HPT with Sharma; TR kept in the office. */
    private function partlyGiven(): WorkFileModel
    {
        return $this->folder([[$this->hpt, $this->sharma, 1000, '2026-09-05'], [$this->tr, null, 800]]);
    }

    private function board(array $query)
    {
        return $this->actingAs($this->admin)->getJson(route('workfile.status', $query))->assertOk();
    }

    private function onBoard(WorkFileModel $file, array $query): ?array
    {
        return collect($this->board($query)->json('props.files'))->firstWhere('id', $file->id);
    }

    // --------------------------------------------------------------- the board

    public function test_a_vendors_view_is_their_part_of_the_folder(): void
    {
        $file = $this->partlyGiven();

        $mine = $this->onBoard($file, ['vendor' => $this->sharma->id, 'status' => 'all']);

        $this->assertSame([$this->hpt->name], array_column($mine['items'], 'work_type'), 'the office\'s TR listed as theirs');
        // Where their work stands, not the folder held back by the office's.
        $this->assertSame(WorkFileModel::DISPATCHED, $mine['status']);
        $this->assertSame('05-09-2026', $mine['dispatched']);
        $this->assertSame(1, $mine['works']);
    }

    public function test_the_in_house_view_finds_work_the_office_kept_beside_a_vendors(): void
    {
        $file = $this->partlyGiven();

        $ours = $this->onBoard($file, ['vendor' => WorkFileModel::IN_HOUSE, 'status' => 'all']);

        $this->assertNotNull($ours, 'missing from In-house');
        $this->assertSame([$this->tr->name], array_column($ours['items'], 'work_type'));
        $this->assertNull($ours['dispatched']);
    }

    public function test_a_split_folder_is_under_each_vendor_for_their_own(): void
    {
        $file = $this->folder([[$this->hpt, $this->sharma, 1000], [$this->tr, $this->shailendra, 1500]]);

        $this->assertSame([$this->hpt->name], array_column($this->onBoard($file, ['vendor' => $this->sharma->id, 'status' => 'all'])['items'], 'work_type'));
        $this->assertSame([$this->tr->name], array_column($this->onBoard($file, ['vendor' => $this->shailendra->id, 'status' => 'all'])['items'], 'work_type'));
    }

    /** The tabs are their part's: approved, it is off In Hand while the office's work stays on. */
    public function test_the_tabs_follow_their_part(): void
    {
        $file = $this->folder([
            [$this->hpt, $this->sharma, 1000, '2026-09-05', WorkFileModel::APPROVED, '2026-09-10'],
            [$this->tr, null, 800],
        ]);

        $this->assertNull($this->onBoard($file, ['vendor' => $this->sharma->id, 'status' => 'open']));
        $this->assertNotNull($this->onBoard($file, ['vendor' => $this->sharma->id, 'status' => WorkFileModel::APPROVED]));
        $this->assertNotNull($this->onBoard($file, ['vendor' => WorkFileModel::IN_HOUSE, 'status' => 'open']));

        $counts = $this->board(['vendor' => $this->sharma->id])->json('page.statusCounts');
        $before = WorkFileModel::statusCounts(null, (string) $this->sharma->id);
        $this->assertSame($before[WorkFileModel::APPROVED], $counts[WorkFileModel::APPROVED]);
        $this->assertGreaterThanOrEqual(1, $counts[WorkFileModel::APPROVED]);
    }

    /** A shared folder is a chip under each holder, each counting what the board then shows. */
    public function test_the_chips_count_a_shared_folder_under_each_holder(): void
    {
        $chips = fn () => collect(WorkFileModel::vendorCounts('all'))->keyBy(fn ($chip) => (int) $chip->id);

        $before = $chips();
        $this->partlyGiven();
        $after = $chips();

        $this->assertSame(($before[$this->sharma->id]->total ?? 0) + 1, (int) $after[$this->sharma->id]->total);
        $this->assertSame((int) ($before[0]->total ?? 0) + 1, (int) $after[0]->total, 'the office\'s part not counted In-house');
    }

    public function test_the_work_types_are_their_own(): void
    {
        $this->partlyGiven();

        $types = collect(WorkFileModel::workTypeCounts('all', (string) $this->sharma->id))->pluck('id')->map(fn ($id) => (int) $id);

        $this->assertContains($this->hpt->id, $types->all());
        $this->assertNotContains($this->tr->id, $types->all());
    }

    /** No vendor chosen, the heading names everybody with work on it. */
    public function test_with_no_vendor_chosen_the_heading_names_everybody(): void
    {
        $file = $this->partlyGiven();

        $row = $this->onBoard($file, ['status' => 'all']);

        $this->assertSame($this->sharma->name.' + in-house', $row['vendor']);
        $this->assertCount(2, $row['items']);

        // And each work says whose it is, for the search.
        $this->assertSame([$this->sharma->name, null], array_column($row['items'], 'vendor'));
    }

    /** An older folder is its vendor's, all of it. */
    public function test_an_older_folder_is_all_its_vendors(): void
    {
        $file = $this->folder([[$this->hpt, null, 1000], [$this->tr, null, 800]]);
        DB::table('work_file')->where('id', $file->id)->update(['vendor_id' => $this->sharma->id, 'vendor_date' => '2026-09-03']);

        $mine = $this->onBoard($file, ['vendor' => $this->sharma->id, 'status' => 'all']);

        $this->assertCount(2, $mine['items']);
        $this->assertSame('03-09-2026', $mine['dispatched']);
    }

    /** Its one given work cancelled: in the office's view, and not the vendor's. */
    public function test_a_cancelled_given_work_leaves_the_folder_in_house(): void
    {
        $file = $this->folder([[$this->hpt, $this->sharma, 1000, '2026-09-05', WorkFileModel::CANCELLED], [$this->tr, null, 800]]);
        DB::table('work_file')->where('id', $file->id)->update(['vendor_id' => $this->sharma->id, 'vendor_date' => '2026-09-05']);

        $this->assertSame([$this->hpt->name], array_column($this->onBoard($file, ['vendor' => $this->sharma->id, 'status' => 'all'])['items'], 'work_type'));
        $this->assertSame(WorkFileModel::CANCELLED, $this->onBoard($file, ['vendor' => $this->sharma->id, 'status' => 'all'])['status']);
        $this->assertNull($this->onBoard($file, ['vendor' => $this->sharma->id, 'status' => 'open']));
        $this->assertNotNull($this->onBoard($file, ['vendor' => WorkFileModel::IN_HOUSE, 'status' => 'open']));
    }

    // ------------------------------------------------------------ found in review

    /**
     * A folder returned to its customer, then the office's work brought back:
     * the vendor's part is still with the customer — Returned, not In Hand,
     * where the board had no row of theirs to show.
     */
    public function test_a_vendors_returned_part_on_a_folder_brought_back_reads_returned(): void
    {
        $file = $this->partlyGiven();
        $file->items()->update(['status' => WorkFileModel::RETURNED]);
        $file->status = WorkFileModel::RETURNED;
        $file->returned_on = '2026-09-15';
        $file->save();
        $file->items()->where('work_type_id', $this->tr->id)->update(['status' => WorkFileModel::IN_OFFICE]);
        $file->load('items');
        $file->rollUp();
        $file->save();

        $this->assertSame(WorkFileModel::IN_OFFICE, $file->fresh()->status);

        $mine = ['vendor' => $this->sharma->id];
        $this->assertNull($this->onBoard($file, $mine + ['status' => 'open']), 'In Hand, with no row of theirs');
        $this->assertSame(WorkFileModel::RETURNED, $this->onBoard($file, $mine + ['status' => WorkFileModel::RETURNED])['status']);

        $counts = WorkFileModel::statusCounts(null, (string) $this->sharma->id);
        $listed = WorkFileModel::forStatusBoard('open', null, (string) $this->sharma->id)->count();
        $this->assertSame($listed, $counts['open'], 'the tab promises what the board then shows');
    }

    /**
     * The tab a part is counted under is the one it shows under: the SQL that
     * counts and the PHP that badges agree, for every mix of where its works
     * stand, beside the office's work.
     */
    public function test_the_counted_tab_is_the_shown_tab_for_every_mix(): void
    {
        $stages = [
            WorkFileModel::IN_OFFICE, 'paper_pendency', WorkFileModel::DISPATCHED, 'under_verification',
            WorkFileModel::APPROVED, WorkFileModel::RETURNED, WorkFileModel::CANCELLED,
        ];

        foreach ($stages as $one) {
            foreach ($stages as $two) {
                $file = $this->folder([
                    [$this->hpt, $this->sharma, 1000, '2026-09-05', $one, $one === WorkFileModel::APPROVED ? '2026-09-10' : null],
                    [$this->tr, $this->sharma, 900, '2026-09-06', $two, $two === WorkFileModel::APPROVED ? '2026-09-11' : null],
                    [$this->hpt, null, null],
                ]);

                $shown = $file->load('items.vendor', 'vendor')->partFor($this->sharma->id)['status'];

                $this->assertTrue(
                    WorkFileModel::forStatusBoard($shown, null, (string) $this->sharma->id)->contains('id', $file->id),
                    "[$one, $two] shows as $shown and is not counted under it"
                );
            }
        }
    }

    /** Each vendor's own day out, and their own finish, on the heading. */
    public function test_each_part_is_dated_from_its_own_works(): void
    {
        $split = $this->folder([[$this->hpt, $this->sharma, 1000, '2026-09-02'], [$this->tr, $this->shailendra, 1500, '2026-09-08']]);

        $this->assertSame('02-09-2026', $this->onBoard($split, ['vendor' => $this->sharma->id, 'status' => 'all'])['dispatched']);
        $this->assertSame('08-09-2026', $this->onBoard($split, ['vendor' => $this->shailendra->id, 'status' => 'all'])['dispatched']);

        // Sharma's part approved on a folder still at work: through, and how long it took.
        $partly = $this->folder([
            [$this->hpt, $this->sharma, 1000, '2026-09-05', WorkFileModel::APPROVED, '2026-09-10'],
            [$this->tr, null, 800],
        ]);

        $this->assertSame('took 5 days', $this->onBoard($partly, ['vendor' => $this->sharma->id, 'status' => WorkFileModel::APPROVED])['days_out']);
    }

    /** The chips and the types under In Hand, and under one stage, count what the board lists. */
    public function test_the_chips_and_types_count_what_the_board_lists_under_each_tab(): void
    {
        $this->folder([
            [$this->hpt, $this->sharma, 1000, '2026-09-05', WorkFileModel::APPROVED, '2026-09-10'],
            [$this->tr, null, 800],
        ]);
        $this->partlyGiven();

        foreach (['open', WorkFileModel::DISPATCHED, WorkFileModel::APPROVED] as $tab) {
            foreach ([(string) $this->sharma->id, WorkFileModel::IN_HOUSE] as $who) {
                $holder = WorkFileModel::holderKey($who);
                $chip = collect(WorkFileModel::vendorCounts($tab))->firstWhere('id', $holder);

                $this->assertSame(WorkFileModel::forStatusBoard($tab, null, $who)->count(), (int) ($chip->total ?? 0), "chip $who on $tab");

                foreach (WorkFileModel::workTypeCounts($tab, $who) as $type) {
                    $this->assertSame(
                        WorkFileModel::forStatusBoard($tab, $type->id, $who)->count(),
                        (int) $type->total,
                        "type {$type->id} for $who on $tab"
                    );
                }
            }
        }
    }

    // ------------------------------------------------------------ the rate hints

    public function test_a_rate_the_office_kept_is_not_offered_as_paid_to_a_vendor(): void
    {
        $plate = 'BR77RH'.random_int(1000, 9999);
        $this->folder([[$this->hpt, $this->sharma, 1000, '2026-09-05'], [$this->tr, null, 800]], $plate);

        $rates = WorkFileModel::recentVendorRates([[$this->tr->id, 'BR77']])[0]['rates'];

        $this->assertSame([], $rates, 'the office\'s TR rate offered as Sharma\'s');
    }

    public function test_a_split_folder_offers_each_vendors_own_rate_and_day(): void
    {
        $this->folder([[$this->hpt, $this->sharma, 1000, '2026-09-05'], [$this->tr, $this->shailendra, 1500, '2026-09-08']], 'BR78RH'.random_int(1000, 9999));

        $rate = WorkFileModel::recentVendorRates([[$this->tr->id, 'BR78']])[0]['rates'][0];

        $this->assertSame($this->shailendra->name, $rate->vendor);
        $this->assertEquals(1500, $rate->amount);
        $this->assertSame('2026-09-08', substr((string) $rate->vendor_date, 0, 10));
    }

    /** An older folder's rate is its vendor's, on the folder's day; and the newest work first. */
    public function test_an_older_folders_rate_and_the_newest_first(): void
    {
        $older = $this->folder([[$this->hpt, null, 700]], 'BR81RH'.random_int(1000, 9999));
        DB::table('work_file')->where('id', $older->id)->update(['vendor_id' => $this->shailendra->id, 'vendor_date' => '2026-09-20']);

        // A later folder, given out earlier.
        $this->folder([[$this->hpt, $this->sharma, 900, '2026-09-01']], 'BR81RH'.random_int(1000, 9999));

        $rates = WorkFileModel::recentVendorRates([[$this->hpt->id, 'BR81']])[0]['rates'];

        $this->assertSame([$this->shailendra->name, $this->sharma->name], array_column($rates, 'vendor'));
        $this->assertSame('2026-09-20', substr((string) $rates[0]->vendor_date, 0, 10));
    }

    public function test_a_cancelled_works_rate_is_not_offered(): void
    {
        $this->folder([[$this->hpt, $this->sharma, 1000, '2026-09-05', WorkFileModel::CANCELLED], [$this->tr, $this->sharma, 900]], 'BR79RH'.random_int(1000, 9999));

        $this->assertSame([], WorkFileModel::recentVendorRates([[$this->hpt->id, 'BR79']])[0]['rates']);
    }

    // ---------------------------------------------------------------- Given To

    private function givenTo(WorkFileModel $file): ?array
    {
        return collect($this->actingAs($this->admin)
            ->getJson(route('report.files', ['party_type' => 'customer']))->assertOk()->json('props.rows'))
            ->firstWhere('id', $file->id);
    }

    public function test_given_to_names_every_vendor_and_the_office(): void
    {
        $this->assertSame($this->sharma->name.' + in-house', $this->givenTo($this->partlyGiven())['counterparty']);

        $split = $this->folder([[$this->hpt, $this->sharma, 1000], [$this->tr, $this->shailendra, 1500]]);
        $this->assertSame($this->sharma->name.', '.$this->shailendra->name, $this->givenTo($split)['counterparty'], 'a split folder called In-house');

        $whole = $this->folder([[$this->hpt, $this->sharma, 1000]]);
        $this->assertSame($this->sharma->name, $this->givenTo($whole)['counterparty']);
    }

    /** Its one given work cancelled: In-house, and no dispatch day counting on. */
    public function test_given_to_a_cancelled_given_work_is_in_house_and_undated(): void
    {
        $file = $this->folder([[$this->hpt, $this->sharma, 1000, '2026-09-05', WorkFileModel::CANCELLED], [$this->tr, null, 800]]);
        DB::table('work_file')->where('id', $file->id)->update(['vendor_id' => $this->sharma->id, 'vendor_date' => '2026-09-05']);

        $row = $this->givenTo($file);

        $this->assertSame('In-house', $row['counterparty']);
        $this->assertNull($row['dispatched']);
        $this->assertNull($row['days_out']);
    }

    // ----------------------------------------------------------- vehicle history

    public function test_the_vehicle_history_names_both_vendors_of_a_split_folder(): void
    {
        $plate = 'BR80VH'.random_int(1000, 9999);
        $this->folder([[$this->hpt, $this->sharma, 1000], [$this->tr, $this->shailendra, 1500]], $plate);

        $history = WorkFileModel::historyFor($plate);

        $this->assertSame($this->sharma->name.', '.$this->shailendra->name, $history->first()->vendor_name);
    }
}
