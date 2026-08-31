<?php

namespace Tests\Feature;

use App\Models\PartyModel;
use App\Models\User;
use App\Models\PartyLedgerModel;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
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
    /**
     * The statement names every work the entry covers.
     *
     * A customer reading their ledger sees one line for the file and the
     * figure they were billed. Calling it by the first work alone made an
     * entry covering three jobs read as though it were for one of them.
     */
    public function test_the_statement_names_every_work_the_entry_covers(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0110');
        $expected = $file->items->map(fn ($item) => $item->workType->name)->implode(', ');

        $particular = PartyLedgerModel::where('work_file_id', $file->id)
            ->where('file_role', 'customer')
            ->value('particular');

        $this->assertStringStartsWith($expected, $particular);
        $this->assertStringContainsString('BR01ZZ0110', $particular, 'and the vehicle it is for');
    }

    /**
     * Cancelled work drops off the statement line, because it drops out of the
     * figure beside it. An entry naming work the customer was not billed for
     * is one they will ring up about.
     */
    public function test_cancelled_work_is_not_named_on_the_statement(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0111');
        [$first, $second] = $file->items->all();

        $this->post(route('workfile.status'), [
            'statuses' => [$second->id => WorkFileModel::CANCELLED],
            'remarks' => [$second->id => 'Entered by mistake'],
        ])->assertRedirect();

        $particular = PartyLedgerModel::where('work_file_id', $file->id)
            ->where('file_role', 'customer')
            ->value('particular');

        $this->assertStringContainsString($first->workType->name, $particular);
        $this->assertStringNotContainsString($second->workType->name, $particular);
    }

    /**
     * Files taken in before there was a registration field have the number in
     * the description, and an entry reading "BR01DD1234 - BR01DD1234" helps
     * nobody.
     */
    public function test_the_vehicle_is_not_written_twice(): void
    {
        $file = $this->file();
        $file->registration_no = 'BR01ZZ0112';
        $file->description = 'BR01ZZ0112';
        $file->save();

        $this->assertSame(1, substr_count($file->ledgerParticular(), 'BR01ZZ0112'));
    }
    /**
     * An approval says when it happened.
     *
     * Work approved on Friday and entered on Monday would otherwise be recorded
     * as Monday's, and with two approvals a week apart on one folder, which day
     * each landed is the whole question the file gets asked later.
     */
    public function test_an_approval_records_the_day_it_came_through(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0113');
        $file->received_date = now()->subDays(14)->toDateString();
        $file->save();

        $item = $file->items->first();
        $approved = now()->subDays(4)->toDateString();

        $this->post(route('workfile.status'), [
            'statuses' => [$item->id => WorkFileModel::APPROVED],
            'approved_on' => [$item->id => $approved],
            'screenshots' => [$item->id => UploadedFile::fake()->image('rto.jpg')],
        ])->assertRedirect();

        $item->refresh();

        $this->assertSame(WorkFileModel::APPROVED, $item->status);
        $this->assertSame($approved, (string) $item->approved_on, 'the day it was approved, not today');

        $item->approval_screenshot && @unlink(public_path($item->approval_screenshot));
    }

    public function test_an_approval_without_a_date_is_refused(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0114');
        $item = $file->items->first();

        $this->post(route('workfile.status'), [
            'statuses' => [$item->id => WorkFileModel::APPROVED],
            'approved_on' => [$item->id => ''],
            'screenshots' => [$item->id => UploadedFile::fake()->image('rto.jpg')],
        ])->assertSessionHas('error', fn ($error) => str_contains($error, 'the date it was approved'));

        $this->assertSame('in_office', $item->refresh()->status, 'nothing moved');
    }

    /**
     * Papers cannot be approved before they were taken in, and a date in the
     * future is not an approval that has happened.
     */
    public function test_an_approval_cannot_be_dated_outside_the_files_life(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0115');
        $item = $file->items->first();

        $this->post(route('workfile.status'), [
            'statuses' => [$item->id => WorkFileModel::APPROVED],
            'approved_on' => [$item->id => now()->subYear()->toDateString()],
            'screenshots' => [$item->id => UploadedFile::fake()->image('rto.jpg')],
        ])->assertSessionHas('error', fn ($error) => str_contains($error, 'before the papers came in'));

        $this->post(route('workfile.status'), [
            'statuses' => [$item->id => WorkFileModel::APPROVED],
            'approved_on' => [$item->id => now()->addDay()->toDateString()],
            'screenshots' => [$item->id => UploadedFile::fake()->image('rto.jpg')],
        ])->assertSessionHasErrors('approved_on.'.$item->id);

        $this->assertSame('in_office', $item->refresh()->status, 'nothing moved either time');
    }

    /**
     * Work that is through comes off the board.
     *
     * The board answers one question — what is still to do — and a finished job
     * sitting on it with a status box and a screenshot prompt is something to
     * read past every time the operator comes back to the job that is not
     * finished. It is still there under every other tab, and on the file.
     */
    public function test_finished_work_leaves_the_in_hand_board(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0116');
        [$done, $pending] = $file->items->all();

        $this->post(route('workfile.status'), [
            'statuses' => [$done->id => WorkFileModel::APPROVED],
            'approved_on' => [$done->id => now()->toDateString()],
            'screenshots' => [$done->id => UploadedFile::fake()->image('rto.jpg')],
        ])->assertRedirect();

        $onBoard = fn (string $view) => collect(
            $this->getJson(route('workfile.status', array_filter(['status' => $view])))
                ->assertOk()->json('props.files')
        )->firstWhere('id', $file->id);

        $inHand = $onBoard('open');

        $this->assertNotNull($inHand, 'the folder is still in hand');
        $this->assertSame([$pending->id], array_column($inHand['items'], 'id'), 'only the work still to do');
        $this->assertSame(2, $inHand['works'], 'and the heading says how many there are');
        $this->assertSame(1, $inHand['settled'], 'and how many are finished');

        $this->assertCount(2, $onBoard('all')['items'], 'every other view shows the whole folder');

        $done->refresh()->approval_screenshot && @unlink(public_path($done->refresh()->approval_screenshot));
    }
    /**
     * The list says which way a partly approved folder's works disagree.
     *
     * "Partly Approved" is the thing worth knowing from across the room, and
     * the question straight after it is always which one came through. Reading
     * it off the list beats opening the file to find out.
     */
    public function test_the_list_says_which_works_are_through(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0117');
        [$done, $pending] = $file->items->all();

        $this->post(route('workfile.status'), [
            'statuses' => [$done->id => WorkFileModel::APPROVED],
            'approved_on' => [$done->id => now()->toDateString()],
            'screenshots' => [$done->id => UploadedFile::fake()->image('rto.jpg')],
        ])->assertRedirect();

        $row = collect($this->getJson(route('workfile.index'))->assertOk()->json('props.rows'))
            ->firstWhere('id', $file->id);

        $this->assertSame(
            $done->workType->name.' approved · '.$pending->workType->name.' pending',
            $row['works_note']
        );

        $done->refresh()->approval_screenshot && @unlink(public_path($done->refresh()->approval_screenshot));
    }

    /**
     * And says nothing when there is nothing to add. A folder of one work, or
     * one whose works all agree, is described by its badge — repeating that on
     * every row would bury the rows where it matters.
     */
    public function test_a_folder_whose_works_agree_says_nothing_extra(): void
    {
        $this->actingAs($this->admin());

        $several = $this->twoWorkFile('BR01ZZ0118');
        $single = $this->file();

        $rows = collect($this->getJson(route('workfile.index'))->assertOk()->json('props.rows'));

        $this->assertNull($rows->firstWhere('id', $several->id)['works_note'], 'both still in hand');
        $this->assertNull($rows->firstWhere('id', $single->id)['works_note'], 'only one work');
    }

    /**
     * Cancelled and returned work is named by what happened to it, not lumped
     * in with what is still being chased.
     */
    public function test_the_note_names_what_happened_to_each_work(): void
    {
        $work = fn (string $name, string $status, ?string $approved = null) => (object) [
            'name' => $name,
            'status' => $status,
            'approved_on' => $approved,
        ];

        $mixed = [
            $work('HPA', WorkFileModel::APPROVED, '2026-08-21'),
            $work('HPT', 'file_dispatch'),
            $work('TR', WorkFileModel::CANCELLED),
        ];

        $this->assertSame('HPA approved · TR cancelled · HPT pending', WorkFileModel::workNote($mixed));

        $this->assertNull(
            WorkFileModel::workNote([$work('HPA', WorkFileModel::APPROVED), $work('TR', WorkFileModel::APPROVED)]),
            'they agree'
        );

        $this->assertNull(WorkFileModel::workNote([]), 'nothing to describe');
    }
    /**
     * A work type nobody has used can be removed outright.
     *
     * One added by mistake, or one tried and abandoned, is clutter in every
     * dropdown on the system until it goes.
     */
    public function test_an_unused_work_type_can_be_deleted(): void
    {
        $this->actingAs($this->admin());

        $spare = new WorkTypeModel;
        $spare->name = 'Spare Work '.uniqid();
        $spare->is_active = 1;
        $spare->save();

        $this->post(route('worktype.delete', $spare->id))->assertRedirect();

        $this->assertNull(WorkTypeModel::find($spare->id));
    }

    /**
     * One with work behind it cannot. Those files say they are for it, and the
     * report and the customer's own statement print its name — deleting it
     * takes that name off all three.
     */
    public function test_a_work_type_in_use_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin());

        $file = $this->file();

        $this->post(route('worktype.delete', $this->workType->id))
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'cannot be deleted')
                && str_contains($error, 'Switch it off instead'));

        $this->assertNotNull(WorkTypeModel::find($this->workType->id), 'still there');
        $this->assertNotNull($file->refresh(), 'and so is the file that names it');
    }

    /**
     * Including one used only by the second work on a folder. The folder names
     * its first work and nothing else, so counting folders would report that
     * type as unused and let it be deleted out from under a live file.
     */
    public function test_a_type_used_by_a_second_work_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0119');
        $second = $file->items->last();

        $this->assertNotSame(
            (int) $file->work_type_id,
            (int) $second->work_type_id,
            'the folder does not name this one'
        );

        $this->post(route('worktype.delete', $second->work_type_id))
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'cannot be deleted'));

        $this->assertNotNull(WorkTypeModel::find($second->work_type_id));
    }

    /**
     * The screen offers delete from the same count the server refuses on, so
     * the two cannot disagree about which types are safe.
     */
    public function test_the_screen_offers_delete_only_where_the_server_allows_it(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0120');

        $spare = new WorkTypeModel;
        $spare->name = 'Spare Work '.uniqid();
        $spare->is_active = 1;
        $spare->save();

        $types = collect($this->getJson(route('worktype.index'))->assertOk()->json('props.types'))
            ->keyBy('id');

        $this->assertSame(0, $types[$spare->id]['file_count'], 'nothing booked against it');

        foreach ($file->items as $item) {
            $this->assertGreaterThan(0, $types[$item->work_type_id]['file_count'], 'work is booked against it');
        }

        $this->assertNotEmpty($types[$spare->id]['delete_url']);
    }
    /**
     * A folder with one rate agreed and one still to come has no margin yet.
     *
     * Its own total is 1,200, which is a figure greater than zero, so it read
     * as fully priced: the list showed a margin against a cost that was going
     * to grow, and the report that chases missing rates passed it over. The
     * work with no rate on it could sit there indefinitely with nobody told.
     */
    public function test_a_half_priced_folder_has_no_margin_and_is_still_chased(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0121');
        $vendor = $this->vendor();
        [$first, $second] = $file->items->all();

        $this->post(route('workfile.assign'), [
            'vendor_id' => $vendor->id,
            'vendor_date' => now()->toDateString(),
            'files' => [$file->id],
            'amounts' => [$first->id => '1200', $second->id => ''],
        ])->assertRedirect();

        $this->assertEquals(1200, $file->refresh()->vendor_amount, 'the folder owes what was agreed');

        $listed = collect($this->getJson(route('workfile.index'))->assertOk()->json('props.rows'))
            ->firstWhere('id', $file->id);

        $this->assertNull($listed['margin'], 'a cost still to grow is not a margin');

        $chased = collect($this->getJson(route('workfile.index', ['pending' => 'vendor']))
            ->assertOk()->json('props.rows'))->pluck('id');

        $this->assertContains($file->id, $chased->all(), 'and somebody is told the rate is missing');
    }

    /**
     * The list and the report are two views of the same figures and must not
     * answer differently. The list worked its own margin out, so a file out
     * with a vendor at no agreed rate showed the whole charge as profit on one
     * page and an empty cell on the other.
     */
    public function test_the_list_and_the_report_agree_about_margin(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0122');
        $vendor = $this->vendor();

        $this->post(route('workfile.assign'), [
            'vendor_id' => $vendor->id,
            'vendor_date' => now()->toDateString(),
            'files' => [$file->id],
            'amounts' => [],
        ])->assertRedirect();

        $listed = collect($this->getJson(route('workfile.index'))->assertOk()->json('props.rows'))
            ->firstWhere('id', $file->id);

        $reported = collect($this->getJson(route('report.files'))->assertOk()->json('props.rows'))
            ->firstWhere('id', $file->id);

        $this->assertNull($listed['margin']);
        $this->assertSame($reported['margin'], $listed['margin'], 'one answer, two pages');
    }

    /**
     * Once every rate is agreed the file has a margin again, and both pages
     * state the same one.
     */
    public function test_a_fully_priced_folder_has_a_margin(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0123');
        $vendor = $this->vendor();
        [$first, $second] = $file->items->all();

        $this->post(route('workfile.assign'), [
            'vendor_id' => $vendor->id,
            'vendor_date' => now()->toDateString(),
            'files' => [$file->id],
            'amounts' => [$first->id => '1200', $second->id => '1800'],
        ])->assertRedirect();

        $listed = collect($this->getJson(route('workfile.index'))->assertOk()->json('props.rows'))
            ->firstWhere('id', $file->id);

        // Charged 5,000 across the two works, costing 3,000.
        $this->assertEquals(2000, $listed['margin']);

        $chased = collect($this->getJson(route('workfile.index', ['pending' => 'vendor']))
            ->assertOk()->json('props.rows'))->pluck('id');

        $this->assertNotContains($file->id, $chased->all(), 'nothing left to chase');
    }
    /**
     * Work struck off a folder stays struck off when the folder moves.
     *
     * Handing the papers to a vendor moved every work on the file, cancelled
     * ones included — and the roll-up counts a work that is not cancelled, so
     * the charge came back onto a customer's statement that nobody had
     * touched. A file billed 2,000 after a cancellation went back to 5,000 for
     * doing nothing but giving it out.
     */
    public function test_giving_a_folder_out_does_not_revive_cancelled_work(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0124');
        $vendor = $this->vendor();
        [$kept, $struck] = $file->items->all();

        $this->post(route('workfile.status'), [
            'statuses' => [$struck->id => WorkFileModel::CANCELLED],
            'remarks' => [$struck->id => 'Entered by mistake'],
        ])->assertRedirect();

        $this->assertEquals(2000, $file->refresh()->customer_amount, 'the charge went with it');

        $this->post(route('workfile.assign'), [
            'vendor_id' => $vendor->id,
            'vendor_date' => now()->toDateString(),
            'files' => [$file->id],
            'amounts' => [$kept->id => '1200'],
        ])->assertRedirect();

        $this->assertSame(WorkFileModel::CANCELLED, $struck->refresh()->status, 'still struck off');
        $this->assertEquals(2000, $file->refresh()->customer_amount, 'and still billed 2,000');
        $this->assertSame(2000.0, (float) PartyLedgerModel::where('work_file_id', $file->id)
            ->where('file_role', 'customer')->value('amount'), 'on the statement too');
    }

    /**
     * And when the papers go home. Returning a folder credits back what was
     * charged; a cancelled work was never charged, so reviving it as returned
     * would put a charge on the statement and refund part of it in one move.
     */
    public function test_returning_a_folder_does_not_revive_cancelled_work(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0125');
        [$kept, $struck] = $file->items->all();

        $this->post(route('workfile.status'), [
            'statuses' => [$struck->id => WorkFileModel::CANCELLED],
            'remarks' => [$struck->id => 'Entered by mistake'],
        ])->assertRedirect();

        $this->post(route('workfile.customerreturn'), [
            'returned_on' => now()->toDateString(),
            'files' => [$file->id],
            'remark' => 'Could not be done',
        ])->assertRedirect();

        $this->assertSame(WorkFileModel::CANCELLED, $struck->refresh()->status, 'still struck off');
        $this->assertSame(WorkFileModel::RETURNED, $kept->refresh()->status, 'the live work went back');
        $this->assertSame(WorkFileModel::RETURNED, $file->refresh()->status);
        $this->assertEquals(2000, $file->customer_amount, 'still billed only what was live');
    }
    /**
     * Both money queries must carry the outstanding-work counts.
     *
     * awaitingPrice() falls back to the folder's own total when they are
     * missing, which is the right answer for a file of one work and the wrong
     * one for a folder half priced. The fallback exists so an unrelated caller
     * cannot crash a page; it is not meant to be how the list and the report
     * answer, and a refactor that quietly dropped the columns would put the
     * phantom margin straight back with every test still green.
     */
    public function test_the_money_queries_count_outstanding_work(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0126');

        $listed = WorkFileModel::listing()->firstWhere('id', $file->id);
        $reported = WorkFileModel::report('customer')->firstWhere('id', $file->id);

        foreach (['listing' => $listed, 'report' => $reported] as $which => $row) {
            $this->assertNotNull($row, "$which returns the file");

            foreach (['unpriced_works', 'unbilled_works'] as $column) {
                $this->assertTrue(
                    property_exists($row, $column),
                    "$which must select $column, or awaitingPrice() silently answers from the folder's total"
                );
            }
        }

        // Two works received, neither priced by a vendor yet.
        $this->assertSame(2, (int) $listed->unpriced_works);
        $this->assertSame(0, (int) $listed->unbilled_works);
    }
    /**
     * The same answer as three fields, for a spreadsheet — where a column can
     * be sorted and filtered and a sentence cannot. The dates line up with the
     * names beside them, so a folder approved a week apart reads across.
     */
    public function test_the_export_splits_the_works_into_columns(): void
    {
        $work = fn (string $name, string $status, ?string $approved = null) => (object) [
            'name' => $name,
            'status' => $status,
            'approved_on' => $approved,
        ];

        $split = WorkFileModel::workSplit([
            $work('HPA', WorkFileModel::APPROVED, '2026-08-21'),
            $work('HPT', 'file_dispatch'),
            $work('TR', WorkFileModel::APPROVED, '2026-08-28'),
        ]);

        $this->assertSame('HPA, TR', $split['done']);
        $this->assertSame('21-08-2026, 28-08-2026', $split['approved_on'], 'in the same order as the names');
        $this->assertSame('HPT', $split['pending']);

        // Cancelled work is neither done nor pending: it is not being chased
        // and it was not carried out.
        $struck = WorkFileModel::workSplit([
            $work('HPA', WorkFileModel::APPROVED, '2026-08-21'),
            $work('TR', WorkFileModel::CANCELLED),
        ]);

        $this->assertSame('HPA', $struck['done']);
        $this->assertSame('', $struck['pending']);
    }

    /**
     * And through the screen, so the columns and the sentence beside them can
     * never tell different stories.
     */
    public function test_the_list_exports_which_works_are_through(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0127');
        $file->received_date = now()->subWeek()->toDateString();
        $file->save();

        [$done, $pending] = $file->items->all();
        $approved = now()->subDay()->toDateString();

        $this->post(route('workfile.status'), [
            'statuses' => [$done->id => WorkFileModel::APPROVED],
            'approved_on' => [$done->id => $approved],
            'screenshots' => [$done->id => UploadedFile::fake()->image('rto.jpg')],
        ])->assertRedirect()->assertSessionMissing('error');

        foreach ([route('workfile.index'), route('report.files')] as $url) {
            $row = collect($this->getJson($url)->assertOk()->json('props.rows'))
                ->firstWhere('id', $file->id);

            $this->assertSame($done->workType->name, $row['works_done'], "$url names the work that is through");
            $this->assertSame(date('d-m-Y', strtotime($approved)), $row['works_approved_on'], "$url dates it");
            $this->assertSame($pending->workType->name, $row['works_pending'], "$url names what is left");
        }

        // Drawn on neither screen: both say it in the status cell instead.
        $columns = collect($this->getJson(route('workfile.index'))->assertOk()->json('props.columns'));

        foreach (['works_done', 'works_approved_on', 'works_pending'] as $key) {
            $this->assertTrue(
                (bool) ($columns->firstWhere('key', $key)['exportOnly'] ?? false),
                "$key belongs in the export, not the table"
            );
        }

        $done->refresh()->approval_screenshot && @unlink(public_path($done->refresh()->approval_screenshot));
    }
    /**
     * One vehicle has one transfer and one hypothecation addition.
     *
     * A file booked for the same work twice charges the customer twice for one
     * job. The screen stops offering a work once it is on the card, but a form
     * can be sent by hand and this is where the money is decided.
     */
    public function test_a_file_cannot_be_received_for_the_same_work_twice(): void
    {
        $this->actingAs($this->admin());

        $before = WorkFileModel::count();

        $this->post(route('workfile.receive'), [
            'received_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'rows' => [[
                'registration_no' => 'BR01ZZ0128',
                'works' => [
                    ['work_type_id' => $this->workType->id, 'amount' => '2000'],
                    ['work_type_id' => $this->workType->id, 'amount' => '3000'],
                ],
            ]],
        ])->assertSessionHas('error', fn ($error) => str_contains($error, 'same work twice')
            && str_contains($error, $this->workType->name));

        $this->assertSame($before, WorkFileModel::count(), 'nothing was booked');
    }

    /**
     * The same work on two different files is ordinary: two vehicles, two
     * transfers. The rule is about one envelope, not one batch.
     */
    public function test_two_files_in_a_batch_may_be_for_the_same_work(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('workfile.receive'), [
            'received_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'rows' => [
                ['registration_no' => 'BR01ZZ0129', 'works' => [['work_type_id' => $this->workType->id, 'amount' => '2000']]],
                ['registration_no' => 'BR01ZZ0130', 'works' => [['work_type_id' => $this->workType->id, 'amount' => '2500']]],
            ],
        ])->assertRedirect()->assertSessionMissing('error');

        $this->assertNotNull(WorkFileModel::where('registration_no', 'BR01ZZ0129')->first());
        $this->assertNotNull(WorkFileModel::where('registration_no', 'BR01ZZ0130')->first());
    }

    /**
     * And a correction cannot introduce the duplicate either — retyping one
     * folder's transfer as a hypothecation addition, where it already has one.
     */
    public function test_a_correction_cannot_make_a_file_the_same_work_twice(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0131');
        [$first, $second] = $file->items->all();

        $this->post(route('workfile.edit', $file->id), [
            'file_no' => $file->file_no,
            'received_date' => $file->received_date,
            'work_type_id' => $file->work_type_id,
            'customer_id' => $this->customer->id,
            'customer_amount' => '5000',
            'status' => $file->status,
            'items' => [
                $first->id => ['work_type_id' => $first->work_type_id, 'customer_amount' => '2000'],
                $second->id => ['work_type_id' => $first->work_type_id, 'customer_amount' => '3000'],
            ],
        ])->assertSessionHas('error', fn ($error) => str_contains($error, 'same work twice'));

        $this->assertSame(
            (int) $second->work_type_id,
            (int) $second->refresh()->work_type_id,
            'the work was left as it was'
        );
    }
    /**
     * The vehicle, on every screen where a file is picked out of a list.
     *
     * A file number identifies a file to the office; the registration
     * identifies it to everyone else. Handing papers to a vendor, or back to a
     * customer, is done with the envelope in hand — and the number plate is
     * what is read off it.
     */
    public function test_the_pick_a_file_screens_name_the_vehicle(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0132');

        $screens = ['workfile.assign', 'workfile.customerreturn', 'workfile.vendorreturn'];

        // Out with a vendor, so the vendor-return screen has it to offer too.
        $this->post(route('workfile.assign'), [
            'vendor_id' => $this->vendor()->id,
            'vendor_date' => now()->toDateString(),
            'files' => [$file->id],
            'amounts' => [$file->items->first()->id => '1200'],
        ])->assertRedirect();

        foreach ($screens as $route) {
            $rows = collect($this->getJson(route($route))->assertOk()->json('props.files'));
            $row = $rows->firstWhere('id', $file->id);

            // Give to Vendor only offers files not yet given out.
            if ($route === 'workfile.assign') {
                $this->assertNull($row, 'a file already with a vendor is not offered again');

                continue;
            }

            $this->assertNotNull($row, "$route lists the file");
            $this->assertSame('BR01ZZ0132', $row['registration_no'], "$route names the vehicle");
        }
    }

    /**
     * And names every work on it, not the first.
     *
     * The two return screens read the folder's own work type, which is the bug
     * already fixed on the files list: a folder for a transfer and a
     * hypothecation addition offered itself as one of them, on the screen where
     * someone decides whether to send it back.
     */
    public function test_the_return_screens_name_every_work_on_a_file(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0133');
        $expected = $file->items->map(fn ($item) => $item->workType->name)->implode(', ');

        $row = collect($this->getJson(route('workfile.customerreturn'))->assertOk()->json('props.files'))
            ->firstWhere('id', $file->id);

        $this->assertSame($expected, $row['work_type']);
    }

    /**
     * A file offered to be given out carries the vehicle as well.
     */
    public function test_a_file_waiting_to_go_out_names_its_vehicle(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0134');

        $row = collect($this->getJson(route('workfile.assign'))->assertOk()->json('props.files'))
            ->firstWhere('id', $file->id);

        $this->assertNotNull($row, 'it is waiting to go out');
        $this->assertSame('BR01ZZ0134', $row['registration_no']);
    }
    /**
     * The board can be narrowed to one vendor.
     *
     * Chasing work is usually chasing one vendor: you have them on the phone
     * and want their files, not everybody's.
     */
    public function test_the_board_can_be_narrowed_to_one_vendor(): void
    {
        $this->actingAs($this->admin());

        $mine = $this->twoWorkFile('BR01ZZ0135');
        $theirs = $this->twoWorkFile('BR01ZZ0136');
        $vendor = $this->vendor();

        $this->post(route('workfile.assign'), [
            'vendor_id' => $vendor->id,
            'vendor_date' => now()->toDateString(),
            'files' => [$mine->id],
            'amounts' => [$mine->items->first()->id => '1200'],
        ])->assertRedirect();

        $listed = fn (array $query) => collect(
            $this->getJson(route('workfile.status', $query))->assertOk()->json('props.files')
        )->pluck('id')->all();

        $his = $listed(['vendor' => $vendor->id]);

        $this->assertContains($mine->id, $his);
        $this->assertNotContains($theirs->id, $his, 'a file nobody was given is not his');

        // And the work kept in hand is a filter of its own, which a blank could
        // not tell apart from no filter at all.
        $inHouse = $listed(['vendor' => WorkFileModel::IN_HOUSE]);

        $this->assertContains($theirs->id, $inHouse);
        $this->assertNotContains($mine->id, $inHouse);
    }

    /**
     * And the chips count what the board would then show.
     *
     * A count taken without the filters that are on promises rows the board
     * does not have — which is the mistake the status and work type counts
     * already made once.
     */
    public function test_the_vendor_chips_count_what_the_board_shows(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0137');
        $vendor = $this->vendor();

        $this->post(route('workfile.assign'), [
            'vendor_id' => $vendor->id,
            'vendor_date' => now()->toDateString(),
            'files' => [$file->id],
            'amounts' => [$file->items->first()->id => '1200'],
        ])->assertRedirect();

        foreach (WorkFileModel::vendorCounts('all') as $party) {
            $key = $party->id ?: WorkFileModel::IN_HOUSE;

            $this->assertSame(
                WorkFileModel::forStatusBoard('all', null, $key)->count(),
                (int) $party->total,
                $party->name.' is counted as many times as the board lists it'
            );
        }
    }

    /**
     * The filters hold each other: choosing a vendor does not widen the status,
     * and the counts are taken under whatever else is on.
     */
    public function test_the_filters_narrow_together(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0138');
        $vendor = $this->vendor();

        $this->post(route('workfile.assign'), [
            'vendor_id' => $vendor->id,
            'vendor_date' => now()->toDateString(),
            'files' => [$file->id],
            'amounts' => [$file->items->first()->id => '1200'],
        ])->assertRedirect();

        // Given out, so the file is dispatched and not in office.
        $dispatched = collect($this->getJson(route('workfile.status', [
            'status' => WorkFileModel::DISPATCHED,
            'vendor' => $vendor->id,
        ]))->assertOk()->json('props.files'))->pluck('id');

        $this->assertContains($file->id, $dispatched->all());

        $inOffice = collect($this->getJson(route('workfile.status', [
            'status' => 'in_office',
            'vendor' => $vendor->id,
        ]))->assertOk()->json('props.files'))->pluck('id');

        $this->assertNotContains($file->id, $inOffice->all(), 'the status still applies');

        // And a work type the file does not hold excludes it, vendor or not.
        $other = new WorkTypeModel;
        $other->name = 'Unused Work '.uniqid();
        $other->is_active = 1;
        $other->save();

        $none = collect($this->getJson(route('workfile.status', [
            'status' => 'all',
            'vendor' => $vendor->id,
            'work_type' => $other->id,
        ]))->assertOk()->json('props.files'))->pluck('id');

        $this->assertNotContains($file->id, $none->all(), 'the work type still applies');
    }
    /**
     * Work that is through has a screen of its own.
     *
     * Approved files are finished, and reading past them every time is what a
     * separate section exists to stop.
     */
    public function test_the_approved_screen_holds_only_work_that_is_through(): void
    {
        $this->actingAs($this->admin());

        $done = $this->twoWorkFile('BR01ZZ0139');
        $done->received_date = now()->subWeek()->toDateString();
        $done->save();

        $open = $this->twoWorkFile('BR01ZZ0140');

        foreach ($done->items as $item) {
            $this->post(route('workfile.status'), [
                'statuses' => [$item->id => WorkFileModel::APPROVED],
                'approved_on' => [$item->id => now()->subDay()->toDateString()],
                'screenshots' => [$item->id => UploadedFile::fake()->image('rto.jpg')],
            ])->assertRedirect()->assertSessionMissing('error');
        }

        $this->assertSame(WorkFileModel::APPROVED, $done->refresh()->status);

        $listed = collect($this->getJson(route('workfile.approved'))->assertOk()->json('props.rows'))
            ->pluck('id');

        $this->assertContains($done->id, $listed->all());
        $this->assertNotContains($open->id, $listed->all(), 'work still in hand belongs on the other screen');

        // And it says which works came through, and when.
        $row = collect($this->getJson(route('workfile.approved'))->assertOk()->json('props.rows'))
            ->firstWhere('id', $done->id);

        $expected = $done->items->map(fn ($item) => $item->workType->name)->implode(', ');
        $this->assertSame($expected, $row['works_done']);
        $this->assertStringContainsString(now()->subDay()->format('d-m-Y'), $row['works_approved_on']);

        foreach ($done->items as $item) {
            $item->refresh()->approval_screenshot && @unlink(public_path($item->approval_screenshot));
        }
    }

    /**
     * All Work Files still holds everything — it is called All. The approved
     * screen is a second way in, not a move.
     */
    public function test_the_files_list_still_holds_approved_work(): void
    {
        $this->actingAs($this->admin());

        $file = $this->twoWorkFile('BR01ZZ0141');
        $file->received_date = now()->subWeek()->toDateString();
        $file->save();

        foreach ($file->items as $item) {
            $this->post(route('workfile.status'), [
                'statuses' => [$item->id => WorkFileModel::APPROVED],
                'approved_on' => [$item->id => now()->toDateString()],
                'screenshots' => [$item->id => UploadedFile::fake()->image('rto.jpg')],
            ])->assertRedirect()->assertSessionMissing('error');
        }

        $listed = collect($this->getJson(route('workfile.index'))->assertOk()->json('props.rows'))
            ->pluck('id');

        $this->assertContains($file->id, $listed->all());

        foreach ($file->items as $item) {
            $item->refresh()->approval_screenshot && @unlink(public_path($item->approval_screenshot));
        }
    }

    /**
     * The two screens ask different questions, so they draw different columns:
     * a status that would read the same on every row gives way to the works
     * that came through and the day they did.
     */
    public function test_the_approved_screen_draws_the_approval_not_the_status(): void
    {
        $this->actingAs($this->admin());

        $drawn = function (string $route) {
            return collect($this->getJson(route($route))->assertOk()->json('props.columns'))
                ->reject(fn ($column) => ($column['hidden'] ?? false) || ($column['exportOnly'] ?? false))
                ->pluck('label')
                ->all();
        };

        $list = $drawn('workfile.index');
        $approved = $drawn('workfile.approved');

        $this->assertContains('Status', $list);
        $this->assertNotContains('Status', $approved);

        $this->assertContains('Approved Works', $approved);
        $this->assertContains('Approved On', $approved);

        // Which works, then when — the other way round reads backwards.
        $this->assertLessThan(
            array_search('Approved On', $approved, true),
            array_search('Approved Works', $approved, true)
        );

        // Both screens export all three, whichever they draw.
        foreach (['workfile.index', 'workfile.approved'] as $route) {
            $exported = collect($this->getJson(route($route))->assertOk()->json('props.columns'))
                ->reject(fn ($column) => ($column['hidden'] ?? false) || ($column['exportable'] ?? true) === false)
                ->pluck('label');

            foreach (['Approved Works', 'Approved On', 'Pending Works'] as $label) {
                $this->assertContains($label, $exported->all(), "$route exports $label");
                $this->assertSame(1, $exported->filter(fn ($one) => $one === $label)->count(), "$route exports $label once");
            }
        }
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
