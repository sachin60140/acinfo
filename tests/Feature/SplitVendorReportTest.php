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
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A folder split between two vendors, on the vendor-wise Work Report.
 *
 * The report asked each folder for its own vendor, and a split folder has none
 * — roll-up clears it rather than name one and put the other's work on his
 * statement. So it appeared under nobody. A vendor holding one work of it
 * found it missing from their list, and from the list the office now sends
 * them on WhatsApp to chase with.
 *
 * It is drawn once under each vendor now, narrowed to that vendor's works. The
 * property that matters most is that the report's totals still count it once.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class SplitVendorReportTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $sharma;

    private PartyModel $shailendra;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Split Report Admin';
        $this->admin->email = 'split-report-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer', 'Customer');
        $this->sharma = $this->party('vendor', 'Sharma');
        $this->shailendra = $this->party('vendor', 'Shailendra');

        $this->tr = $this->workType('TR');
        $this->hpa = $this->workType('HPA');
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
     * A folder and its works: [type, charged, vendor, cost, dispatched on].
     *
     * @param  array<int, array{0: WorkTypeModel, 1: float, 2: PartyModel, 3: float, 4: string}>  $works
     */
    private function folder(array $works): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-SVR-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01SV'.random_int(1000, 9999);
        $file->work_type_id = $works[0][0]->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 0;
        $file->status = WorkFileModel::DISPATCHED;
        $file->save();

        foreach ($works as [$type, $charged, $vendor, $cost, $on]) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = $charged;
            $item->vendor_id = $vendor->id;
            $item->vendor_amount = $cost;
            $item->vendor_date = $on;
            $item->status = WorkFileModel::DISPATCHED;
            $item->save();
        }

        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    /** Transfer to Sharma, hypothecation addition to Shailendra. */
    private function split(): WorkFileModel
    {
        return $this->folder([
            [$this->tr, 3000, $this->sharma, 1800, '2026-09-05'],
            [$this->hpa, 2000, $this->shailendra, 1200, '2026-09-08'],
        ]);
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
        $expense->spent_on = '2026-09-06';
        $expense->save();
    }

    /** The vendor-wise report's rows for one file, keyed by vendor. */
    private function rowsFor(WorkFileModel $file, array $query = []): array
    {
        $html = $this->actingAs($this->admin)
            ->get(route('report.files', ['party_type' => 'vendor'] + $query))
            ->assertOk()->getContent();

        preg_match('#data-vue="vue-work-report" data-props="(.*?)"></div>#s', $html, $mount);
        $props = json_decode(html_entity_decode($mount[1] ?? '{}', ENT_QUOTES, 'UTF-8'), true);

        return collect($props['rows'] ?? [])
            ->where('id', $file->id)
            ->keyBy('party_id')
            ->all();
    }

    // ------------------------------------------------------------- appearing

    public function test_a_split_folder_has_no_vendor_of_its_own(): void
    {
        // The premise, pinned: this is why the report could not see it.
        $this->assertNull($this->split()->vendor_id);
    }

    public function test_it_appears_under_every_vendor_holding_part_of_it(): void
    {
        $rows = $this->rowsFor($this->split());

        $this->assertArrayHasKey($this->sharma->id, $rows, 'Sharma lost his half of the folder');
        $this->assertArrayHasKey($this->shailendra->id, $rows, 'Shailendra lost his half of the folder');
        $this->assertCount(2, $rows);
    }

    public function test_each_vendor_sees_only_their_own_works(): void
    {
        $rows = $this->rowsFor($this->split());

        $this->assertSame($this->tr->name, $rows[$this->sharma->id]['work_type']);
        $this->assertSame($this->hpa->name, $rows[$this->shailendra->id]['work_type']);

        // And the Update button moves only theirs.
        $this->assertSame([$this->tr->name], array_column($rows[$this->sharma->id]['items'], 'work_type'));
        $this->assertSame([$this->hpa->name], array_column($rows[$this->shailendra->id]['items'], 'work_type'));
    }

    public function test_each_row_is_dated_from_that_vendors_own_dispatch(): void
    {
        $rows = $this->rowsFor($this->split());

        $this->assertSame('05-09-2026', $rows[$this->sharma->id]['dispatched']);
        $this->assertSame('08-09-2026', $rows[$this->shailendra->id]['dispatched']);
    }

    // ------------------------------------------------------------------ money

    public function test_each_vendor_row_carries_their_own_charge_and_cost(): void
    {
        $rows = $this->rowsFor($this->split());

        $this->assertEquals(3000, $rows[$this->sharma->id]['billed']);
        $this->assertEquals(1800, $rows[$this->sharma->id]['cost']);
        $this->assertEquals(1200, $rows[$this->sharma->id]['margin']);

        $this->assertEquals(2000, $rows[$this->shailendra->id]['billed']);
        $this->assertEquals(1200, $rows[$this->shailendra->id]['cost']);
        $this->assertEquals(800, $rows[$this->shailendra->id]['margin']);
    }

    /**
     * The property that matters most. Drawn under two vendors, the folder must
     * still be counted once — the two halves add up to the folder, not to twice
     * it.
     */
    public function test_a_split_folder_is_counted_once_in_the_totals(): void
    {
        $rows = collect($this->rowsFor($this->split()));

        $this->assertEquals(5000, $rows->sum('billed'), 'the folder was billed twice');
        $this->assertEquals(3000, $rows->sum('cost'), 'the folder was costed twice');
        $this->assertEquals(2000, $rows->sum('margin'));
    }

    /**
     * The office's own expenses belong to the file, not to either vendor, so
     * they are shared in proportion to each vendor's charge — and the shares add
     * back up to the paisa, however awkward the amount.
     */
    public function test_expenses_are_shared_in_proportion_and_add_up_exactly(): void
    {
        $file = $this->split();
        $this->spend($file, 1001);

        $rows = $this->rowsFor($file);

        // 3000 : 2000 of the charge, so 60% and 40% of 1001.
        $this->assertEqualsWithDelta(600.60, $rows[$this->sharma->id]['expenses'], 0.001);
        $this->assertEqualsWithDelta(400.40, $rows[$this->shailendra->id]['expenses'], 0.001);

        $this->assertEqualsWithDelta(1001.00, collect($rows)->sum('expenses'), 0.001, 'the expense was not shared out whole');

        // And the cost, which includes them, still totals the folder's once.
        $this->assertEqualsWithDelta(4001.00, collect($rows)->sum('cost'), 0.001);
    }

    // -------------------------------------------------------------- narrowed

    public function test_narrowed_to_one_vendor_it_shows_only_their_share(): void
    {
        $rows = $this->rowsFor($this->split(), ['party_id' => $this->shailendra->id]);

        $this->assertSame([$this->shailendra->id], array_keys($rows));
        $this->assertEquals(2000, $rows[$this->shailendra->id]['billed']);
    }

    /** The WhatsApp button on Shailendra's band now has his half of the folder to send. */
    public function test_the_vendors_number_rides_on_their_half(): void
    {
        $rows = $this->rowsFor($this->split());

        $this->assertSame($this->shailendra->mobile, $rows[$this->shailendra->id]['party_mobile']);
    }

    // ------------------------------------------------------ nothing else moved

    /** A folder with one vendor is drawn exactly as it always was: whole. */
    public function test_a_folder_with_one_vendor_is_unchanged(): void
    {
        $file = $this->folder([
            [$this->tr, 3000, $this->sharma, 1800, '2026-09-05'],
            [$this->hpa, 2000, $this->sharma, 1200, '2026-09-08'],
        ]);

        $rows = $this->rowsFor($file);

        $this->assertCount(1, $rows);

        $row = $rows[$this->sharma->id];

        $this->assertEquals(5000, $row['billed']);
        $this->assertEquals(3000, $row['cost']);
        $this->assertCount(2, $row['items']);
        $this->assertStringContainsString($this->tr->name, $row['work_type']);
        $this->assertStringContainsString($this->hpa->name, $row['work_type']);
    }

    /** The customer-wise report was never missing it, and still draws it once and whole. */
    public function test_the_customer_report_still_draws_it_once_and_whole(): void
    {
        $file = $this->split();

        $html = $this->actingAs($this->admin)
            ->get(route('report.files', ['party_type' => 'customer']))
            ->assertOk()->getContent();

        preg_match('#data-vue="vue-work-report" data-props="(.*?)"></div>#s', $html, $mount);
        $props = json_decode(html_entity_decode($mount[1], ENT_QUOTES, 'UTF-8'), true);

        $mine = collect($props['rows'])->where('id', $file->id)->values();

        $this->assertCount(1, $mine);
        $this->assertEquals(5000, $mine[0]['billed']);
        $this->assertCount(2, $mine[0]['items']);
    }
}
