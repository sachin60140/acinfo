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
 * The Profit report adds up: its table to its heading, and each tab to the
 * others for the same dates.
 *
 * Found in a health check on 2026-10-07. A row with one file awaiting a price
 * sent a blank margin, so the table's Total row left out every priced file in
 * that row and read lower than the heading above it — on the Year tab, only
 * the discounts. The Work type tab left out every file returned to the
 * customer, so its totals were lower than every other tab's. And its heading
 * counted works and called them files.
 *
 * The files here are received in February 2032, so a period isolates them from
 * whatever else the database holds.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class ProfitReportTotalsTest extends TestCase
{
    use DatabaseTransactions;

    private const PERIOD = ['2032-02-01', '2032-02-29'];

    private User $admin;

    private PartyModel $customer;

    private PartyModel $vendor;

    private WorkTypeModel $hpt;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Profit Totals Admin';
        $this->admin->email = 'profit-totals-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer', 'Customer');
        $this->vendor = $this->party('vendor', 'Vendor');

        $this->hpt = $this->workType('HPT');
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
     * A folder and its works, each [type, charged, rate], all given to the
     * vendor; a rate of null is one not agreed yet.
     *
     * @param  array<int, array>  $works
     */
    private function folder(array $works): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-PRT-'.uniqid();
        $file->received_date = '2032-02-10';
        $file->registration_no = 'BR06PT'.random_int(1000, 9999);
        $file->work_type_id = $works[0][0]->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 0;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        foreach ($works as [$type, $charged, $rate]) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = $charged;
            $item->vendor_id = $this->vendor->id;
            $item->vendor_amount = $rate;
            $item->vendor_date = '2032-02-11';
            $item->status = WorkFileModel::DISPATCHED;
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
        $expense->spent_on = '2032-02-12';
        $expense->save();
    }

    /** Return to Customer, as the office does it, with a part refund. */
    private function giveBack(WorkFileModel $file, float $refund): void
    {
        $this->actingAs($this->admin)->post(route('workfile.customerreturn'), [
            'returned_on' => '2032-02-20',
            'files' => [$file->id],
            'amounts' => [$file->id => $refund],
            'remark' => 'Customer took the papers back',
        ])->assertRedirect(route('workfile.index'));
    }

    /** The report's page and props for one tab over the period. */
    private function tab(string $group): array
    {
        return $this->actingAs($this->admin)
            ->getJson(route('report.profit', ['group' => $group, 'from' => self::PERIOD[0], 'to' => self::PERIOD[1]]))
            ->assertOk()
            ->json();
    }

    // ------------------------------------------------------ table and heading

    /**
     * The Total row under the table is the margin at the top of the page, on
     * every tab — with a file awaiting a price in the same row as a priced one.
     */
    public function test_the_total_row_is_the_heading_on_every_tab(): void
    {
        // 3,000 at 1,200 agreed: 1,800. And 2,000 with no rate agreed yet.
        $this->folder([[$this->hpt, 3000, 1200]]);
        $this->folder([[$this->tr, 2000, null]]);

        foreach (array_keys(WorkFileModel::PROFIT_GROUPS) as $group) {
            $json = $this->tab($group);

            $this->assertEquals(1800, $json['page']['totals']['margin'], "$group: the heading's margin");
            $this->assertEqualsWithDelta(
                $json['page']['totals']['margin'],
                collect($json['props']['rows'])->sum('margin'),
                0.005,
                "$group: the Total row's margin is not the heading's"
            );
        }
    }

    /**
     * The row shows the margin of what is priced in it, and still says what
     * it left out. A percentage of it would be a ratio of two different sets
     * of files, so it stays blank.
     */
    public function test_a_row_with_a_file_awaiting_a_price_shows_what_it_has(): void
    {
        $this->folder([[$this->hpt, 3000, 1200]]);
        $this->folder([[$this->tr, 2000, null]]);

        $row = collect($this->tab('month')['props']['rows'])->firstWhere('id', '2032-02');

        $this->assertEquals(5000, $row['billed']);
        $this->assertEquals(1800, $row['margin']);
        $this->assertSame('1 awaiting a price', $row['unpriced']);
        $this->assertNull($row['rate']);
    }

    // ---------------------------------------------------- returned files

    /**
     * A file returned to the customer is on the Work type tab, on a line of its
     * own: what the office kept of the charge, and the vendor's rate that
     * stands. Every tab of the period then adds up to the same money.
     */
    public function test_the_work_type_tab_keeps_files_returned_to_the_customer(): void
    {
        $this->folder([[$this->hpt, 3000, 1200], [$this->tr, 2000, 800]]);

        // Charged 4,000, given out at 2,000, 1,500 refunded: 2,500 kept.
        $returned = $this->folder([[$this->hpt, 2500, 1000], [$this->tr, 1500, 1000]]);
        $this->spend($returned, 200);
        $this->giveBack($returned, 1500);

        $line = collect($this->tab('work_type')['props']['rows'])->firstWhere('label', 'Returned to customer');

        $this->assertNotNull($line, 'the returned file is nowhere on the Work type tab');
        $this->assertEquals(2500, $line['billed']);
        $this->assertEquals(2000, $line['cost'], 'the challan is on Counter expenses, not here');
        $this->assertEquals(500, $line['margin']);
        $this->assertSame(2, $line['files'], 'its two works');
        $this->assertNotEmpty($line['label_note'], 'nothing says why it is not under its works');

        $month = $this->tab('month')['page']['totals'];

        foreach (['customer', 'vendor', 'work_type'] as $group) {
            $totals = $this->tab($group)['page']['totals'];

            foreach (['billed', 'cost', 'margin'] as $figure) {
                $this->assertEqualsWithDelta($month[$figure], $totals[$figure], 0.005, "$group: $figure is not the Month tab's");
            }
        }

        // 7,500 billed; 2,000 + 2,000 to the vendor and 200 over the counter.
        $this->assertEquals(7500, $month['billed']);
        $this->assertEquals(4200, $month['cost']);
    }

    /** No file returned in the period, no such line. */
    public function test_a_period_with_nothing_returned_has_no_such_line(): void
    {
        $this->folder([[$this->hpt, 3000, 1200]]);

        $rows = collect($this->tab('work_type')['props']['rows']);

        $this->assertNull($rows->firstWhere('label', 'Returned to customer'));
    }

    // ------------------------------------------------- files, and works

    /**
     * The Work type tab's heading counts files, as every other tab's does;
     * its rows count works, and the note under its margin says works.
     */
    public function test_the_work_type_heading_counts_files_and_its_note_works(): void
    {
        $this->folder([[$this->hpt, 1000, 400], [$this->tr, 1000, 400], [$this->hpa, 1000, 400]]);
        $this->folder([[$this->hpt, 1000, 400], [$this->tr, 1000, null]]);

        $totals = $this->tab('work_type')['page']['totals'];

        $this->assertSame(2, $totals['files']);
        $this->assertSame($this->tab('month')['page']['totals']['files'], $totals['files']);
        $this->assertSame(5, $totals['works']);
        $this->assertSame(1, $totals['unpriced']);

        $this->actingAs($this->admin)
            ->get(route('report.profit', ['group' => 'work_type', 'from' => self::PERIOD[0], 'to' => self::PERIOD[1]]))
            ->assertOk()
            ->assertSee('2 files', false)
            ->assertSee('on 4 of 5 works &mdash; 1 awaiting a price', false)
            ->assertDontSee('of 5 files', false);
    }
}
