<?php

namespace Tests\Feature;

use App\Models\ExpenseTypeModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileExpenseModel;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Profit report's vendor cut: each vendor's own works, the office's under
 * In-house.
 *
 * Asked for by the owner on 2026-09-28, with the rule that anything vendor-wise
 * counts only the works given to that vendor. Grouped by the folder's own
 * vendor, a folder a vendor held part of put its whole charge, cost and margin
 * under them, the office's work with it; a folder split between two vendors,
 * naming neither, went under In-house.
 *
 * The files here are received in 2031, so a period isolates them from whatever
 * else the database holds.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class ProfitByVendorTest extends TestCase
{
    use DatabaseTransactions;

    private const PERIOD = ['2031-01-01', '2031-12-31'];

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
        $this->admin->name = 'Profit Admin';
        $this->admin->email = 'profit-vendor-'.uniqid().'@example.com';
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
     * A folder and its works, each [type, charged, vendor or null, rate, status].
     *
     * @param  array<int, array>  $works
     */
    private function folder(array $works): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-PBV-'.uniqid();
        $file->received_date = '2031-03-01';
        $file->registration_no = 'BR06PV'.random_int(1000, 9999);
        $file->work_type_id = $works[0][0]->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 0;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        foreach ($works as $work) {
            [$type, $charged, $vendor, $rate, $status] = $work + [3 => null, 4 => null];

            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = $charged;
            $item->vendor_id = $vendor?->id;
            $item->vendor_amount = $rate;
            $item->vendor_date = $vendor ? '2031-03-02' : null;
            $item->status = $status ?? ($vendor ? WorkFileModel::DISPATCHED : WorkFileModel::IN_OFFICE);
            $item->save();
        }

        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    private function spend(WorkFileModel $file, float $amount): void
    {
        $type = new ExpenseTypeModel;
        $type->name = 'Challan '.uniqid();
        $type->is_active = 1;
        $type->save();

        $expense = new WorkFileExpenseModel;
        $expense->work_file_id = $file->id;
        $expense->expense_type_id = $type->id;
        $expense->amount = $amount;
        $expense->spent_on = '2031-03-03';
        $expense->save();
    }

    /** The vendor cut over 2031, keyed by holder: vendor id, 0 for In-house. */
    private function cut(): \Illuminate\Support\Collection
    {
        return WorkFileModel::profitBy('vendor', ...self::PERIOD)
            ->filter(fn ($row) => (int) $row->group_key >= 0)
            ->keyBy(fn ($row) => (int) $row->group_key);
    }

    // --------------------------------------------------------- each their own

    /** HPT with Sharma, TR kept in the office with a rate typed on it; 1000 spent on the file. */
    public function test_a_vendor_holding_part_of_a_folder_is_credited_their_part(): void
    {
        $file = $this->folder([
            [$this->hpt, 2000, $this->sharma, 1250],
            [$this->tr, 3000, null, 800],
        ]);
        $this->spend($file, 1000);

        $rows = $this->cut();

        // 2000 of the 5000 charged is Sharma's: 400 of the 1000 spent.
        $this->assertEquals(2000, $rows[$this->sharma->id]->billed, 'the office\'s transfer billed under Sharma');
        $this->assertEquals(1650, $rows[$this->sharma->id]->cost);
        $this->assertEquals(350, $rows[$this->sharma->id]->margin);

        // The office's: its charge, its typed rate, its share of the spend.
        $this->assertEquals(3000, $rows[0]->billed);
        $this->assertEquals(1400, $rows[0]->cost);
    }

    public function test_a_split_folder_is_each_vendors_own_and_not_in_house(): void
    {
        $this->folder([
            [$this->hpt, 2000, $this->sharma, 1000],
            [$this->tr, 3000, $this->shailendra, 1500],
        ]);

        $rows = $this->cut();

        $this->assertEquals(2000, $rows[$this->sharma->id]->billed);
        $this->assertEquals(3000, $rows[$this->shailendra->id]->billed);
        $this->assertArrayNotHasKey(0, $rows->all(), 'the split folder went under In-house');
    }

    /** A vendor's line is the sum of their rows on the vendor-wise Work Report. */
    public function test_a_vendors_line_is_their_work_report_rows_added_up(): void
    {
        $file = $this->folder([
            [$this->hpt, 2000, $this->sharma, 1250],
            [$this->tr, 3000, $this->shailendra, 900],
        ]);
        $this->spend($file, 777.77);

        $report = collect($this->actingAs($this->admin)
            ->getJson(route('report.files', ['party_type' => 'vendor', 'from' => self::PERIOD[0], 'to' => self::PERIOD[1]]))
            ->assertOk()->json('props.rows'));

        $rows = $this->cut();

        foreach ([$this->sharma, $this->shailendra] as $vendor) {
            $theirs = $report->where('party_id', $vendor->id);

            $this->assertEqualsWithDelta($theirs->sum('billed'), $rows[$vendor->id]->billed, 0.005);
            $this->assertEqualsWithDelta($theirs->sum('cost'), $rows[$vendor->id]->cost, 0.005);
        }
    }

    // --------------------------------------------------------- the whole

    /** Every cut of the period still accounts for the same money. */
    public function test_the_vendor_cut_accounts_for_what_the_month_cut_does(): void
    {
        $this->spend($this->folder([[$this->hpt, 2000, $this->sharma, 1250], [$this->tr, 3000, null, 800]]), 333.33);
        $this->spend($this->folder([[$this->hpt, 2000, $this->sharma, 1000], [$this->tr, 3000, $this->shailendra, 1500]]), 100.01);
        $this->folder([[$this->hpt, 1500, $this->sharma, 700]]);
        $this->folder([[$this->tr, 900, null, null]]);

        $vendor = $this->cut();
        $month = WorkFileModel::profitBy('month', ...self::PERIOD)->filter(fn ($row) => $row->group_key === '2031-03');

        $this->assertEqualsWithDelta($month->sum('billed'), $vendor->sum('billed'), 0.005);
        $this->assertEqualsWithDelta($month->sum('cost'), $vendor->sum('cost'), 0.005);
    }

    /** A folder whose total has drifted from its works: the difference is still counted, under In-house. */
    public function test_a_drifted_folder_still_adds_up(): void
    {
        $file = $this->folder([[$this->hpt, 2000, $this->sharma, 1000], [$this->tr, 3000, $this->shailendra, 1500]]);
        DB::table('work_file')->where('id', $file->id)->update(['customer_amount' => 5100, 'vendor_amount' => 2600]);

        $vendor = $this->cut();
        $month = WorkFileModel::profitBy('month', ...self::PERIOD)->firstWhere('group_key', '2031-03');

        $this->assertEqualsWithDelta($month->billed, $vendor->sum('billed'), 0.005);
        $this->assertEqualsWithDelta($month->cost, $vendor->sum('cost'), 0.005);
        $this->assertEquals(100, $vendor[0]->billed);
    }

    /** The heading counts a shared folder once, and its grid does not add up files. */
    public function test_the_heading_counts_each_file_once(): void
    {
        $this->folder([[$this->hpt, 2000, $this->sharma, 1000], [$this->tr, 3000, $this->shailendra, 1500]]);
        $this->folder([[$this->hpt, 1500, $this->sharma, 700]]);

        $response = $this->actingAs($this->admin)
            ->getJson(route('report.profit', ['group' => 'vendor', 'from' => self::PERIOD[0], 'to' => self::PERIOD[1]]))
            ->assertOk();

        $this->assertSame(2, $response->json('page.totals.files'));
        $this->assertArrayNotHasKey('files', $response->json('props.totals'));
    }

    // ------------------------------------------------------------- margins

    /**
     * As the owner chose: a vendor's margin is kept out only while their own
     * works wait on a price — not because the office's part does.
     */
    public function test_a_vendors_margin_waits_only_on_their_own_price(): void
    {
        $this->folder([
            [$this->hpt, 2000, $this->sharma, 1250],
            [$this->tr, 0, null, null],
        ]);

        $rows = $this->cut();

        $this->assertSame(0, $rows[$this->sharma->id]->unpriced);
        $this->assertEquals(750, $rows[$this->sharma->id]->margin);
        $this->assertSame(1, $rows[0]->unpriced, 'the office\'s unpriced part');
    }

    // ------------------------------------------------------------- the edges

    /** Its one given work cancelled, what is left was the office's. */
    public function test_a_cancelled_given_work_leaves_the_folder_in_house(): void
    {
        $file = $this->folder([[$this->hpt, 2000, $this->sharma, 1250], [$this->tr, 3000, null, null]]);
        $file->items()->where('vendor_id', $this->sharma->id)->update(['status' => WorkFileModel::CANCELLED]);
        $file->load('items');
        $file->rollUp();
        $file->save();
        // As one struck off before roll-up learned to clear the folder's vendor.
        DB::table('work_file')->where('id', $file->id)->update(['vendor_id' => $this->sharma->id]);

        $rows = $this->cut();

        $this->assertArrayNotHasKey($this->sharma->id, $rows->all());
        $this->assertEquals(3000, $rows[0]->billed);
    }

    /** Struck off whole, its spend stays with who had it; an older folder is its vendor's. */
    public function test_a_folder_struck_off_whole_and_an_older_one_stay_with_their_vendor(): void
    {
        $cancelled = $this->folder([[$this->hpt, 2000, $this->sharma, 1250]]);
        $cancelled->items()->update(['status' => WorkFileModel::CANCELLED]);
        $cancelled->load('items');
        $cancelled->rollUp();
        $cancelled->save();
        $this->spend($cancelled, 300);

        $older = $this->folder([[$this->tr, 3000, null, null]]);
        $older->vendor_id = $this->shailendra->id;
        $older->vendor_amount = 1200;
        $older->save();

        $rows = $this->cut();

        $this->assertEquals(300, $rows[$this->sharma->id]->cost);
        $this->assertEquals(3000, $rows[$this->shailendra->id]->billed);
        $this->assertEquals(1200, $rows[$this->shailendra->id]->cost);
    }

    // --------------------------------------------------------- the work type cut

    /** A work handed back costs its rate less what came back of it, as SPENT says. */
    public function test_the_work_type_cut_takes_off_what_came_back(): void
    {
        $file = $this->folder([[$this->hpt, 2000, $this->sharma, 1000], [$this->tr, 3000, $this->shailendra, 1500]]);

        $this->actingAs($this->admin)->post(route('workfile.vendorreturn'), [
            'returned_on' => '2031-03-10',
            'files' => [$file->id.':'.$this->sharma->id],
            'amounts' => [$file->id.':'.$this->sharma->id => 400],
            'remark' => 'Could not do it',
        ])->assertRedirect(route('workfile.index'));

        $rows = WorkFileModel::profitBy('work_type', ...self::PERIOD)->keyBy('group_key');

        $this->assertEquals(600, $rows[$this->hpt->id]->cost);
        $this->assertEquals(1500, $rows[$this->tr->id]->cost);

        $month = WorkFileModel::profitBy('month', ...self::PERIOD)->firstWhere('group_key', '2031-03');
        $this->assertEqualsWithDelta($month->cost, $rows[$this->hpt->id]->cost + $rows[$this->tr->id]->cost, 0.005);
    }

    /** An older folder's hand-back is the folder's, shared over its works by rate: all of it. */
    public function test_the_work_type_cut_takes_off_an_older_folders_hand_back(): void
    {
        [$cost, $month] = $this->olderHandedBack(null);

        $this->assertEqualsWithDelta(0, $cost, 0.005, 'the rate counted whole');
        $this->assertEqualsWithDelta($month, $cost, 0.005);
    }

    /** And a part of it. */
    public function test_the_work_type_cut_takes_off_an_older_folders_part(): void
    {
        [$cost, $month] = $this->olderHandedBack(400);

        $this->assertEqualsWithDelta(800, $cost, 0.005);
        $this->assertEqualsWithDelta($month, $cost, 0.005);
    }

    /** @return array{0: float, 1: float} the Work Type cut's cost of it, and the month's */
    private function olderHandedBack(?float $typed): array
    {
        $file = $this->folder([[$this->tr, 3000, null, 1200]]);
        $file->vendor_id = $this->sharma->id;
        $file->vendor_date = '2031-03-02';
        $file->save();

        $this->actingAs($this->admin)->post(route('workfile.vendorreturn'), [
            'returned_on' => '2031-03-10',
            'files' => [$file->id.':'.$this->sharma->id],
            'amounts' => $typed ? [$file->id.':'.$this->sharma->id => $typed] : [],
            'remark' => 'Could not do it',
        ])->assertRedirect(route('workfile.index'));

        return [
            (float) WorkFileModel::profitBy('work_type', ...self::PERIOD)->firstWhere('group_key', $this->tr->id)->cost,
            (float) WorkFileModel::profitBy('month', ...self::PERIOD)->firstWhere('group_key', '2031-03')->cost,
        ];
    }

    /**
     * The vendor tab's margin is each holder's part, so the note beside it
     * counts parts. Found in review: in files, it read "on 0 of 1 files" over
     * a margin that had the vendor's priced part in it.
     */
    public function test_the_heading_says_the_margin_covers_parts(): void
    {
        $this->folder([
            [$this->hpt, 2000, $this->sharma, 1250],
            [$this->tr, 0, null, null],
        ]);

        $query = ['group' => 'vendor', 'from' => self::PERIOD[0], 'to' => self::PERIOD[1]];

        $totals = $this->actingAs($this->admin)->getJson(route('report.profit', $query))->assertOk()->json('page.totals');

        $this->assertSame(1, $totals['files']);
        $this->assertSame(2, $totals['parts']);
        $this->assertSame(1, $totals['unpriced']);
        $this->assertEquals(750, $totals['margin']);

        $this->actingAs($this->admin)->get(route('report.profit', $query))
            ->assertOk()->assertSee('on 1 of 2 parts', false);
    }

    /** A vendor found only on shared folders is named, and sorted by what they billed. */
    public function test_a_vendor_only_on_shared_folders_is_named_and_sorted(): void
    {
        $this->folder([[$this->hpt, 1000, $this->sharma, 500]]);
        $this->folder([[$this->hpt, 2000, $this->sharma, 900], [$this->tr, 9000, $this->shailendra, 4000]]);

        $rows = WorkFileModel::profitBy('vendor', ...self::PERIOD)->values();

        $this->assertSame($this->shailendra->name, $rows->firstWhere('group_key', $this->shailendra->id)->group_label);
        $this->assertSame($this->sharma->name, $rows->firstWhere('group_key', $this->sharma->id)->group_label);

        // Shailendra's 9000 on top, above Sharma's 3000.
        $order = $rows->pluck('group_key')->map(fn ($key) => (int) $key)->all();
        $this->assertLessThan(array_search($this->sharma->id, $order), array_search($this->shailendra->id, $order));
    }
}
