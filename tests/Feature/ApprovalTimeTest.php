<?php

namespace Tests\Feature;

use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Approval Time: how many days approval took from the day the work was
 * dispatched, for every approved file, customer-wise and vendor-wise. Asked
 * for by the owner on 2026-09-28, one row a file.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class ApprovalTimeTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Approval Time Admin';
        $this->admin->email = 'approval-time-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' timed';
        $party->mobile = '93500'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /**
     * A file of works, each [vendor or null, dispatched days ago or null, approved days ago or null, status].
     *
     * @param  array<int, array{0: ?PartyModel, 1: ?int, 2: ?int, 3?: string}>  $works
     */
    private function file(array $works, ?PartyModel $customer = null): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-AT-'.uniqid();
        $file->received_date = now()->subDays(120)->toDateString();
        $file->registration_no = 'BR06AT'.random_int(1000, 9999);
        $file->work_type_id = $this->type()->id;
        $file->customer_id = ($customer ?? $this->customer)->id;
        $file->customer_amount = 0;
        $file->status = WorkFileModel::DISPATCHED;
        $file->save();

        foreach ($works as $work) {
            [$vendor, $sent, $approved] = $work;

            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $this->type()->id;
            $item->customer_amount = 1000;
            $item->status = $work[3] ?? ($approved !== null ? WorkFileModel::APPROVED : WorkFileModel::DISPATCHED);
            $item->approved_on = $approved !== null ? now()->subDays($approved)->toDateString() : null;

            if ($vendor) {
                $item->vendor_id = $vendor->id;
                $item->vendor_amount = 500;
                $item->vendor_date = $sent === null ? null : now()->subDays($sent)->toDateString();
            } else {
                $item->kept_in_house_on = now()->subDays(100)->toDateString();
            }

            $item->save();
        }

        $file->rollUp();
        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    private function type(): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = 'Timed Work '.uniqid();
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    private function page(array $query = [])
    {
        return $this->actingAs($this->admin)->getJson(route('report.approvaltime', $query))->assertOk();
    }

    private function row(WorkFileModel $file, array $query = []): ?array
    {
        return collect($this->page($query)->json('props.rows'))->firstWhere('file_no', $file->file_no);
    }

    public function test_it_counts_from_dispatch_to_approval(): void
    {
        $vendor = $this->party('vendor');
        $file = $this->file([[$vendor, 30, 18]]);

        $row = $this->row($file);

        $this->assertSame(12, $row['days']);
        $this->assertSame(now()->subDays(30)->format('d-m-Y'), $row['dispatched']);
        $this->assertSame(now()->subDays(18)->format('d-m-Y'), $row['approved']);
    }

    /**
     * Customer-wise, no vendor: asked for by the owner on 2026-09-28. Not
     * hidden but absent — a spreadsheet made from the page could not carry
     * what the page was never sent.
     */
    public function test_customer_wise_never_names_the_vendor(): void
    {
        $vendor = $this->party('vendor');
        $vendor->name = 'Zorawar Distinct Works';
        $vendor->save();
        $file = $this->file([[$vendor, 30, 18]]);

        $page = $this->page(['party_id' => $this->customer->id]);

        $this->assertNotNull($this->row($file, ['party_id' => $this->customer->id]));
        $this->assertNull(collect($page->json('props.columns'))->firstWhere('key', 'counterparty'));
        $this->assertStringNotContainsString('Zorawar', json_encode($page->json()));

        $this->actingAs($this->admin)->get(route('report.approvaltime', ['party_id' => $this->customer->id]))
            ->assertOk()
            ->assertDontSee('Zorawar')
            ->assertDontSee('Given To');

        // Vendor-wise, the office still sees whose file it was.
        $byVendor = $this->page(['party_type' => 'vendor', 'party_id' => $vendor->id]);
        $this->assertSame('Customer', collect($byVendor->json('props.columns'))->firstWhere('key', 'counterparty')['label']);
        $this->assertSame($this->customer->name, collect($byVendor->json('props.rows'))->firstWhere('file_no', $file->file_no)['counterparty']);
    }

    /** A folder of three is not through until the third one is. */
    public function test_a_file_of_several_works_is_approved_when_its_last_one_is(): void
    {
        $vendor = $this->party('vendor');
        $file = $this->file([[$vendor, 40, 35], [$vendor, 40, 20], [$vendor, 40, 28]]);

        $this->assertSame(20, $this->row($file)['days']);
    }

    public function test_only_approved_files_are_counted(): void
    {
        $vendor = $this->party('vendor');
        $running = $this->file([[$vendor, 10, null]]);
        $half = $this->file([[$vendor, 10, 5], [$vendor, 10, null]]);

        $this->assertNull($this->row($running));
        $this->assertNull($this->row($half), 'partly approved is not approved');

        // Papers given back to the customer finished too — on the day they
        // went back, which is not an approval.
        $returned = $this->file([[$vendor, 10, null, WorkFileModel::RETURNED]]);
        $returned->status = WorkFileModel::RETURNED;
        $returned->returned_on = now()->subDays(2)->toDateString();
        $returned->save();

        $this->assertNull($this->row($returned));
    }

    public function test_files_that_cannot_be_counted_are_said_not_hidden(): void
    {
        $before = $this->page()->json('page');

        // Done here and never dispatched.
        $inHouse = $this->file([[null, null, 5]]);

        // Approved before it went out: a date typed wrong.
        $misdated = $this->file([[$this->party('vendor'), 5, 9]]);

        $page = $this->page();

        $this->assertNull($this->row($inHouse));
        $this->assertNull($this->row($misdated));
        $this->assertSame($before['inHouse'] + 1, $page->json('page.inHouse'));
        $this->assertSame($before['misdated'] + 1, $page->json('page.misdated'));

        $this->actingAs($this->admin)->get(route('report.approvaltime'))
            ->assertSee('done in-house and never dispatched')
            ->assertSee('an approval dated before');
    }

    public function test_the_period_is_the_day_it_was_approved(): void
    {
        $vendor = $this->party('vendor');
        $lastMonth = $this->file([[$vendor, 90, 40]]);
        $thisWeek = $this->file([[$vendor, 90, 3]]);

        $query = ['from' => now()->subDays(10)->toDateString(), 'to' => now()->toDateString()];

        $this->assertNull($this->row($lastMonth, $query), 'went out long ago and was approved long ago');
        $this->assertSame(87, $this->row($thisWeek, $query)['days'], 'went out long ago, approved this week');
    }

    /**
     * Vendor-wise, a vendor's own works: another vendor's, or work done
     * in-house, approved later is not time this vendor took.
     */
    public function test_vendor_wise_counts_only_that_vendors_works(): void
    {
        $quick = $this->party('vendor');
        $slow = $this->party('vendor');

        // Split between two vendors, and one work done in-house, approved last.
        $file = $this->file([[$quick, 30, 26], [$slow, 25, 5], [null, null, 2]]);

        $rows = collect($this->page(['party_type' => 'vendor'])->json('props.rows'))->where('file_no', $file->file_no)->keyBy('party_id');

        $this->assertSame(4, $rows[$quick->id]['days']);
        $this->assertSame(20, $rows[$slow->id]['days']);
        $this->assertSame($this->customer->name, $rows[$quick->id]['counterparty']);

        // Customer-wise it is one file: from the first dispatch to the last approval.
        $this->assertSame(28, $this->row($file)['days']);
    }

    /**
     * Its only vendor work cancelled — and the in-house work approved later is
     * no time of theirs. The folder named the vendor and the day still, until
     * roll-up learned to clear them (2026-09-28); either way it is not theirs.
     */
    public function test_a_folder_whose_vendor_work_was_cancelled_is_in_house(): void
    {
        $before = $this->page()->json('page');

        $vendor = $this->party('vendor');
        $vendorPage = ['party_type' => 'vendor', 'party_id' => $vendor->id];
        $file = $this->file([[$vendor, 15, null], [null, null, 20]]);

        $given = $file->items->firstWhere('vendor_id', $vendor->id);
        $given->status = WorkFileModel::CANCELLED;
        $given->save();
        $file->rollUp();
        $file->save();

        $this->assertNull($file->fresh()->vendor_id, 'the folder names nobody now');
        $this->assertSame(WorkFileModel::APPROVED, $file->fresh()->status);

        $page = $this->page();

        $this->assertNull($this->row($file));
        $this->assertSame($before['misdated'], $page->json('page.misdated'), 'no date was typed wrong');
        $this->assertSame($before['inHouse'] + 1, $page->json('page.inHouse'), 'what is left of it was done here');

        $vendorWise = $this->page($vendorPage);
        $this->assertSame([], $vendorWise->json('props.rows'), 'not the vendor\'s time');

        // Nor anything about it to say on their page: it is not theirs.
        foreach (['inHouse', 'undated', 'misdated'] as $note) {
            $this->assertSame(0, $vendorWise->json("page.$note"), $note);
        }
    }

    public function test_a_vendor_file_with_no_dispatch_date_is_not_called_in_house(): void
    {
        $vendor = $this->party('vendor');
        $before = $this->page(['party_type' => 'vendor', 'party_id' => $vendor->id])->json('page');

        $file = $this->file([[$vendor, null, 5]]);

        $page = $this->page(['party_type' => 'vendor', 'party_id' => $vendor->id]);

        $this->assertNull($this->row($file, ['party_type' => 'vendor', 'party_id' => $vendor->id]));
        $this->assertSame($before['inHouse'], $page->json('page.inHouse'));
        $this->assertSame($before['undated'] + 1, $page->json('page.undated'));

        $this->actingAs($this->admin)->get(route('report.approvaltime', ['party_type' => 'vendor', 'party_id' => $vendor->id]))
            ->assertSee('given to a vendor with no dispatch date')
            ->assertDontSee('done in-house and never dispatched');
    }

    /** Given back unfinished and done by the office: not the vendor's time. */
    public function test_work_taken_back_before_approval_is_not_the_vendors_time(): void
    {
        $vendor = $this->party('vendor');
        $file = $this->file([[$vendor, 30, 1]]);

        $work = $file->items->first();
        $work->vendor_returned_on = now()->subDays(25)->toDateString();
        $work->save();

        $vendorWise = collect($this->page(['party_type' => 'vendor', 'party_id' => $vendor->id])->json('props.rows'));
        $this->assertNull($vendorWise->firstWhere('file_no', $file->file_no));

        // The customer's file still took 29 days from going out.
        $this->assertSame(29, $this->row($file)['days']);
    }

    /** A file from before works carried a vendor: the folder's vendor has all of it. */
    public function test_an_older_file_is_the_folders_vendors(): void
    {
        $vendor = $this->party('vendor');
        $file = $this->file([[null, null, 8]]);

        // Written the old way: the folder names the vendor, the work does not.
        WorkFileItemModel::where('work_file_id', $file->id)->update(['kept_in_house_on' => null]);
        $file->vendor_id = $vendor->id;
        $file->vendor_date = now()->subDays(20)->toDateString();
        $file->save();

        $this->assertSame(12, $this->row($file)['days']);

        $vendorWise = collect($this->page(['party_type' => 'vendor', 'party_id' => $vendor->id])->json('props.rows'));
        $this->assertSame(12, $vendorWise->firstWhere('file_no', $file->file_no)['days']);
    }

    /** The card and the grid's foot round alike: a mean of 1.15 is 1.2 on both. */
    public function test_the_average_rounds_as_the_grid_does(): void
    {
        $vendor = $this->party('vendor');

        for ($i = 0; $i < 20; $i++) {
            $this->file([[$vendor, $i < 3 ? 12 : 11, 10]]);
        }

        $query = ['party_type' => 'vendor', 'party_id' => $vendor->id];

        $this->assertEquals(1.2, $this->page($query)->json('page.totals.average'));

        $this->actingAs($this->admin)->get(route('report.approvaltime', $query))->assertSee('1.2 days');
    }

    /** Latest first, customer-wise and vendor-wise: asked for by the owner. */
    public function test_the_latest_approval_comes_first(): void
    {
        $vendor = $this->party('vendor');

        // Named to come first by name, so only "latest first" puts them below.
        $other = $this->party('vendor');
        $other->name = 'Aaa Other Works '.uniqid();
        $other->save();

        $quiet = $this->party('customer');
        $quiet->name = 'Aaa Quiet Customer '.uniqid();
        $quiet->save();

        $older = $this->file([[$vendor, 60, 30]]);
        $newest = $this->file([[$vendor, 60, 1]]);
        $middle = $this->file([[$vendor, 60, 12]]);

        // Another customer and vendor, approved before any of those.
        $this->file([[$other, 90, 50]], $quiet);

        foreach ([[], ['party_type' => 'vendor']] as $query) {
            $page = $this->page($query);
            $rows = collect($page->json('props.rows'));
            $mine = $rows->where('party_id', $query ? $vendor->id : $this->customer->id)->pluck('file_no')->values();

            $this->assertSame([$newest->file_no, $middle->file_no, $older->file_no], $mine->all(), 'latest first in the band');

            // The band approved most lately above the one approved long ago.
            $bands = $rows->pluck('party_id')->unique()->values();
            $this->assertLessThan(
                $bands->search($query ? $other->id : $quiet->id),
                $bands->search($query ? $vendor->id : $this->customer->id)
            );

            $this->assertSame('approved', $page->json('props.sortedBy'));
            $this->assertTrue($page->json('props.sortedDesc'));
        }
    }

    public function test_a_party_can_be_picked(): void
    {
        $vendor = $this->party('vendor');
        $other = $this->party('customer');
        $mine = $this->file([[$vendor, 20, 10]]);
        $theirs = $this->file([[$vendor, 20, 10]], $other);

        $rows = collect($this->page(['party_id' => $this->customer->id])->json('props.rows'))->pluck('file_no');

        $this->assertContains($mine->file_no, $rows);
        $this->assertNotContains($theirs->file_no, $rows);

        $byVendor = collect($this->page(['party_type' => 'vendor', 'party_id' => $vendor->id])->json('props.rows'))->pluck('file_no');
        $this->assertEqualsCanonicalizing([$mine->file_no, $theirs->file_no], $byVendor->all());
    }

    public function test_it_says_the_average_the_fastest_and_the_slowest(): void
    {
        $vendor = $this->party('vendor');
        $this->file([[$vendor, 20, 16]]);
        $slowest = $this->file([[$vendor, 60, 10]]);
        $this->file([[$vendor, 30, 20]]);

        $page = $this->page(['party_type' => 'vendor', 'party_id' => $vendor->id]);

        $this->assertEquals(21.3, $page->json('page.totals.average'));
        $this->assertSame(4, $page->json('page.totals.fastest'));
        $this->assertSame(50, $page->json('page.totals.slowest'));
        $this->assertSame($slowest->file_no, $page->json('page.totals.slowestFile'));

        // And each party's under their files, by the grid.
        $this->assertSame('avg', $page->json('props.totals.days'));
    }

    public function test_it_exports_the_days_and_who_they_belong_to(): void
    {
        $columns = collect($this->page()->json('props.columns'))->keyBy('key');

        $this->assertArrayHasKey('days', $columns);
        $this->assertNotFalse($columns['days']['exportable'] ?? true, 'Days Taken reaches the spreadsheet');
        $this->assertTrue($columns['party_name']['exportOnly'], 'and the party, which a spreadsheet has no band for');
    }

    public function test_it_is_on_the_menu_and_shut_to_strangers(): void
    {
        $this->actingAs($this->admin)->get('admin/dashboard')->assertOk()->assertSee(route('report.approvaltime'), false);

        $this->actingAs($this->admin)->get(route('report.approvaltime'))
            ->assertOk()
            ->assertSee('data-vue="vue-approval-time"', false);

        auth()->logout();

        $this->get(route('report.approvaltime'))->assertRedirect(url('/admin'));
    }
}
