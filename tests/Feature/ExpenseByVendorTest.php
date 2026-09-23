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
 * Everything one vendor's files cost.
 *
 * Asked for by the owner after the vehicle search (2026-09-23): the Expense
 * Report, narrowed to one vendor. It answers with what was agreed with that
 * vendor — their charge on each work they were given — and every expense paid
 * out on the files they were given, and the total.
 *
 * A folder split between two vendors is each one's file: each is shown their
 * own charge only, and the folder's expenses under both, since a challan is
 * paid for the file rather than for either vendor's part of it.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class ExpenseByVendorTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $vendor;

    private PartyModel $rival;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpa;

    private int $challan;

    private int $affidavit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Vendor Costs Admin';
        $this->admin->email = 'vendor-costs-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer', 'Rakesh Ji Madhubani');
        $this->vendor = $this->party('vendor', 'Parwez Ji Muzaffarpur');
        $this->rival = $this->party('vendor', 'Shailendra Ji Motihari');

        $this->tr = $this->type('TR');
        $this->hpa = $this->type('HPA');

        $this->challan = $this->kind('Challan');
        $this->affidavit = $this->kind('Affidavit');
    }

    private function party(string $type, string $name): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = $name.' '.uniqid();
        $party->mobile = '93900'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function type(string $name): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    private function kind(string $name): int
    {
        return (int) DB::table('expense_type')->insertGetId([
            'name' => $name.' '.uniqid(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A folder with a work per [type, vendor, rate], each given out on the 18th.
     *
     * @param  array<int, array{0: WorkTypeModel, 1: PartyModel, 2: float}>  $works
     */
    private function file(array $works, ?string $plate = null): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-EVV-'.uniqid();
        $file->received_date = '2026-09-15';
        $file->registration_no = $plate ?? 'BR01VC'.random_int(1000, 9999);
        $file->work_type_id = $works[0][0]->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 0;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        foreach ($works as [$type, $vendor, $rate]) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = 5000;
            $item->vendor_id = $vendor->id;
            $item->vendor_amount = $rate;
            $item->vendor_date = '2026-09-18';
            $item->status = WorkFileModel::DISPATCHED;
            $item->save();
        }

        $file->rollUp();
        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    private function spend(WorkFileModel $file, int $kind, float $amount, string $on = '2026-09-19'): void
    {
        DB::table('work_file_expense')->insert([
            'work_file_id' => $file->id,
            'expense_type_id' => $kind,
            'amount' => $amount,
            'spent_on' => $on,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function report(array $query)
    {
        return $this->actingAs($this->admin)
            ->getJson(route('report.expenses').'?'.http_build_query($query))
            ->assertOk();
    }

    private function rows(array $query)
    {
        return collect($this->report($query)->json('props.rows'));
    }

    private function spent(WorkFileModel $file): float
    {
        return (float) WorkFileModel::whereKey($file->id)->selectRaw(WorkFileModel::SPENT.' as spent')->value('spent');
    }

    // ------------------------------------------------------------- the point

    /** Their charge on each work they were given, every expense on those files, and what it came to. */
    public function test_a_vendor_shows_their_charges_and_what_was_spent_on_their_files(): void
    {
        $one = $this->file([[$this->tr, $this->vendor, 3000]]);
        $two = $this->file([[$this->hpa, $this->vendor, 1200]]);
        $theirs = $this->file([[$this->tr, $this->rival, 9999]]);

        $this->spend($one, $this->challan, 450);
        $this->spend($two, $this->affidavit, 150);
        $this->spend($theirs, $this->challan, 777);

        $rows = $this->rows(['vendor_id' => $this->vendor->id]);

        // 3,000 + 1,200 + 450 + 150.
        $this->assertEqualsWithDelta(4800, $rows->sum('amount'), 0.005);
        $this->assertSame([$one->id, $two->id], $rows->pluck('file_id')->unique()->sort()->values()->all());
        $this->assertFalse($rows->contains('amount', 9999.0));
        $this->assertFalse($rows->contains('amount', 777.0));

        $charges = $rows->where('type_name', 'Vendor charge');
        $this->assertCount(2, $charges);
        $this->assertEqualsWithDelta(4200, $charges->sum('amount'), 0.005);
    }

    /** A folder split between two vendors: each their own charge, the folder's expenses under both. */
    public function test_a_split_folder_shows_each_vendor_their_own_charge(): void
    {
        $split = $this->file([[$this->tr, $this->vendor, 3000], [$this->hpa, $this->rival, 900]]);
        $this->spend($split, $this->challan, 450);

        $mine = $this->rows(['vendor_id' => $this->vendor->id]);
        $this->assertEqualsWithDelta(3450, $mine->sum('amount'), 0.005);
        $this->assertFalse($mine->contains('amount', 900.0));

        $theirs = $this->rows(['vendor_id' => $this->rival->id]);
        $this->assertEqualsWithDelta(1350, $theirs->sum('amount'), 0.005);
        $this->assertFalse($theirs->contains('amount', 3000.0));
    }

    /** A folder with no works carries its vendor and rate on itself. */
    public function test_a_folder_with_no_works_is_the_vendor_it_names(): void
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-EVV-'.uniqid();
        $file->received_date = '2026-09-15';
        $file->registration_no = 'BR01VC'.random_int(1000, 9999);
        $file->work_type_id = $this->tr->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 5000;
        $file->vendor_id = $this->vendor->id;
        $file->vendor_amount = 2000;
        $file->vendor_date = '2026-09-17';
        $file->status = WorkFileModel::DISPATCHED;
        $file->save();

        $this->spend($file, $this->challan, 300);

        $rows = $this->rows(['vendor_id' => $this->vendor->id]);

        $this->assertEqualsWithDelta(2300, $rows->sum('amount'), 0.005);
        $this->assertCount(0, $this->rows(['vendor_id' => $this->rival->id]));
    }

    /** Handed back undone, the rate is reversed: the file costs what the Profit report says. */
    public function test_a_file_handed_back_costs_what_the_rest_of_the_app_says(): void
    {
        $file = $this->file([[$this->tr, $this->vendor, 3000], [$this->hpa, $this->vendor, 1200]]);
        $this->spend($file, $this->challan, 450);

        $file->items()->update(['vendor_returned_on' => '2026-09-20', 'status' => WorkFileModel::IN_OFFICE]);
        $file->vendor_returned_amount = 1000;
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        $rows = $this->rows(['vendor_id' => $this->vendor->id]);

        // 3,000 + 1,200 − 1,000 + 450.
        $this->assertEqualsWithDelta(3650, $this->spent($file), 0.005);
        $this->assertEqualsWithDelta($this->spent($file), $rows->sum('amount'), 0.005);

        // Nothing of it is anybody else's, the reversal included.
        $this->assertCount(0, $this->rows(['vendor_id' => $this->rival->id]));
    }

    /** Work struck off is nobody's, and a file whose only work with them was struck off is not theirs. */
    public function test_work_struck_off_is_not_theirs(): void
    {
        $file = $this->file([[$this->tr, $this->vendor, 3000], [$this->hpa, $this->rival, 900]]);
        $this->spend($file, $this->challan, 450);

        $file->items()->where('vendor_id', $this->vendor->id)->update(['status' => WorkFileModel::CANCELLED]);

        $this->assertCount(0, $this->rows(['vendor_id' => $this->vendor->id]));
        $this->assertEqualsWithDelta(1350, $this->rows(['vendor_id' => $this->rival->id])->sum('amount'), 0.005);
    }

    // --------------------------------------------------------- with the rest

    /** With a vehicle too: that vehicle's files, and only this vendor's part of them. */
    public function test_with_a_vehicle_it_is_that_vehicles_files_and_this_vendors_part(): void
    {
        $plate = 'BR05VQ'.random_int(1000, 9999);
        $split = $this->file([[$this->tr, $this->vendor, 3000], [$this->hpa, $this->rival, 900]], $plate);
        $this->spend($split, $this->challan, 450);
        $elsewhere = $this->file([[$this->tr, $this->vendor, 5555]]);

        $props = $this->report(['vehicle' => $plate, 'vendor_id' => $this->vendor->id])->json();
        $rows = collect($props['props']['rows']);

        $this->assertEqualsWithDelta(3450, $rows->sum('amount'), 0.005);
        $this->assertSame([$split->id], $rows->pluck('file_id')->unique()->values()->all());
        $this->assertSame('file_id', $props['props']['groupBy']);
        $this->assertSame('Costs on '.$plate.', given to '.$this->vendor->name, $props['page']['heading']);
    }

    /** A kind asked for is a kind of expense: the vendor's charge is not one. */
    public function test_a_kind_asked_for_leaves_the_charge_out(): void
    {
        $file = $this->file([[$this->tr, $this->vendor, 3000]]);
        $this->spend($file, $this->challan, 450);
        $this->spend($file, $this->affidavit, 150);

        $rows = $this->rows(['vendor_id' => $this->vendor->id, 'expense_type_id' => $this->challan]);

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(450, $rows->sum('amount'), 0.005);
    }

    /** A customer asked for as well: that customer's files with this vendor. */
    public function test_a_customer_asked_for_as_well_narrows_it(): void
    {
        $this->file([[$this->tr, $this->vendor, 3000]]);
        $other = $this->party('customer', 'Somebody Else');

        $this->assertCount(0, $this->rows(['vendor_id' => $this->vendor->id, 'party_id' => $other->id]));
        $this->assertCount(1, $this->rows(['vendor_id' => $this->vendor->id, 'party_id' => $this->customer->id]));
    }

    /** The dates hold for the charge — the day it went out — as for any expense. */
    public function test_the_dates_hold(): void
    {
        $file = $this->file([[$this->tr, $this->vendor, 3000]]);
        $this->spend($file, $this->challan, 450);

        $this->assertEqualsWithDelta(3000, $this->rows(['vendor_id' => $this->vendor->id, 'from' => '2026-09-18', 'to' => '2026-09-18'])->sum('amount'), 0.005);
        $this->assertEqualsWithDelta(450, $this->rows(['vendor_id' => $this->vendor->id, 'from' => '2026-09-19', 'to' => '2026-09-19'])->sum('amount'), 0.005);
    }

    // ------------------------------------------------------------ the page

    /** Banded by kind, their charge first, and the page says whose files these are. */
    public function test_the_page_says_whose_files_these_are(): void
    {
        $file = $this->file([[$this->tr, $this->vendor, 3000]]);
        $this->spend($file, $this->affidavit, 150);

        $json = $this->report(['vendor_id' => $this->vendor->id])->json();

        $this->assertSame('type_id', $json['props']['groupBy']);
        $this->assertSame('Vendor charge', $json['props']['rows'][0]['type_name']);
        $this->assertSame('Costs on files given to '.$this->vendor->name, $json['page']['heading']);
        $this->assertSame('Date', collect($json['props']['columns'])->firstWhere('key', 'spent_on')['label']);

        $page = $this->actingAs($this->admin)
            ->get(route('report.expenses', ['vendor_id' => $this->vendor->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Costs on files given to '.e($this->vendor->name).'</h5>', $page);
        $this->assertStringContainsString('their charge and every expense on the files they were given', $page);
        $this->assertStringContainsString('Total Cost', $page);
        $this->assertStringContainsString('name="vendor_id"', $page);
        $this->assertStringContainsString('<option value="'.$this->vendor->id.'" selected', $page);
    }

    /** Nothing to show, it says so of this vendor. */
    public function test_an_empty_list_says_why(): void
    {
        $this->assertStringContainsString(
            'files given to '.$this->vendor->name,
            $this->report(['vendor_id' => $this->vendor->id])->json('props.emptyText')
        );
    }

    /** A customer is not a vendor. */
    public function test_only_a_vendor_can_be_asked_for(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('report.expenses').'?vendor_id='.$this->customer->id)
            ->assertStatus(422);
    }

    /** Without a vendor, the report is the one it always was. */
    public function test_without_a_vendor_nothing_changes(): void
    {
        $file = $this->file([[$this->tr, $this->vendor, 3000]]);
        $this->spend($file, $this->challan, 450);

        $json = $this->report([])->json();

        $this->assertSame('type_id', $json['props']['groupBy']);
        $this->assertFalse(collect($json['props']['rows'])->contains('type_name', 'Vendor charge'));
        $this->assertSame('', $json['page']['heading']);
    }
}
