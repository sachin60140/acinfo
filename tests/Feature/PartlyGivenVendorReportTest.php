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
 * A folder its vendor holds only part of, on the vendor-wise Work Report.
 *
 * Asked for by the owner on 2026-09-28: F-00089's hypothecation went to a
 * vendor and its transfer to nobody, and the vendor's list said "HPT, TR". Roll-
 * up names the one vendor a folder's works went to, so the folder read as
 * theirs — all of it: its works, on the screen and on the WhatsApp list the
 * office sends them; its whole charge; and the transfer's standing, In Office,
 * as its status.
 *
 * It is drawn as a split folder is now: under the vendor, their works only.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class PartlyGivenVendorReportTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $sharma;

    private PartyModel $shailendra;

    private WorkTypeModel $hpt;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Part Given Admin';
        $this->admin->email = 'part-given-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer', 'Customer');
        $this->sharma = $this->party('vendor', 'Sharma');
        $this->shailendra = $this->party('vendor', 'Shailendra');

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
     * A folder and its works, each [type, charged, vendor or null, cost, given
     * on, status, approved on].
     *
     * @param  array<int, array>  $works
     */
    private function folder(array $works): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-PGV-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR06PG'.random_int(1000, 9999);
        $file->work_type_id = $works[0][0]->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 0;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        foreach ($works as $work) {
            [$type, $charged, $vendor, $cost, $on, $status, $approved] = $work + [3 => null, 4 => null, 5 => null, 6 => null];

            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = $charged;
            $item->vendor_id = $vendor?->id;
            $item->vendor_amount = $cost;
            $item->vendor_date = $on;
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

    /** F-00089: the hypothecation to Sharma, the transfer kept in the office. */
    private function partlyGiven(string $hptStatus = WorkFileModel::DISPATCHED, ?string $approvedOn = null): WorkFileModel
    {
        return $this->folder([
            [$this->hpt, 2000, $this->sharma, 1250, '2026-09-01', $hptStatus, $approvedOn],
            [$this->tr, 3000, null],
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
        $expense->spent_on = '2026-09-02';
        $expense->save();
    }

    /** Return to Customer, as that screen does it: every standing work goes back. */
    private function returnToCustomer(WorkFileModel $file, string $on, ?float $refund): WorkFileModel
    {
        $file->items()->where('status', '<>', WorkFileModel::CANCELLED)->update(['status' => WorkFileModel::RETURNED]);
        $file->status = WorkFileModel::RETURNED;
        $file->returned_on = $on;
        $file->returned_amount = $refund;
        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    /** Moves one work, as the board does, and rolls the folder up after it. */
    private function move(WorkFileModel $file, WorkTypeModel $type, array $fields): WorkFileModel
    {
        $file->items()->where('work_type_id', $type->id)->update($fields);
        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    /** The Work Report's rows for one file, keyed by the party they are under. */
    private function rowsFor(WorkFileModel $file, array $query = []): array
    {
        return collect($this->actingAs($this->admin)
            ->getJson(route('report.files', ['party_type' => 'vendor'] + $query))
            ->assertOk()
            ->json('props.rows'))
            ->where('id', $file->id)
            ->keyBy('party_id')
            ->all();
    }

    // ------------------------------------------------------------- the works

    public function test_the_premise_the_folder_names_the_one_vendor_it_went_to(): void
    {
        // Why it read as Sharma's, all of it.
        $this->assertSame($this->sharma->id, (int) $this->partlyGiven()->vendor_id);
    }

    public function test_the_vendor_is_shown_only_the_work_they_were_given(): void
    {
        $rows = $this->rowsFor($this->partlyGiven());

        $this->assertSame([$this->sharma->id], array_keys($rows), 'the folder is under Sharma, and nobody else');

        $row = $rows[$this->sharma->id];

        // What the screen says, and what the WhatsApp list sends him.
        $this->assertSame($this->hpt->name, $row['work_type']);

        // Nothing to say about works disagreeing: he has one.
        $this->assertNull($row['works_note']);
        $this->assertSame($this->hpt->name, $row['works_pending']);

        // And the Update button moves only his.
        $this->assertSame([$this->hpt->name], array_column($row['items'], 'work_type'));
    }

    /** Drawn once, narrowed — not once whole and once again narrowed beside it. */
    public function test_it_is_drawn_once(): void
    {
        $file = $this->partlyGiven();

        $mine = collect($this->actingAs($this->admin)
            ->getJson(route('report.files', ['party_type' => 'vendor']))
            ->assertOk()
            ->json('props.rows'))->where('id', $file->id);

        $this->assertCount(1, $mine);
        $this->assertEquals(2000, $mine->sum('billed'));
    }

    public function test_their_row_carries_only_their_works_charge_and_cost(): void
    {
        $row = $this->rowsFor($this->partlyGiven())[$this->sharma->id];

        $this->assertEquals(2000, $row['billed'], 'the transfer he never had was billed on his row');
        $this->assertEquals(1250, $row['cost']);
        $this->assertEquals(750, $row['margin']);
    }

    /**
     * The office's expenses are the file's, shared by each work's charge — and
     * the work it kept takes its share, under nobody.
     */
    public function test_the_offices_expenses_are_shared_with_the_work_it_kept(): void
    {
        $file = $this->partlyGiven();
        $this->spend($file, 1000);

        $row = $this->rowsFor($file)[$this->sharma->id];

        // 2000 of the 5000 charged is his: 40% of 1000.
        $this->assertEqualsWithDelta(400.00, $row['expenses'], 0.001);
        $this->assertEqualsWithDelta(1650.00, $row['cost'], 0.001);
    }

    /** And on a split folder with work kept in the office, as on this one. */
    public function test_a_split_folder_shares_its_expenses_with_the_office_too(): void
    {
        $file = $this->folder([
            [$this->hpt, 2000, $this->sharma, 1250, '2026-09-01'],
            [$this->hpa, 2000, $this->shailendra, 1000, '2026-09-02'],
            [$this->tr, 1000, null],
        ]);
        $this->spend($file, 500);

        $rows = $this->rowsFor($file);

        // Two fifths each, not half each.
        $this->assertEqualsWithDelta(200.00, $rows[$this->sharma->id]['expenses'], 0.001);
        $this->assertEqualsWithDelta(200.00, $rows[$this->shailendra->id]['expenses'], 0.001);
    }

    /**
     * Shared by charge, the office weighed first so the rounding falls to a
     * vendor. Found in review: weighed last, a kept work charged nothing took
     * -0.01, and the vendors' shares came to more than was spent.
     */
    public function test_the_vendors_shares_never_come_to_more_than_was_spent(): void
    {
        $file = $this->folder([
            [$this->hpt, 1000, $this->sharma, 600, '2026-09-01'],
            [$this->hpa, 1000, $this->shailendra, 600, '2026-09-02'],
            [$this->tr, 0, null],
        ]);
        $this->spend($file, 100.01);

        $rows = collect($this->rowsFor($file));

        $this->assertEqualsWithDelta(100.01, $rows->sum('expenses'), 0.0001);
    }

    /** Nothing charged yet, a vendor's share is by the works they hold. */
    public function test_with_nothing_charged_yet_the_expenses_go_by_the_works(): void
    {
        $file = $this->folder([
            [$this->hpt, 0, $this->sharma, 600, '2026-09-01'],
            [$this->tr, 0, null],
            [$this->hpa, 0, null],
        ]);
        $this->spend($file, 300);

        // One work of three, not one holder of two.
        $this->assertEqualsWithDelta(100.00, $this->rowsFor($file)[$this->sharma->id]['expenses'], 0.001);
    }

    // ---------------------------------------------------- returns and hand-backs

    /**
     * The folder goes back to its customer whole, and the refund is the
     * folder's — shared by charge, the office's kept work taking its part.
     */
    public function test_a_returned_folder_refunds_the_vendors_share_of_it(): void
    {
        $file = $this->returnToCustomer($this->partlyGiven(), '2026-09-15', 2500);

        $rows = $this->rowsFor($file, ['status' => WorkFileModel::RETURNED]);
        $row = $rows[$this->sharma->id];

        $this->assertSame(WorkFileModel::RETURNED, $row['status_key']);
        // 2000 of the 5000 charged is his: 2/5 of the 2500 refunded.
        $this->assertEquals(1000, $row['billed']);
        // Counted to the day the folder went back.
        $this->assertSame('took 14 days', $row['days_out']);
    }

    /**
     * Found in review: a folder returned, then one of its works brought back,
     * is no longer returned and its refund is gone from it and from the ledger.
     * The vendor's works still read returned — and his row billed nothing.
     */
    public function test_a_work_brought_back_after_a_return_leaves_no_refund_on_the_vendors_row(): void
    {
        $file = $this->returnToCustomer($this->partlyGiven(), '2026-09-15', null);
        $file = $this->move($file, $this->tr, ['status' => WorkFileModel::IN_OFFICE]);

        $this->assertSame(WorkFileModel::IN_OFFICE, $file->status);
        $this->assertNull($file->returned_amount);

        $row = $this->rowsFor($file)[$this->sharma->id];

        $this->assertNotSame(WorkFileModel::RETURNED, $row['status_key']);
        $this->assertEquals(2000, $row['billed'], 'a refund the ledger no longer holds');
    }

    /** He hands his work back: it is in the office, and his booking is reversed in part. */
    public function test_a_vendor_hand_back_is_theirs(): void
    {
        $file = $this->move($this->partlyGiven(), $this->hpt, [
            'status' => WorkFileModel::IN_OFFICE,
            'vendor_returned_on' => '2026-09-05',
        ]);
        $file->vendor_returned_amount = 500;
        $file->save();

        $row = $this->rowsFor($file, ['status' => WorkFileModel::IN_OFFICE])[$this->sharma->id];

        $this->assertSame(WorkFileModel::IN_OFFICE, $row['status_key']);
        $this->assertEquals(750, $row['cost']);
    }

    // ------------------------------------------------------------ the status

    public function test_their_row_stands_where_their_work_stands(): void
    {
        $file = $this->partlyGiven();

        // The folder waits on the transfer in the office...
        $this->assertSame(WorkFileModel::IN_OFFICE, $file->status);

        // ...and Sharma has had the hypothecation since the 1st.
        $row = $this->rowsFor($file)[$this->sharma->id];

        $this->assertSame(WorkFileModel::DISPATCHED, $row['status_key']);
        $this->assertSame('01-09-2026', $row['dispatched']);
    }

    public function test_once_their_work_is_approved_they_are_through(): void
    {
        $file = $this->partlyGiven(WorkFileModel::APPROVED, '2026-09-10');

        $this->assertSame(WorkFileModel::PARTLY_APPROVED, $file->status);

        $row = $this->rowsFor($file)[$this->sharma->id];

        $this->assertSame(WorkFileModel::APPROVED, $row['status_key']);
        // Counted to his approval, not running on while the office works.
        $this->assertSame('took 9 days', $row['days_out']);
    }

    /**
     * The views are asked of his work: approved, it is off the list of what
     * is still with him — the list the office chases him with.
     */
    public function test_the_views_follow_their_work_not_the_folder(): void
    {
        $approved = $this->partlyGiven(WorkFileModel::APPROVED, '2026-09-10');
        $out = $this->partlyGiven();

        $this->assertSame([], $this->rowsFor($approved, ['status' => 'open']), 'his finished work is still chased');
        $this->assertArrayHasKey($this->sharma->id, $this->rowsFor($approved, ['status' => WorkFileModel::APPROVED]));

        $this->assertSame([], $this->rowsFor($out, ['status' => WorkFileModel::IN_OFFICE]), 'work with him reads as in the office');
        $this->assertArrayHasKey($this->sharma->id, $this->rowsFor($out, ['status' => 'open']));
    }

    /**
     * On a folder split between two vendors too, which read the folder's
     * status before: one vendor's approved work is off the Still pending list
     * the other's is still on.
     */
    public function test_on_a_split_folder_each_vendor_stands_where_their_work_does(): void
    {
        $file = $this->folder([
            [$this->tr, 3000, $this->sharma, 1800, '2026-09-01', WorkFileModel::APPROVED, '2026-09-10'],
            [$this->hpa, 2000, $this->shailendra, 1200, '2026-09-02'],
        ]);

        $this->assertSame(WorkFileModel::PARTLY_APPROVED, $file->status);

        $rows = $this->rowsFor($file);

        $this->assertSame(WorkFileModel::APPROVED, $rows[$this->sharma->id]['status_key']);
        $this->assertSame('took 9 days', $rows[$this->sharma->id]['days_out']);
        $this->assertSame(WorkFileModel::DISPATCHED, $rows[$this->shailendra->id]['status_key']);

        $this->assertSame([$this->shailendra->id], array_keys($this->rowsFor($file, ['status' => 'open'])));
        $this->assertSame([$this->sharma->id], array_keys($this->rowsFor($file, ['status' => WorkFileModel::APPROVED])));
        $this->assertSame([], $this->rowsFor($file, ['status' => WorkFileModel::PARTLY_APPROVED]));
    }

    public function test_narrowed_to_the_vendor_it_is_still_theirs(): void
    {
        $rows = $this->rowsFor($this->partlyGiven(), ['party_id' => $this->sharma->id]);

        $this->assertSame([$this->sharma->id], array_keys($rows));
        $this->assertSame($this->hpt->name, $rows[$this->sharma->id]['work_type']);

        $this->assertSame([], $this->rowsFor($this->partlyGiven(), ['party_id' => $this->shailendra->id]));
    }

    // ---------------------------------------------------------- the edges

    /**
     * Its one given work cancelled, the folder still names the vendor — no
     * live work carries one to say otherwise. It is not an older file, all of
     * it theirs: what is left was kept in the office.
     */
    public function test_a_cancelled_given_work_leaves_nothing_under_the_vendor(): void
    {
        $file = $this->partlyGiven();

        // Given, then struck off.
        $file->items()->where('vendor_id', $this->sharma->id)->update(['status' => WorkFileModel::CANCELLED]);
        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        $this->assertSame($this->sharma->id, (int) $file->fresh()->vendor_id);
        $this->assertSame([], $this->rowsFor($file), 'the transfer kept in the office went on his list');
    }

    /** A folder from before works carried a vendor is its vendor's, all of it. */
    public function test_an_older_folder_is_still_all_its_vendors(): void
    {
        $file = $this->folder([[$this->hpt, 2000, null], [$this->tr, 3000, null]]);
        $file->vendor_id = $this->sharma->id;
        $file->vendor_amount = 2500;
        $file->vendor_date = '2026-09-01';
        $file->save();

        $row = $this->rowsFor($file)[$this->sharma->id];

        $this->assertEquals(5000, $row['billed']);
        $this->assertCount(2, $row['items']);
    }

    /** Given wholly to one vendor, it is drawn as it always was. */
    public function test_a_folder_given_whole_is_unchanged(): void
    {
        $file = $this->folder([
            [$this->hpt, 2000, $this->sharma, 1250, '2026-09-01'],
            [$this->tr, 3000, $this->sharma, 1500, '2026-09-01'],
        ]);
        $this->spend($file, 1000);

        $row = $this->rowsFor($file)[$this->sharma->id];

        $this->assertEquals(5000, $row['billed']);
        $this->assertEqualsWithDelta(1000.00, $row['expenses'], 0.001);
        $this->assertCount(2, $row['items']);
    }

    /**
     * A work struck off is not a work given to nobody: the folder is still its
     * vendor's, all of it, and drawn so — once, its cancelled work named.
     */
    public function test_a_whole_folder_with_a_cancelled_work_is_unchanged(): void
    {
        $file = $this->folder([
            [$this->hpt, 2000, $this->sharma, 1250, '2026-09-01'],
            [$this->tr, 3000, null, null, null, WorkFileModel::CANCELLED],
        ]);

        $mine = collect($this->actingAs($this->admin)
            ->getJson(route('report.files', ['party_type' => 'vendor']))
            ->assertOk()
            ->json('props.rows'))->where('id', $file->id)->values();

        $this->assertCount(1, $mine);
        $this->assertCount(2, $mine[0]['items']);
        $this->assertStringContainsString($this->tr->name.' cancelled', (string) $mine[0]['works_note']);
    }

    public function test_the_customer_report_still_draws_it_whole(): void
    {
        $file = $this->partlyGiven();

        $mine = collect($this->actingAs($this->admin)
            ->getJson(route('report.files', ['party_type' => 'customer']))
            ->assertOk()
            ->json('props.rows'))->where('id', $file->id)->values();

        $this->assertCount(1, $mine);
        $this->assertEquals(5000, $mine[0]['billed']);
        $this->assertCount(2, $mine[0]['items']);
    }

    // ------------------------------------------------------ Approval Time

    /**
     * Approval Time shares the report and counts approved files, as the owner
     * chose. His part approved on a folder still at work is not yet counted —
     * and once the folder is through, its Work names his works only.
     */
    public function test_approval_time_still_counts_approved_files_naming_their_works(): void
    {
        $open = $this->partlyGiven(WorkFileModel::APPROVED, '2026-09-10');
        $through = $this->folder([
            [$this->hpt, 2000, $this->sharma, 1250, '2026-09-01', WorkFileModel::APPROVED, '2026-09-10'],
            [$this->tr, 3000, null, null, null, WorkFileModel::APPROVED, '2026-09-20'],
        ]);

        $rows = collect($this->actingAs($this->admin)
            ->getJson(route('report.approvaltime', ['party_type' => 'vendor', 'party_id' => $this->sharma->id]))
            ->assertOk()
            ->json('props.rows'))->keyBy('file_no');

        $this->assertArrayNotHasKey($open->file_no, $rows->all());

        $this->assertSame($this->hpt->name, $rows[$through->file_no]['work_type']);
        $this->assertSame(9, $rows[$through->file_no]['days']);
    }
}
