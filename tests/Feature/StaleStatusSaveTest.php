<?php

namespace Tests\Feature;

use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A save from a page left open must not undo what a colleague did since.
 *
 * Every screen that posts to Update Status sends a status for each work it
 * shows, touched or not. So a page drawn before a colleague returned the file
 * sent the old status back with the next remark, and the work went back to it:
 * the return undone, its refund taken off the ledger and the customer charged
 * again. An approval made meanwhile lost its date the same way.
 *
 * Pages now send what each work said when they were drawn (`was`). A work the
 * post leaves alone is not touched; one it asks something of that has moved
 * since stops the save and says where it stands now.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class StaleStatusSaveTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private WorkTypeModel $tr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Stale Save Admin';
        $this->admin->email = 'stale-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = new PartyModel;
        $this->customer->party_type = 'customer';
        $this->customer->name = 'Customer '.uniqid().' stale';
        $this->customer->mobile = '93500'.random_int(10000, 99999);
        $this->customer->is_active = 1;
        $this->customer->save();

        $this->tr = new WorkTypeModel;
        $this->tr->name = 'TR '.uniqid();
        $this->tr->is_active = 1;
        $this->tr->save();
    }

    /** A folder of one work, charged to the customer. */
    private function file(string $status = WorkFileModel::IN_OFFICE): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-ST-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01ST'.random_int(1000, 9999);
        $file->work_type_id = $this->tr->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 3000;
        $file->status = $status;
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $this->tr->id;
        $item->customer_amount = 3000;
        $item->status = $status;
        $item->save();

        $file->syncLedger();

        return $file->fresh();
    }

    private function job(WorkFileModel $file): WorkFileItemModel
    {
        return WorkFileItemModel::where('work_file_id', $file->id)->firstOrFail();
    }

    /** A colleague moves the work on the board, from their own fresh page. */
    private function colleagueMoves(WorkFileModel $file, string $to): void
    {
        $job = $this->job($file);

        $this->actingAs($this->admin)->post(route('workfile.status'), [
            'statuses' => [$job->id => $to],
            'was' => [$job->id => $job->status],
        ])->assertSessionHasNoErrors();

        $this->assertSame($to, $job->fresh()->status, 'the premise: the colleague\'s move went through');
    }

    /** The post a page makes: [job id => [status, was, remark]]. */
    private function save(array $works, array $extra = [])
    {
        $post = ['statuses' => [], 'was' => [], 'remarks' => []];

        foreach ($works as $id => [$status, $was, $remark]) {
            $post['statuses'][$id] = $status;

            if ($was !== null) {
                $post['was'][$id] = $was;
            }

            $post['remarks'][$id] = $remark;
        }

        return $this->actingAs($this->admin)
            ->from(route('workfile.status'))
            ->post(route('workfile.status'), array_filter($post) + $extra);
    }

    private function refunds(WorkFileModel $file): int
    {
        return PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', 'customer_return')->count();
    }

    // ------------------------------------------------ the two reported cases

    /**
     * The reported case: returned by a colleague, then a remark from a page
     * that still showed it In Office.
     */
    public function test_a_return_made_since_is_not_undone_by_a_remark_from_an_old_page(): void
    {
        $file = $this->file();
        $job = $this->job($file);

        $this->actingAs($this->admin)->post(route('workfile.customerreturn'), [
            'returned_on' => now()->toDateString(),
            'files' => [$file->id],
            'remark' => 'Customer took the papers back',
        ])->assertSessionHasNoErrors();

        $this->assertSame(WorkFileModel::RETURNED, $job->fresh()->status, 'the premise: it was returned');
        $this->assertSame(1, $this->refunds($file), 'the premise: the refund was credited');
        $balance = PartyLedgerModel::currentBalance($this->customer->id);

        $this->save([$job->id => [WorkFileModel::IN_OFFICE, WorkFileModel::IN_OFFICE, 'Customer called']])
            ->assertSessionHas('error');

        $this->assertSame(WorkFileModel::RETURNED, $job->fresh()->status, 'the return was undone');
        $this->assertNotNull($file->fresh()->returned_on, 'the return lost its date');
        $this->assertSame(1, $this->refunds($file), 'the refund was taken off the ledger');
        $this->assertSame($balance, PartyLedgerModel::currentBalance($this->customer->id), 'the customer was charged again');
    }

    public function test_an_approval_made_since_is_not_undone_by_a_remark_from_an_old_page(): void
    {
        $file = $this->file(WorkFileModel::DISPATCHED);
        $job = $this->job($file);

        // A colleague records the approval, with its document and its day.
        $job->status = WorkFileModel::APPROVED;
        $job->approved_on = '2026-09-15';
        $job->approval_screenshot = 'approval-'.uniqid().'.png';
        $job->save();
        $file->load('items');
        $file->rollUp();
        $file->save();

        $this->save([$job->id => [WorkFileModel::DISPATCHED, WorkFileModel::DISPATCHED, 'Chased the RTO']])
            ->assertSessionHas('error');

        $job->refresh();

        $this->assertSame(WorkFileModel::APPROVED, $job->status, 'the approval was undone');
        $this->assertSame('2026-09-15', date('Y-m-d', strtotime($job->approved_on)), 'the approval lost its date');
    }

    // --------------------------------------------------- what a stale save says

    /** A move chosen against a status that is no longer true is refused too. */
    public function test_a_move_chosen_against_an_old_status_is_refused(): void
    {
        $file = $this->file();
        $job = $this->job($file);

        $this->colleagueMoves($file, 'under_verification');

        $this->save([$job->id => ['file_dispatch', WorkFileModel::IN_OFFICE, '']])->assertSessionHas('error');

        $this->assertSame('under_verification', $job->fresh()->status);
    }

    public function test_it_names_the_work_and_where_it_stands_now(): void
    {
        $file = $this->file();
        $job = $this->job($file);

        $this->colleagueMoves($file, 'under_verification');

        $this->save([$job->id => [WorkFileModel::IN_OFFICE, WorkFileModel::IN_OFFICE, 'Called the RTO']]);

        $error = session('error');

        $this->assertStringContainsString('Nothing was saved', $error);
        $this->assertStringContainsString($file->file_no, $error);
        $this->assertStringContainsString('now Under Verification', $error);
        $this->assertStringContainsString('Reload', $error);
    }

    /** And nothing else in that post is saved either: it was all made against the old page. */
    public function test_a_stale_save_saves_nothing_at_all(): void
    {
        $stale = $this->file();
        $fresh = $this->file();

        $this->colleagueMoves($stale, 'under_verification');

        $this->save([
            $this->job($stale)->id => [WorkFileModel::IN_OFFICE, WorkFileModel::IN_OFFICE, 'Called'],
            $this->job($fresh)->id => ['file_dispatch', WorkFileModel::IN_OFFICE, ''],
        ])->assertSessionHas('error');

        $this->assertSame(WorkFileModel::IN_OFFICE, $this->job($fresh)->fresh()->status);
    }

    // ----------------------------------------------------- nothing else moved

    public function test_a_move_from_the_status_the_page_showed_still_works(): void
    {
        $file = $this->file();
        $job = $this->job($file);

        $this->save([$job->id => ['under_verification', WorkFileModel::IN_OFFICE, 'Filed']])
            ->assertSessionHas('success');

        $this->assertSame('under_verification', $job->fresh()->status);
    }

    public function test_a_remark_alone_on_an_unchanged_work_is_saved_without_moving_it(): void
    {
        $file = $this->file();
        $job = $this->job($file);

        $this->save([$job->id => [WorkFileModel::IN_OFFICE, WorkFileModel::IN_OFFICE, 'Customer called']])
            ->assertSessionHas('success', 'Remarks saved.');

        $this->assertSame(WorkFileModel::IN_OFFICE, $job->fresh()->status);
    }

    /**
     * The board posts every row. A row the clerk never touched, that a
     * colleague has moved since, must neither block the save nor be put back.
     */
    public function test_the_board_saves_the_row_moved_and_leaves_alone_a_row_changed_since(): void
    {
        $mine = $this->file();
        $theirs = $this->file();

        $this->colleagueMoves($theirs, 'under_verification');

        $this->save([
            $this->job($mine)->id => ['file_dispatch', WorkFileModel::IN_OFFICE, ''],
            // Untouched on the stale board: still showing In Office.
            $this->job($theirs)->id => [WorkFileModel::IN_OFFICE, WorkFileModel::IN_OFFICE, ''],
        ])->assertSessionHas('success');

        $this->assertSame('file_dispatch', $this->job($mine)->fresh()->status, 'the clerk\'s move was lost');
        $this->assertSame('under_verification', $this->job($theirs)->fresh()->status, 'the colleague\'s move was undone');
    }

    /** A page drawn before this change posts no `was`, and is read as it always was. */
    public function test_a_post_from_an_older_page_without_was_behaves_as_before(): void
    {
        $file = $this->file();
        $job = $this->job($file);

        $this->save([$job->id => ['under_verification', null, '']])->assertSessionHas('success');

        $this->assertSame('under_verification', $job->fresh()->status);
    }

    // ------------------------------------ found in review: the other ways in

    /** Two works on one folder, the first cancelled with a reason. */
    private function folderWithACancelledWork(): WorkFileModel
    {
        $file = $this->file();

        $second = new WorkFileItemModel;
        $second->work_file_id = $file->id;
        $second->work_type_id = $this->tr->id;
        $second->customer_amount = 3000;
        $second->status = WorkFileModel::IN_OFFICE;
        $second->save();

        $file->customer_amount = 6000;
        $file->save();
        $file->syncLedger();

        $first = $this->job($file);

        $this->actingAs($this->admin)->post(route('workfile.status'), [
            'statuses' => [$first->id => WorkFileModel::CANCELLED],
            'was' => [$first->id => WorkFileModel::IN_OFFICE],
            'remarks' => [$first->id => 'Customer dropped this one'],
        ])->assertSessionHas('success');

        return $file->fresh();
    }

    /**
     * Return to Customer sends back the works still standing and leaves the
     * cancelled one cancelled. The folder's roll-up counted that cancelled
     * work, read the folder as Approval Done on the next save of anything on
     * it — even a remark from a fresh page — and took the refund away.
     */
    public function test_a_returned_folder_with_a_cancelled_work_stays_returned_whatever_is_saved_on_it(): void
    {
        $file = $this->folderWithACancelledWork();
        [$cancelled, $standing] = WorkFileItemModel::where('work_file_id', $file->id)->orderBy('id')->get()->all();

        $this->actingAs($this->admin)->post(route('workfile.customerreturn'), [
            'returned_on' => now()->toDateString(),
            'files' => [$file->id],
            'remark' => 'Customer took the papers back',
        ])->assertSessionHasNoErrors();

        $this->assertSame(WorkFileModel::RETURNED, $file->fresh()->status, 'the premise: returned');
        $this->assertSame(1, $this->refunds($file));
        $balance = PartyLedgerModel::currentBalance($this->customer->id);

        // A remark on each work in turn, from pages that are up to date.
        foreach ([$cancelled, $standing] as $job) {
            $job->refresh();

            $this->save([$job->id => [$job->status, $job->status, 'Called the customer']])
                ->assertSessionHas('success', 'Remarks saved.');

            $this->assertSame(WorkFileModel::RETURNED, $file->fresh()->status, 'the folder was read as approved');
            $this->assertNotNull($file->fresh()->returned_on, 'the return lost its date');
            $this->assertSame(1, $this->refunds($file), 'the refund was taken off the ledger');
            $this->assertSame($balance, PartyLedgerModel::currentBalance($this->customer->id), 'the customer was charged again');
        }
    }

    /**
     * Stays returned, and never becomes it: a roll-up that turned a folder
     * into a return would have to invent its day and its refund.
     */
    public function test_the_roll_up_keeps_a_return_but_never_invents_one(): void
    {
        $works = collect([
            (object) ['status' => WorkFileModel::CANCELLED],
            (object) ['status' => WorkFileModel::RETURNED],
        ]);

        $this->assertSame(WorkFileModel::RETURNED, WorkFileModel::statusFromItems($works, WorkFileModel::RETURNED));
        $this->assertSame(WorkFileModel::APPROVED, WorkFileModel::statusFromItems($works, WorkFileModel::APPROVED));
        $this->assertSame(WorkFileModel::APPROVED, WorkFileModel::statusFromItems($works));

        // And nothing else about it moved.
        $this->assertSame(WorkFileModel::RETURNED, WorkFileModel::statusFromItems(collect([(object) ['status' => WorkFileModel::RETURNED]])));
        $this->assertSame(WorkFileModel::CANCELLED, WorkFileModel::statusFromItems(collect([(object) ['status' => WorkFileModel::CANCELLED]])));
        $this->assertSame(WorkFileModel::APPROVED, WorkFileModel::statusFromItems(collect([
            (object) ['status' => WorkFileModel::CANCELLED], (object) ['status' => WorkFileModel::APPROVED],
        ]), WorkFileModel::RETURNED));
    }

    /**
     * A folder the old roll-up already un-returned: works returned, folder
     * Approval Done, return date and refund gone. The agreed refund is recorded
     * nowhere, so the roll-up must not make one up — today, in full — on the
     * next save. It is left as it is, and the audit names it.
     */
    private function alreadyUnreturned(): WorkFileModel
    {
        $file = $this->folderWithACancelledWork();

        WorkFileItemModel::where('work_file_id', $file->id)
            ->where('status', '<>', WorkFileModel::CANCELLED)
            ->update(['status' => WorkFileModel::RETURNED]);

        // What the old roll-up left behind, written past the model's hooks.
        \Illuminate\Support\Facades\DB::table('work_file')->where('id', $file->id)->update([
            'status' => WorkFileModel::APPROVED, 'returned_on' => null, 'returned_amount' => null,
        ]);

        return $file->fresh();
    }

    public function test_a_folder_already_unreturned_is_not_given_an_invented_refund(): void
    {
        $file = $this->alreadyUnreturned();
        $cancelled = WorkFileItemModel::where('work_file_id', $file->id)->where('status', WorkFileModel::CANCELLED)->first();
        $balance = PartyLedgerModel::currentBalance($this->customer->id);

        $this->save([$cancelled->id => [WorkFileModel::CANCELLED, WorkFileModel::CANCELLED, 'Customer called']])
            ->assertSessionHas('success');

        $file->refresh();

        $this->assertSame(WorkFileModel::APPROVED, $file->status, 'the roll-up turned it into a return');
        $this->assertNull($file->returned_on, 'a return date was made up');
        $this->assertSame(0, $this->refunds($file), 'a refund was made up');
        $this->assertSame($balance, PartyLedgerModel::currentBalance($this->customer->id));
    }

    public function test_the_audit_names_a_folder_already_unreturned(): void
    {
        $this->alreadyUnreturned();

        $this->artisan('files:audit')
            ->expectsOutputToContain('a later save lost the return and its refund')
            ->run();
    }

    /**
     * A row the clerk never touched, approved since on the edit screen (which
     * keeps the screenshot on the folder, not the work), must not refuse the
     * clerk's save of another row for want of a screenshot.
     */
    public function test_an_untouched_row_approved_since_does_not_refuse_the_save(): void
    {
        $mine = $this->file();
        $theirs = $this->file('under_verification');
        $job = $this->job($theirs);

        $job->status = WorkFileModel::APPROVED;
        $job->approved_on = '2026-09-15';
        $job->save();

        $this->save([
            $this->job($mine)->id => ['file_dispatch', WorkFileModel::IN_OFFICE, ''],
            $job->id => ['under_verification', 'under_verification', ''],
        ])->assertSessionHas('success');

        $this->assertSame('file_dispatch', $this->job($mine)->fresh()->status);
        $this->assertSame(WorkFileModel::APPROVED, $job->fresh()->status);
    }

    /** An approved work as the board draws it: [id, the date shown]. */
    private function approvedWork(string $on): WorkFileItemModel
    {
        $file = $this->file(WorkFileModel::DISPATCHED);
        $job = $this->job($file);

        $job->status = WorkFileModel::APPROVED;
        $job->approved_on = $on;
        $job->approval_screenshot = 'approval-'.uniqid().'.png';
        $job->save();

        return $job->fresh();
    }

    /** The board posts every approved row's date back with any remark on it. */
    private function boardSave(WorkFileItemModel $job, string $date, string $drawn, string $remark = '')
    {
        return $this->actingAs($this->admin)
            ->from(route('workfile.status'))
            ->post(route('workfile.status'), array_filter([
                'statuses' => [$job->id => WorkFileModel::APPROVED],
                'was' => [$job->id => WorkFileModel::APPROVED],
                'approved_on' => [$job->id => $date],
                'was_approved_on' => [$job->id => $drawn],
                'remarks' => [$job->id => $remark],
            ]));
    }

    public function test_a_remark_from_an_old_board_keeps_an_approval_date_corrected_since(): void
    {
        $job = $this->approvedWork('2026-09-10');

        // A colleague corrects the day.
        $job->approved_on = '2026-09-12';
        $job->save();

        // The old board still shows the 10th, and only a remark is typed.
        $this->boardSave($job, '2026-09-10', '2026-09-10', 'Customer collected')
            ->assertSessionHas('success', 'Remarks saved.');

        $this->assertSame('2026-09-12', date('Y-m-d', strtotime($job->fresh()->approved_on)));
    }

    public function test_an_approval_date_corrected_on_the_board_is_saved(): void
    {
        $job = $this->approvedWork('2026-09-10');

        $this->boardSave($job, '2026-09-11', '2026-09-10')->assertSessionHas('success');

        $this->assertSame('2026-09-11', date('Y-m-d', strtotime($job->fresh()->approved_on)));
    }

    public function test_an_approval_date_corrected_over_one_changed_since_is_refused(): void
    {
        $job = $this->approvedWork('2026-09-10');

        $job->approved_on = '2026-09-12';
        $job->save();

        $this->boardSave($job, '2026-09-11', '2026-09-10')->assertSessionHas('error');

        $this->assertStringContainsString('approved 12-09-2026', session('error'));
        $this->assertSame('2026-09-12', date('Y-m-d', strtotime($job->fresh()->approved_on)));
    }

    /**
     * Approved long ago with no date recorded: the board's box suggests today,
     * but nothing is stored. Typing the real day is entering it, not changing
     * one somebody else set.
     */
    public function test_a_date_entered_for_an_approval_that_never_had_one_is_saved(): void
    {
        $job = $this->approvedWork('2026-09-10');
        \Illuminate\Support\Facades\DB::table('work_file_item')->where('id', $job->id)->update(['approved_on' => null]);

        $this->boardSave($job, '2026-09-05', '', 'Found the approval mail')->assertSessionHas('success');

        $this->assertSame('2026-09-05', date('Y-m-d', strtotime($job->fresh()->approved_on)));
    }

    /**
     * An old approval with no date, untouched, while the clerk moves another
     * work. The board no longer sends the date box it drew with a suggestion
     * of today, so the row is left out: not stamped with an invented date, and
     * not refusing the clerk's save for want of a screenshot.
     */
    public function test_an_untouched_approval_with_no_date_is_left_out_of_the_save(): void
    {
        $mine = $this->file();
        $old = $this->approvedWork('2026-09-10');
        \Illuminate\Support\Facades\DB::table('work_file_item')->where('id', $old->id)->update([
            'approved_on' => null, 'approval_screenshot' => null,
        ]);

        $this->actingAs($this->admin)->from(route('workfile.status'))->post(route('workfile.status'), [
            'statuses' => [$this->job($mine)->id => 'under_verification', $old->id => WorkFileModel::APPROVED],
            'was' => [$this->job($mine)->id => WorkFileModel::IN_OFFICE, $old->id => WorkFileModel::APPROVED],
            'was_approved_on' => [$old->id => ''],
        ])->assertSessionHas('success');

        $this->assertSame('under_verification', $this->job($mine)->fresh()->status);
        $this->assertNull($old->fresh()->approved_on, 'an approval date was made up');
    }

    /** A work not approved but still carrying an old date, approved now: judged by its status, which has not moved. */
    public function test_approving_a_work_with_a_leftover_date_is_not_mistaken_for_a_stale_page(): void
    {
        $file = $this->file('under_verification');
        $job = $this->job($file);
        \Illuminate\Support\Facades\DB::table('work_file_item')->where('id', $job->id)->update([
            'approved_on' => '2026-09-03', 'approval_screenshot' => 'approval-'.uniqid().'.png',
        ]);

        $this->actingAs($this->admin)->from(route('workfile.status'))->post(route('workfile.status'), [
            'statuses' => [$job->id => WorkFileModel::APPROVED],
            'was' => [$job->id => 'under_verification'],
            'approved_on' => [$job->id => '2026-09-18'],
            // Whatever date the page drew — here none, as a page drawn by an
            // older board would say — a work being moved is judged by its status.
            'was_approved_on' => [$job->id => ''],
        ])->assertSessionHas('success');

        $this->assertSame(WorkFileModel::APPROVED, $job->fresh()->status);
        $this->assertSame('2026-09-18', date('Y-m-d', strtotime($job->fresh()->approved_on)));
    }

    // ----------------------------------------------- the Work Report says so

    public function test_the_work_report_shows_that_a_save_went_through(): void
    {
        $file = $this->file();
        $job = $this->job($file);

        $this->actingAs($this->admin)
            ->followingRedirects()
            ->post(route('workfile.status'), [
                'statuses' => [$job->id => 'under_verification'],
                'was' => [$job->id => WorkFileModel::IN_OFFICE],
                'return_to' => route('report.files', ['party_type' => 'customer']),
            ])
            ->assertOk()
            ->assertSee('1 work updated.');
    }

    public function test_the_work_report_shows_why_a_save_was_refused(): void
    {
        $file = $this->file();
        $job = $this->job($file);

        $this->colleagueMoves($file, 'under_verification');

        $this->actingAs($this->admin)
            ->from(route('report.files', ['party_type' => 'customer']))
            ->followingRedirects()
            ->post(route('workfile.status'), [
                'statuses' => [$job->id => WorkFileModel::IN_OFFICE],
                'was' => [$job->id => WorkFileModel::IN_OFFICE],
                'remarks' => [$job->id => 'Called'],
                'return_to' => route('report.files', ['party_type' => 'customer']),
            ])
            ->assertOk()
            ->assertSee('Nothing was saved');
    }
}
