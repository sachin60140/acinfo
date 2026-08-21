<?php

namespace Tests\Feature;

use App\Models\PartyModel;
use App\Models\User;
use App\Models\PartyLedgerModel;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The work a file is for, as rows.
 *
 * Papers come in for several jobs at once and each is approved separately —
 * a hypothecation addition can come back approved while a transfer is still
 * pending days later. A file with one work type and one status could describe
 * neither, so the work moved onto its own rows.
 *
 * This is the first step and it is deliberately invisible: every file has
 * exactly one item saying what the file already said. These assert that, and
 * that it stays true — because until the screens move over, a file edited
 * without its item following would leave the two disagreeing about what the
 * job is.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class WorkFileItemTest extends TestCase
{
    use DatabaseTransactions;

    private WorkTypeModel $workType;

    private PartyModel $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workType = new WorkTypeModel;
        $this->workType->name = 'Item Work '.uniqid();
        $this->workType->is_active = 1;
        $this->workType->save();

        $this->customer = new PartyModel;
        $this->customer->party_type = 'customer';
        $this->customer->name = 'Item Customer '.uniqid();
        $this->customer->mobile = '9333300001';
        $this->customer->is_active = 1;
        $this->customer->save();
    }

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Item Admin';
        $user->email = 'item-admin-'.uniqid().'@example.com';
        $user->password = Hash::make('password-for-tests');
        $user->user_type = 1;
        $user->save();

        return $user;
    }

    private function file(float $charged = 5000, string $status = 'in_office'): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-ITEM-'.uniqid();
        $file->received_date = now()->toDateString();
        $file->work_type_id = $this->workType->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = $charged;
        $file->status = $status;
        $file->save();

        /*
         * The job the papers are for, written the way receive() writes it. A
         * file saved straight to the database has no items of its own — only
         * receiving creates them, and nothing invents one on its behalf.
         */
        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $file->work_type_id;
        $item->customer_amount = $charged;
        $item->status = $status;
        $item->save();

        return $file->refresh();
    }

    public function test_receiving_a_file_gives_it_an_item(): void
    {
        $file = $this->file();

        $this->assertCount(1, $file->items()->get());

        $item = $file->items()->first();

        $this->assertSame($this->workType->id, (int) $item->work_type_id);
        $this->assertEquals(5000, $item->customer_amount);
        $this->assertSame('in_office', $item->status);
    }

    /**
     * The jobs are the record of what a file is for, so a correction made on
     * the edit screen has to reach them — otherwise the folder and its work
     * disagree, and the board would show the job the file used to be.
     */
    public function test_correcting_a_file_reaches_its_job(): void
    {
        $this->actingAs($this->admin());

        $file = $this->file();
        $other = new WorkTypeModel;
        $other->name = 'Other Work '.uniqid();
        $other->is_active = 1;
        $other->save();

        $this->post(route('workfile.edit', $file->id), [
            'file_no' => $file->file_no,
            'received_date' => $file->received_date,
            'work_type_id' => $other->id,
            'customer_id' => $this->customer->id,
            'customer_amount' => '7500',
            'status' => 'under_verification',
        ])->assertRedirect();

        $item = $file->items()->first();

        $this->assertSame($other->id, (int) $item->work_type_id);
        $this->assertEquals(7500, $item->customer_amount);
        $this->assertSame('under_verification', $item->status);
    }

    public function test_a_file_never_grows_a_second_item_from_being_saved_twice(): void
    {
        $file = $this->file();

        $file->customer_amount = 6000;
        $file->save();
        $file->customer_amount = 6500;
        $file->save();

        $this->assertSame(1, $file->items()->count(), 'saving updates the item rather than adding one');
    }

    /**
     * Every file, not just new ones. The migration filled the table from what
     * was already there, and a file without an item is one the next step would
     * read as having no work on it at all.
     */
    public function test_no_file_anywhere_is_without_an_item(): void
    {
        $orphans = DB::table('work_file')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('work_file_item')
                ->whereColumn('work_file_item.work_file_id', 'work_file.id'))
            ->count();

        $this->assertSame(0, $orphans, 'every file must describe what it is for');
    }

    /**
     * Approval is dated the moment it is recorded, because part approval means
     * two approvals arriving days apart and "when" is the whole question.
     */
    public function test_an_approved_item_is_dated_and_an_undone_one_is_not(): void
    {
        $file = $this->file();
        $item = $file->items()->first();

        $item->status = WorkFileModel::APPROVED;
        $item->save();

        $this->assertNotNull($item->approved_on);
        $this->assertSame(now()->toDateString(), (string) $item->approved_on);

        $item->status = 'under_verification';
        $item->save();

        $this->assertNull($item->approved_on, 'no longer approved, so no approval date');
    }

    public function test_an_item_knows_when_nothing_further_is_expected_of_it(): void
    {
        $item = new WorkFileItemModel;

        foreach ([WorkFileModel::APPROVED, WorkFileModel::RETURNED, WorkFileModel::CANCELLED] as $settled) {
            $item->status = $settled;
            $this->assertTrue($item->isSettled(), "$settled is finished");
        }

        foreach (WorkFileModel::OPEN_STATUSES as $open) {
            $item->status = $open;
            $this->assertFalse($item->isSettled(), "$open is still in hand");
        }
    }

    /**
     * Deleting a file takes its items with it. They describe that file and
     * nothing else, and rows pointing at a file that is gone would be counted
     * by anything that later sums work across items.
     */
    public function test_items_go_when_their_file_goes(): void
    {
        $file = $this->file();
        $id = $file->id;

        $file->delete();

        $this->assertSame(0, WorkFileItemModel::where('work_file_id', $id)->count());
    }
    /**
     * Papers for one vehicle, for several works, priced separately.
     *
     * This is the case the old model could not describe: one folder, a transfer
     * and a hypothecation addition on it, a price for each. It could only be
     * said by inventing a work type called "HPT + TR" — which is why the list
     * held seven names for three services and still could not say what each
     * one earned.
     */
    public function test_one_file_can_be_received_for_several_works(): void
    {
        $this->actingAs($this->admin());

        $second = new WorkTypeModel;
        $second->name = 'Second Work '.uniqid();
        $second->is_active = 1;
        $second->save();

        $before = PartyLedgerModel::currentBalance($this->customer->id);

        $this->post(route('workfile.receive'), [
            'received_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'rows' => [[
                'registration_no' => 'BR01ZZ0001',
                'description' => 'Two works, one folder',
                'works' => [
                    ['work_type_id' => $this->workType->id, 'amount' => '2000'],
                    ['work_type_id' => $second->id, 'amount' => '3000'],
                ],
            ]],
        ])->assertRedirect();

        $file = WorkFileModel::where('registration_no', 'BR01ZZ0001')->firstOrFail();

        $this->assertCount(2, $file->items, 'one row per work');
        $this->assertEqualsCanonicalizing(
            [2000.0, 3000.0],
            $file->items->map(fn ($item) => (float) $item->customer_amount)->all()
        );

        // The file's own figure is the sum, and that is what reaches the ledger.
        $this->assertEquals(5000, $file->customer_amount);
        $this->assertSame(5000.0, PartyLedgerModel::currentBalance($this->customer->id) - $before);

        // One entry for the folder, not one per work: the customer owes for a
        // file, and the breakdown is the file's business.
        $this->assertSame(1, PartyLedgerModel::where('work_file_id', $file->id)->count());
    }

    /**
     * A file is saved once before its works can exist, because they need its id.
     * Filling that gap automatically put an empty job on every file the papers
     * were never for.
     */
    public function test_receiving_never_leaves_a_work_nobody_entered(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('workfile.receive'), [
            'received_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'rows' => [[
                'registration_no' => 'BR01ZZ0002',
                'works' => [['work_type_id' => $this->workType->id, 'amount' => '4000']],
            ]],
        ])->assertRedirect();

        $file = WorkFileModel::where('registration_no', 'BR01ZZ0002')->firstOrFail();

        $this->assertCount(1, $file->items);
        $this->assertEquals(4000, $file->items->first()->customer_amount);
    }

    public function test_a_file_must_be_received_for_at_least_one_work(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('workfile.receive'), [
            'received_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'rows' => [['registration_no' => 'BR01ZZ0003', 'works' => []]],
        ])->assertSessionHasErrors('rows.0.works');
    }

    /**
     * The vendor cost stays unknown while every work is unpriced. Summing them
     * to zero would claim a figure the file does not have, and the pending
     * report reads null as "not agreed" everywhere else.
     */
    public function test_a_files_vendor_cost_is_the_sum_of_its_priced_works(): void
    {
        $file = $this->file();
        $item = $file->items()->first();

        $file->rollUp();
        $this->assertNull($file->vendor_amount, 'nothing agreed on any work');

        $item->vendor_amount = 1500;
        $item->save();

        $file->load('items');
        $file->rollUp();

        $this->assertEquals(1500, $file->vendor_amount);
    }

    /**
     * Papers for two works given to one vendor, at a rate agreed for each.
     *
     * This is the other half of per-work pricing. The customer is charged per
     * job, so the vendor is paid per job, and the folder's cost is the sum —
     * which is what reaches the vendor's statement as one credit for the file.
     */
    public function test_a_vendor_is_credited_the_rate_agreed_on_each_work(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0100');
        $vendor = $this->vendor();
        [$first, $second] = $file->items->all();

        $this->post(route('workfile.assign'), [
            'vendor_id' => $vendor->id,
            'vendor_date' => now()->toDateString(),
            'files' => [$file->id],
            'amounts' => [$first->id => '1200', $second->id => '1800'],
        ])->assertRedirect();

        $file->refresh()->load('items');

        $this->assertEquals(1200, $file->items->firstWhere('id', $first->id)->vendor_amount);
        $this->assertEquals(1800, $file->items->firstWhere('id', $second->id)->vendor_amount);
        $this->assertEquals(3000, $file->vendor_amount, 'the folder costs what its works cost');

        // A vendor's balance sits on the credit side, so being owed 3000 moves
        // it 3000 that way.
        $this->assertSame(-3000.0, PartyLedgerModel::currentBalance($vendor->id));
    }

    /**
     * One rate agreed, the other still being haggled over. The file goes out
     * anyway — it has to, the vendor is holding the papers — and owes only what
     * was actually agreed, with the rest reported as outstanding.
     */
    public function test_a_work_left_unpriced_owes_only_what_was_agreed(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0101');
        $vendor = $this->vendor();
        [$first, $second] = $file->items->all();

        $this->post(route('workfile.assign'), [
            'vendor_id' => $vendor->id,
            'vendor_date' => now()->toDateString(),
            'files' => [$file->id],
            'amounts' => [$first->id => '1200', $second->id => ''],
        ])->assertRedirect();

        $file->refresh()->load('items');

        $this->assertNull($file->items->firstWhere('id', $second->id)->vendor_amount, 'not agreed yet');
        $this->assertEquals(1200, $file->vendor_amount);
        $this->assertSame(-1200.0, PartyLedgerModel::currentBalance($vendor->id));
    }

    /**
     * Papers go back in one envelope.
     *
     * A folder holding a transfer and a hypothecation addition cannot send one
     * of them home: left half returned it would credit nothing and go on
     * billing the customer for papers sitting in their own hand. The return
     * screen takes the whole folder, and shows the refund figure while doing
     * it, so the board sends the operator there.
     */
    public function test_papers_cannot_go_back_one_work_at_a_time(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0102');
        $item = $file->items->first();

        $this->post(route('workfile.status'), [
            'statuses' => [$item->id => WorkFileModel::RETURNED],
            'remarks' => [$item->id => 'Customer asked for the papers back'],
        ])->assertSessionHas('error', fn ($error) => str_contains($error, 'Papers go back a whole file at a time')
            && str_contains($error, $file->file_no));

        $this->assertSame('in_office', $item->refresh()->status, 'nothing moved');
        $this->assertSame('in_office', $file->refresh()->status);
    }

    /**
     * The ordinary file still returns in one click.
     *
     * Most files are for one work, where the folder is the job, and returning
     * it from the board is how it has always been done. The rule above must not
     * take that away.
     */
    public function test_a_file_of_one_work_still_returns_from_the_board(): void
    {
        $this->actingAs($this->admin());

        $file = $this->file();
        $item = $file->items()->first();

        $this->post(route('workfile.status'), [
            'statuses' => [$item->id => WorkFileModel::RETURNED],
            'remarks' => [$item->id => 'Work could not be done'],
        ])->assertRedirect();

        $this->assertSame(WorkFileModel::RETURNED, $file->refresh()->status);
    }

    /**
     * The board offers a folder of several works no way to send one home, so
     * the refusal above is never something an operator can walk into.
     */
    public function test_the_board_does_not_offer_a_part_return(): void
    {
        $this->assertArrayHasKey(WorkFileModel::RETURNED, WorkFileModel::jobStatusesFor(1));
        $this->assertArrayNotHasKey(WorkFileModel::RETURNED, WorkFileModel::jobStatusesFor(2));
    }

    /**
     * The lists name every work on a file.
     *
     * Calling a folder by the first work on it is how the old list came to show
     * "HPA" over a file that was also a transfer — and it is why the work type
     * list grew names like "HPT + TR + HPA": a single column that had to hold
     * several answers.
     */
    public function test_the_files_list_and_the_report_name_every_work(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0103');
        $expected = $file->items->map(fn ($item) => $item->workType->name)->implode(', ');

        $listed = collect($this->getJson(route('workfile.index'))->assertOk()->json('props.rows'))
            ->firstWhere('id', $file->id);

        $this->assertSame($expected, $listed['work_type']);

        $reported = collect($this->getJson(route('report.files'))->assertOk()->json('props.rows'))
            ->firstWhere('id', $file->id);

        $this->assertSame($expected, $reported['work_type']);
    }

    /**
     * Cancelled work stops being named, because it stops being charged for. A
     * file still called "TR, HPA" beside a figure covering only the transfer
     * says the customer was billed for both.
     */
    public function test_a_cancelled_work_drops_out_of_the_name(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0104');
        [$first, $second] = $file->items->all();

        $this->post(route('workfile.status'), [
            'statuses' => [$second->id => WorkFileModel::CANCELLED],
            'remarks' => [$second->id => 'Entered by mistake'],
        ])->assertRedirect();

        $listed = collect($this->getJson(route('workfile.index'))->assertOk()->json('props.rows'))
            ->firstWhere('id', $file->id);

        $this->assertSame($first->workType->name, $listed['work_type'], 'only the work still charged for');
        $this->assertEquals(2000, $listed['charged'], 'and only its charge');
    }
    /**
     * A charge typed wrong is corrected on the work it belongs to.
     *
     * The file screen used to offer one type and one charge, which on a folder
     * of several works could only describe the sum — so a transfer entered at
     * 2,000 when it should have been 2,500 had nowhere to be put right. The
     * folder follows its works afterwards, and so does the statement.
     */
    public function test_a_charge_is_corrected_on_the_work_it_belongs_to(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0105');
        [$first, $second] = $file->items->all();

        $this->post(route('workfile.edit', $file->id), [
            'file_no' => $file->file_no,
            'received_date' => $file->received_date,
            'work_type_id' => $file->work_type_id,
            'customer_id' => $this->customer->id,
            'customer_amount' => '5000',
            'status' => $file->status,
            'items' => [
                $first->id => ['work_type_id' => $first->work_type_id, 'customer_amount' => '2500'],
                $second->id => ['work_type_id' => $second->work_type_id, 'customer_amount' => '3000'],
            ],
        ])->assertRedirect();

        $this->assertEquals(2500, $first->refresh()->customer_amount, 'the work that was wrong');
        $this->assertEquals(3000, $second->refresh()->customer_amount, 'and the one that was not');

        $file->refresh();
        $this->assertEquals(5500, $file->customer_amount, 'the folder is the sum of its works');
        $this->assertSame(5500.0, PartyLedgerModel::where('work_file_id', $file->id)
            ->where('file_role', 'customer')->value('amount') + 0, 'and so is the statement');
    }

    /**
     * The correction cannot reach a work on somebody else's file. Ids in a form
     * are typed by whoever sends it, and repricing a stranger's work would move
     * a party's balance from a screen that never showed it.
     */
    public function test_a_correction_cannot_reach_another_files_work(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0106');
        $other = $this->file();
        $stranger = $other->items()->first();

        $this->post(route('workfile.edit', $file->id), [
            'file_no' => $file->file_no,
            'received_date' => $file->received_date,
            'work_type_id' => $file->work_type_id,
            'customer_id' => $this->customer->id,
            'customer_amount' => '5000',
            'status' => $file->status,
            'items' => [
                $stranger->id => ['work_type_id' => $stranger->work_type_id, 'customer_amount' => '99999'],
            ],
        ])->assertRedirect();

        $this->assertEquals(5000, $stranger->refresh()->customer_amount, 'untouched');
    }

    /**
     * Every approval keeps the document it arrived with, and the file's own
     * screen is where they all hang: the list upstairs has one link per folder
     * and a folder can have several approvals.
     */
    public function test_the_file_screen_carries_each_works_own_approval(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0107');
        [$first, $second] = $file->items->all();

        $first->approval_screenshot = 'uploads/approval/item-first.png';
        $first->status = WorkFileModel::APPROVED;
        $first->save();

        $works = collect(
            $this->getJson(route('workfile.edit', $file->id))->assertOk()->json('props.items')
        );

        $approved = $works->firstWhere('id', $first->id);
        $pending = $works->firstWhere('id', $second->id);

        $this->assertStringContainsString('item-first.png', $approved['screenshot_url']);
        $this->assertSame(now()->format('d-m-Y'), $approved['approved_on']);

        $this->assertNull($pending['screenshot_url'], 'nothing to show for work not yet through');
        $this->assertNull($pending['approved_on']);
    }
    /**
     * A folder counts under every work it holds, on both filters.
     *
     * The board shows a file when any of its works is of the chosen type, so
     * the tabs beside it have to count the same way. Counting the folder's own
     * type credited the whole folder to the first work on it: the transfer tab
     * said "no files" over a board that then listed one.
     */
    public function test_the_tabs_count_every_work_a_folder_holds(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0108');

        foreach ($file->items as $item) {
            $counted = collect(WorkFileModel::workTypeCounts('all'))
                ->firstWhere('id', $item->work_type_id);

            $this->assertNotNull($counted, $item->workType->name.' is counted');
            $this->assertSame(
                WorkFileModel::forStatusBoard('all', $item->work_type_id)->count(),
                (int) $counted->total,
                'the tab counts what the board shows'
            );

            // And the file is counted once under each, not twice under one.
            $this->assertSame(1, (int) $counted->total);

            $this->assertSame(1, WorkFileModel::statusCounts($item->work_type_id)['all']);
        }
    }

    /**
     * A work type is used by the works booked against it, not by the folders
     * that happen to name it. The second work on a folder read as a type
     * nobody had ever used — which is what makes a type look safe to retire.
     */
    public function test_a_work_type_counts_the_work_booked_against_it(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0109');
        $second = $file->items->last();

        $usage = collect(WorkTypeModel::withUsage())->firstWhere('id', $second->work_type_id);

        $this->assertSame(1, (int) $usage->file_count, 'the work counts even though the folder names another');
        $this->assertEquals(3000, $usage->billed_total);
    }
    private function vendor(): PartyModel
    {
        $vendor = new PartyModel;
        $vendor->party_type = 'vendor';
        $vendor->name = 'Item Vendor '.uniqid();
        $vendor->mobile = '9333300002';
        $vendor->is_active = 1;
        $vendor->save();

        return $vendor;
    }

    /**
     * Papers for one vehicle and two works, received the way the screen does it.
     */
    private function twoWorkFile(string $registration): WorkFileModel
    {
        $second = new WorkTypeModel;
        $second->name = 'Second Work '.uniqid();
        $second->is_active = 1;
        $second->save();

        $this->post(route('workfile.receive'), [
            'received_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'rows' => [[
                'registration_no' => $registration,
                'works' => [
                    ['work_type_id' => $this->workType->id, 'amount' => '2000'],
                    ['work_type_id' => $second->id, 'amount' => '3000'],
                ],
            ]],
        ])->assertRedirect();

        return WorkFileModel::where('registration_no', $registration)->firstOrFail()->load('items');
    }

}
