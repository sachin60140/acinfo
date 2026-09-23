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
 * Everything one vehicle's work cost.
 *
 * Asked by registration number — a number plate is what anybody asking has in
 * front of them. The Expense Report then answers file by file with the vendor's
 * charge beside every expense paid out on it, and the total. Costs only: what
 * the customer was charged, and the margin, are the Profit report's question.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class ExpenseByVehicleTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $vendor;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpa;

    private int $challan;

    private int $affidavit;

    /** A vehicle made up for this test, so no real file answers for it. */
    private string $plate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Costs Admin';
        $this->admin->email = 'costs-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer', 'Rakesh Ji Madhubani');
        $this->vendor = $this->party('vendor', 'Parwez Ji Muzaffarpur');

        $this->tr = $this->type('TR');
        $this->hpa = $this->type('HPA');

        $this->challan = $this->kind('Challan');
        $this->affidavit = $this->kind('Affidavit');

        $this->plate = 'BR05ZQ'.random_int(1000, 9999);
    }

    private function party(string $type, string $name): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = $name.' '.uniqid();
        $party->mobile = '93800'.random_int(10000, 99999);
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

    /** A folder of two works — TR charged 5,000, HPA 2,500 — each with a vendor's rate. */
    private function file(string $vehicle, float $trRate = 3000, float $hpaRate = 1200): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-EV-'.uniqid();
        $file->received_date = '2026-09-15';
        $file->registration_no = $vehicle;
        $file->work_type_id = $this->tr->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 7500;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        foreach ([[$this->tr, 5000, $trRate], [$this->hpa, 2500, $hpaRate]] as [$type, $charge, $rate]) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = $charge;
            $item->vendor_id = $this->vendor->id;
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

    private function spend(WorkFileModel $file, int $kind, float $amount, string $what = '', string $on = '2026-09-19'): void
    {
        DB::table('work_file_expense')->insert([
            'work_file_id' => $file->id,
            'expense_type_id' => $kind,
            'amount' => $amount,
            'spent_on' => $on,
            'remark' => $what ?: null,
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

    private function page(array $query): string
    {
        return $this->actingAs($this->admin)
            ->get(route('report.expenses', $query))
            ->assertOk()->getContent();
    }

    /** What the rest of the app says the file cost: the Profit report and the file screen. */
    private function spent(WorkFileModel $file): float
    {
        return (float) WorkFileModel::whereKey($file->id)->selectRaw(WorkFileModel::SPENT.' as spent')->value('spent');
    }

    /** The vendor hands the folder back undone, as the vendor-return screen records it. */
    private function handBack(WorkFileModel $file, ?float $reversed, string $on = '2026-09-20'): void
    {
        $file->items()->update(['vendor_returned_on' => $on, 'status' => WorkFileModel::IN_OFFICE]);
        $file->vendor_returned_amount = $reversed;
        $file->rollUp();
        $file->save();
        $file->syncLedger();
    }

    // ------------------------------------------------------------- the point

    /** The vendor's charge and every expense, on that vehicle, and what they came to. */
    public function test_a_vehicle_shows_everything_its_work_cost(): void
    {
        $file = $this->file($this->plate);
        $this->spend($file, $this->challan, 450, 'RTO challan');
        $this->spend($file, $this->affidavit, 150);

        $props = $this->report(['vehicle' => $this->plate])->json('props');
        $rows = collect($props['rows']);

        // Two works' rates and two expenses: 3,000 + 1,200 + 450 + 150.
        $this->assertCount(4, $rows);
        $this->assertEqualsWithDelta(4800, $rows->sum('amount'), 0.005);

        $charges = $rows->where('type_name', 'Vendor charge');
        $this->assertCount(2, $charges);
        $this->assertEqualsWithDelta(4200, $charges->sum('amount'), 0.005);
        $this->assertTrue($charges->contains(fn ($row) => str_contains($row['remark'], $this->tr->name) && str_contains($row['remark'], $this->vendor->name)));

        // Banded by file, so each band subtotals what that file cost.
        $this->assertSame('file_id', $props['groupBy']);
        $this->assertSame([$file->id], $rows->pluck('file_id')->unique()->values()->all());
    }

    public function test_it_is_that_vehicle_and_no_other(): void
    {
        $mine = $this->file($this->plate);
        $theirs = $this->file('BR01ZZ0001', 9999, 8888);
        $this->spend($theirs, $this->challan, 777);

        $rows = collect($this->report(['vehicle' => $this->plate])->json('props.rows'));

        $this->assertSame([$mine->id], $rows->pluck('file_id')->unique()->values()->all());
        $this->assertFalse($rows->contains('amount', 9999.0));
        $this->assertFalse($rows->contains('amount', 777.0));
    }

    /** Typed however it is typed: stored without spaces or dashes, matched the same way. */
    public function test_the_number_is_found_however_it_is_typed(): void
    {
        $file = $this->file($this->plate);

        // Lower case with spaces and a dash, whole, and the end of it.
        $loose = strtolower(substr($this->plate, 0, 4)).' '.strtolower(substr($this->plate, 4, 2)).'-'.substr($this->plate, 6);

        foreach ([$loose, $this->plate, substr($this->plate, 4)] as $typed) {
            $rows = collect($this->report(['vehicle' => $typed])->json('props.rows'));

            $this->assertSame([$file->id], $rows->pluck('file_id')->unique()->values()->all(), $typed);
        }
    }

    /** Costs only: what the customer was charged is not a cost. */
    public function test_it_says_nothing_of_what_the_customer_was_charged(): void
    {
        $this->file($this->plate);

        $said = $this->report(['vehicle' => $this->plate])->getContent();

        $this->assertStringNotContainsString('7500', $said);
        $this->assertStringNotContainsString('7,500', $said);
        $this->assertStringNotContainsString('"5000', $said);
    }

    /** Work struck off charges nobody, and cost nobody either. */
    public function test_a_work_struck_off_is_not_a_cost(): void
    {
        $file = $this->file($this->plate);
        $file->items()->where('work_type_id', $this->hpa->id)->update(['status' => WorkFileModel::CANCELLED]);

        $rows = collect($this->report(['vehicle' => $this->plate])->json('props.rows'));

        $this->assertEqualsWithDelta(3000, $rows->sum('amount'), 0.005);
        $this->assertFalse($rows->contains(fn ($row) => str_contains((string) $row['remark'], $this->hpa->name)));
    }

    /** A kind of expense asked for is a kind of expense: the vendor's charge is not one. */
    public function test_a_kind_asked_for_leaves_the_vendors_charge_out(): void
    {
        $file = $this->file($this->plate);
        $this->spend($file, $this->challan, 450);
        $this->spend($file, $this->affidavit, 150);

        $rows = collect($this->report(['vehicle' => $this->plate, 'expense_type_id' => $this->challan])->json('props.rows'));

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(450, $rows->sum('amount'), 0.005);
    }

    /** Without a vehicle, the report is the one it always was. */
    public function test_without_a_vehicle_nothing_changes(): void
    {
        $file = $this->file($this->plate);
        $this->spend($file, $this->challan, 450);

        $props = $this->report([])->json('props');

        $this->assertSame('type_id', $props['groupBy']);
        $this->assertFalse(collect($props['rows'])->contains('type_name', 'Vendor charge'));
    }

    /** The page says what it is showing. */
    public function test_the_page_says_it_is_one_vehicles_costs(): void
    {
        $file = $this->file($this->plate);
        $this->spend($file, $this->challan, 450);

        $page = $this->actingAs($this->admin)
            ->get(route('report.expenses', ['vehicle' => $this->plate]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Costs on '.$this->plate, $page);
        $this->assertStringContainsString('Total Cost', $page);
        $this->assertStringContainsString('value="'.$this->plate.'"', $page);
    }

    // ------------------------------------------------ found in review (#32)

    /**
     * Handed back undone, the vendor's rate is reversed: the report nets it
     * off exactly as the Profit report and the file's screen do, on a row of
     * its own so the reader sees why.
     */
    public function test_a_file_handed_back_whole_costs_what_the_rest_of_the_app_says(): void
    {
        $file = $this->file($this->plate);
        $this->spend($file, $this->challan, 450);
        $this->handBack($file, null);

        $rows = collect($this->report(['vehicle' => $this->plate])->json('props.rows'));

        $this->assertEqualsWithDelta(450, $this->spent($file), 0.005);
        $this->assertEqualsWithDelta($this->spent($file), $rows->sum('amount'), 0.005);

        $back = $rows->firstWhere('amount', -4200.0);
        $this->assertNotNull($back);
        $this->assertSame('20-09-2026', $back['spent_on']);
        $this->assertStringContainsString('Handed back', $back['remark']);

        // A reversal is netted into the charge's tile, not counted as another time.
        $page = $this->page(['vehicle' => $this->plate]);
        $this->assertStringContainsString('2 times', $page);
        $this->assertStringNotContainsString('3 times', $page);
    }

    public function test_a_part_reversal_leaves_the_rest_of_the_rate_as_a_cost(): void
    {
        $file = $this->file($this->plate);
        $this->spend($file, $this->challan, 450);
        $this->handBack($file, 1000);

        $rows = collect($this->report(['vehicle' => $this->plate])->json('props.rows'));

        // 3,000 + 1,200 − 1,000 + 450.
        $this->assertEqualsWithDelta(3650, $this->spent($file), 0.005);
        $this->assertEqualsWithDelta($this->spent($file), $rows->sum('amount'), 0.005);
    }

    /** The dates asked for hold for the vendor's charge as for any expense. */
    public function test_the_dates_hold_for_the_vendors_charge_too(): void
    {
        $file = $this->file($this->plate);
        $this->spend($file, $this->challan, 450);

        // Given out on the 18th, the challan paid on the 19th.
        $rows = collect($this->report(['vehicle' => $this->plate, 'from' => '2026-01-01', 'to' => '2026-01-31'])->json('props.rows'));
        $this->assertCount(0, $rows);

        $rows = collect($this->report(['vehicle' => $this->plate, 'from' => '2026-09-19', 'to' => '2026-09-19'])->json('props.rows'));
        $this->assertEqualsWithDelta(450, $rows->sum('amount'), 0.005);

        $rows = collect($this->report(['vehicle' => $this->plate, 'from' => '2026-09-18', 'to' => '2026-09-18'])->json('props.rows'));
        $this->assertEqualsWithDelta(4200, $rows->sum('amount'), 0.005);

        // Handed back on the 20th: not yet, in a period that ends before it.
        $this->handBack($file, null);
        $rows = collect($this->report(['vehicle' => $this->plate, 'to' => '2026-09-19'])->json('props.rows'));
        $this->assertEqualsWithDelta(4650, $rows->sum('amount'), 0.005);
    }

    /** A folder with no works carries its vendor's rate on itself. */
    public function test_a_folder_with_no_works_shows_its_rate(): void
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-EV-'.uniqid();
        $file->received_date = '2026-09-15';
        $file->registration_no = $this->plate;
        $file->work_type_id = $this->tr->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 5000;
        $file->vendor_id = $this->vendor->id;
        $file->vendor_amount = 2000;
        $file->vendor_date = '2026-09-17';
        $file->status = WorkFileModel::DISPATCHED;
        $file->save();

        // Cancelled, a folder charges nobody and costs nothing.
        $struck = $file->replicate();
        $struck->file_no = 'F-EV-'.uniqid();
        $struck->status = WorkFileModel::CANCELLED;
        $struck->save();

        $rows = collect($this->report(['vehicle' => $this->plate])->json('props.rows'));

        $this->assertCount(1, $rows);
        $this->assertSame('17-09-2026', $rows->first()['spent_on']);
        $this->assertStringContainsString($this->vendor->name, $rows->first()['remark']);
        $this->assertEqualsWithDelta($this->spent($file), $rows->sum('amount'), 0.005);
    }

    /** A customer asked for is a customer: another's vehicle charges are not theirs. */
    public function test_a_customer_asked_for_leaves_other_customers_charges_out(): void
    {
        $this->file($this->plate);
        $other = $this->party('customer', 'Somebody Else');

        $rows = collect($this->report(['vehicle' => $this->plate, 'party_id' => $other->id])->json('props.rows'));

        $this->assertCount(0, $rows);
    }

    /** BR05AS632 is a vehicle, and BR05AS6321 another one that contains it. */
    public function test_a_whole_number_finds_that_vehicle_and_not_a_longer_one(): void
    {
        $short = substr($this->plate, 0, -1);
        $mine = $this->file($short);
        $this->file($this->plate, 9999, 8888);

        $rows = collect($this->report(['vehicle' => $short])->json('props.rows'));

        $this->assertSame([$mine->id], $rows->pluck('file_id')->unique()->values()->all());
    }

    /** Part of a number can find two vehicles, and the page says so rather than adding them up as one. */
    public function test_part_of_a_number_says_how_many_vehicles_it_found(): void
    {
        $tail = substr($this->plate, 4);
        $twin = 'JH01'.$tail;
        $this->file($this->plate);
        $this->file($twin);

        $json = $this->report(['vehicle' => $tail]);

        $this->assertSame('Costs on 2 vehicles matching '.$tail, $json->json('page.heading'));
        $this->assertSame([$this->plate, $twin], collect($json->json('page.plates'))->sort()->values()->all());

        // In the heading, and the two named under it (the grid's own data
        // carries each plate on its own, never the two together).
        $page = $this->page(['vehicle' => $tail]);
        $this->assertStringContainsString('Costs on 2 vehicles matching '.$tail.'</h5>', $page);
        $this->assertStringContainsString($this->plate.', '.$twin, $page);
    }

    /** Found by part of its number, one vehicle is named by the whole of it. */
    public function test_one_vehicle_found_is_named_in_full(): void
    {
        $this->file($this->plate);

        $json = $this->report(['vehicle' => substr($this->plate, 4)]);

        $this->assertSame('Costs on '.$this->plate, $json->json('page.heading'));
        $this->assertSame('Date', collect($json->json('props.columns'))->firstWhere('key', 'spent_on')['label']);
    }

    /** Under a kind the vendor's charge is left out, and the page does not call what is left the cost. */
    public function test_under_a_kind_the_page_does_not_claim_the_whole_cost(): void
    {
        $file = $this->file($this->plate);
        $this->spend($file, $this->challan, 450);

        $page = $this->page(['vehicle' => $this->plate, 'expense_type_id' => $this->challan]);

        $this->assertStringNotContainsString('Total Cost', $page);
        $this->assertStringNotContainsString('Costs on', $page);
        $this->assertStringContainsString('Paid Out', $page);
        $this->assertStringContainsString('left out when a kind is chosen', $page);
    }

    /** File by file, and in each file in the order it happened — the export has no bands to sort it. */
    public function test_rows_come_file_by_file_in_the_order_they_happened(): void
    {
        $first = $this->file($this->plate);
        $second = $this->file($this->plate);
        // By kind, the second file's affidavit would lead the list.
        $this->spend($second, $this->affidavit, 150);
        $this->spend($first, $this->challan, 450, '', '2026-09-19');
        // Paid the day the work went out: the charge still reads first.
        $this->spend($first, $this->challan, 50, '', '2026-09-18');

        $rows = collect($this->report(['vehicle' => $this->plate])->json('props.rows'));

        $this->assertSame([$first->id, $first->id, $first->id, $first->id, $second->id, $second->id, $second->id], $rows->pluck('file_id')->all());
        $this->assertSame(['Vendor charge', 'Vendor charge'], $rows->take(2)->pluck('type_name')->all());
        $this->assertEquals([50, 450], $rows->slice(2, 2)->pluck('amount')->values()->all());
    }

    /** The empty list says why it is empty. */
    public function test_an_empty_list_says_why(): void
    {
        $this->file($this->plate);

        $mistyped = $this->report(['vehicle' => 'BR05QZ'.substr($this->plate, 6).'X'])->json('props.emptyText');
        $this->assertStringContainsString('No file has a vehicle number', $mistyped);

        $underKind = $this->report(['vehicle' => $this->plate, 'expense_type_id' => $this->challan])->json('props.emptyText');
        $this->assertStringContainsString('Nothing on '.$this->plate.' matches this report', $underKind);
    }
}
