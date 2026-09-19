<?php

namespace Tests\Feature;

use App\Models\PaperTypeModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkFilePaperModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Files that Paper Pendency would not let go of.
 *
 * Paper Pendency used to be an ordinary status somebody chose by hand. The
 * paper checklist took it over — it is now set and cleared by which papers are
 * ticked — and the board was locked so nobody could contradict the list. What
 * that locking did not account for is the files already sitting in it:
 *
 *  - Work booked under one of the retired combination types (HPT + TR + HPA)
 *    has no paper list at all, so it can never be audited, never gets a
 *    checklist, and the office could not move it anywhere but Cancelled.
 *  - A file given to a vendor on an override was dragged straight back out of
 *    File Dispatch into Paper Pendency, and then frozen there while it sat at
 *    the RTO.
 *
 * The rule that survives is the one that was actually needed: Paper Pendency is
 * never chosen by hand, because it is a claim about a list. Where the work has
 * got to is a different question from whether the office has every paper, and
 * the answer to the second one must not stop anybody answering the first.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class PaperPendencyStuckTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Pendency Admin';
        $this->admin->email = 'pendency-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');
        $this->vendor = $this->party('vendor');
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' for pendency';
        $party->mobile = '93600'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /** A work type, with or without a list of papers behind it. */
    private function workType(bool $withPapers, bool $active = true): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = ($withPapers ? 'Mapped' : 'Combination').' Work '.uniqid();
        $type->is_active = $active ? 1 : 0;
        $type->save();

        if ($withPapers) {
            $paper = new PaperTypeModel;
            $paper->name = 'Form '.uniqid();
            $paper->is_active = 1;
            $paper->save();

            DB::table('work_type_paper')->insert([
                'work_type_id' => $type->id,
                'paper_type_id' => $paper->id,
            ]);
        }

        return $type;
    }

    private function file(WorkTypeModel $type, string $status, bool $withVendor = false): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-PP-'.uniqid();
        $file->received_date = now()->subDays(20)->toDateString();
        $file->registration_no = 'BR05PP'.random_int(1000, 9999);
        $file->work_type_id = $type->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 5000;
        $file->status = $status;

        if ($withVendor) {
            $file->vendor_id = $this->vendor->id;
            $file->vendor_date = now()->subDays(3)->toDateString();
            $file->vendor_amount = 3000;
        }

        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $type->id;
        $item->customer_amount = 5000;
        $item->vendor_amount = $withVendor ? 3000 : null;
        $item->status = $status;
        $item->save();

        $file->syncLedger();

        return $file->fresh();
    }

    /** Audit the file and leave its one paper pending, as the audit screen does. */
    private function leavePending(WorkFileModel $file, WorkTypeModel $type, string $note): void
    {
        $paper = DB::table('work_type_paper')->where('work_type_id', $type->id)->value('paper_type_id');

        $file->savePaperChecklist([
            (int) $paper => ['state' => WorkFilePaperModel::PENDING, 'note' => $note],
        ]);
    }
    private function move(WorkFileModel $file, string $to, array $extra = [])
    {
        $item = $file->items()->first();

        return $this->from(route('workfile.status'))->actingAs($this->admin)->post(route('workfile.status'), array_merge([
            'statuses' => [$item->id => $to],
            'remarks' => [$item->id => 'Moved from the board'],
        ], $extra));
    }

    // ------------------------------------------------------- work with no paper list

    /**
     * The file in the bug report: booked under a combination type, sitting in
     * Paper Pendency, with nothing behind it to tick.
     */
    public function test_work_with_no_paper_list_can_still_be_moved_on(): void
    {
        $file = $this->file($this->workType(false, false), WorkFileModel::PAPER_PENDENCY);

        $this->move($file, WorkFileModel::DISPATCHED)->assertSessionHasNoErrors();

        $this->assertSame(WorkFileModel::DISPATCHED, $file->fresh()->status, 'the file would not leave Paper Pendency');
    }

    /** And the board offers the move, rather than offering it and refusing it. */
    public function test_the_board_offers_every_move_out_of_pendency(): void
    {
        $file = $this->file($this->workType(false, false), WorkFileModel::PAPER_PENDENCY);

        $row = collect($this->actingAs($this->admin)
            ->getJson(route('workfile.status', ['status' => WorkFileModel::PAPER_PENDENCY]))
            ->assertOk()->json('props.files'))->firstWhere('id', $file->id);

        $this->assertNotNull($row, 'the board does not show a file in Paper Pendency');
    }

    /** What is still refused is claiming pendency by hand: that is the list's word. */
    public function test_pendency_still_cannot_be_chosen_by_hand(): void
    {
        $file = $this->file($this->workType(true), WorkFileModel::IN_OFFICE);

        $this->move($file, WorkFileModel::PAPER_PENDENCY);

        $this->assertSame(WorkFileModel::IN_OFFICE, $file->fresh()->status, 'Paper Pendency was set by hand');
    }

    // ---------------------------------------------------------- work already out

    /**
     * A file given to a vendor before its papers were complete stays out.
     *
     * The override exists to say "it went anyway". Pulling it back to Paper
     * Pendency the moment it was dispatched undid the decision and then froze
     * the file while it sat at the RTO.
     */
    public function test_a_file_dispatched_on_an_override_stays_dispatched(): void
    {
        $type = $this->workType(true);
        $file = $this->file($type, WorkFileModel::IN_OFFICE);

        $this->leavePending($file, $type, 'Customer is bringing it');

        $this->assertSame(WorkFileModel::PAPER_PENDENCY, $file->fresh()->status, 'the checklist did not set pendency');

        $this->actingAs($this->admin)->post(route('workfile.assign'), [
            'vendor_id' => $this->vendor->id,
            'vendor_date' => now()->toDateString(),
            'files' => [$file->id],
            'amounts' => [$file->id => 3000],
            'overrides' => [$file->id => 'Vendor agreed to start without it'],
        ])->assertSessionHasNoErrors();

        $file = $file->fresh();

        $this->assertSame(WorkFileModel::DISPATCHED, $file->status, 'the dispatch was undone by the checklist');

        // And the paper is still pending: the papers did not become complete.
        $this->assertNotEmpty(WorkFileModel::papersNotReady([$file->id]), 'the pending paper was forgotten');
    }

    /** Work at the RTO keeps moving while a paper is still pending in the office. */
    public function test_work_at_the_rto_moves_while_a_paper_is_still_pending(): void
    {
        $type = $this->workType(true);
        $file = $this->file($type, WorkFileModel::DISPATCHED, true);

        $this->leavePending($file, $type, 'Still to come');

        $this->assertSame(WorkFileModel::DISPATCHED, $file->fresh()->status,
            'a pending paper dragged work back from the vendor');

        $this->move($file, 'under_verification')->assertSessionHasNoErrors();

        $this->assertSame('under_verification', $file->fresh()->status);
        $this->assertNotEmpty(WorkFileModel::papersNotReady([$file->id]), 'moving the work marked the papers as in');
    }

    // ------------------------------------------------------- the list still rules

    /** Work that has not started still follows the checklist, both ways. */
    public function test_work_in_the_office_still_follows_the_list(): void
    {
        $type = $this->workType(true);
        $file = $this->file($type, WorkFileModel::IN_OFFICE);
        $this->leavePending($file, $type, 'Not brought in');

        $this->assertSame(WorkFileModel::PAPER_PENDENCY, $file->fresh()->status);

        // The customer brings it in: the file goes back to being ordinary work.
        $pending = DB::table('work_file_paper')->where('work_file_id', $file->id)->pluck('id')->all();
        WorkFileModel::receivePapers($pending, now()->toDateString());

        $this->assertSame(WorkFileModel::IN_OFFICE, $file->fresh()->status, 'the file did not come out of pendency');
    }

    /**
     * The board keeps saying which paper is missing.
     *
     * It used to read that off the status, so a file out with a vendor — which
     * is no longer in Paper Pendency, because it is not on the desk — would
     * have stopped saying anything at all. It comes off the checklist now, and
     * names the paper.
     */
    public function test_the_board_says_which_paper_is_missing_wherever_the_work_is(): void
    {
        $type = $this->workType(true);
        $file = $this->file($type, WorkFileModel::DISPATCHED, true);
        $this->leavePending($file, $type, 'Still to come');

        $paper = PaperTypeModel::find(DB::table('work_type_paper')->where('work_type_id', $type->id)->value('paper_type_id'));

        $row = collect($this->actingAs($this->admin)
            ->getJson(route('workfile.status', ['status' => WorkFileModel::DISPATCHED]))
            ->assertOk()->json('props.files'))->firstWhere('id', $file->id);

        $this->assertNotNull($row, 'the board does not show the dispatched file');
        $this->assertStringContainsString($paper->name, (string) $row['pending_papers'],
            'the board stopped saying a paper was missing once the file went out');
    }

    /** And says nothing about papers on a file that has them all. */
    public function test_the_board_is_quiet_when_nothing_is_pending(): void
    {
        $file = $this->file($this->workType(true), WorkFileModel::DISPATCHED, true);

        $row = collect($this->actingAs($this->admin)
            ->getJson(route('workfile.status', ['status' => WorkFileModel::DISPATCHED]))
            ->assertOk()->json('props.files'))->firstWhere('id', $file->id);

        $this->assertNull($row['pending_papers']);
    }
    // ------------------------------------------------------------- finding them

    /**
     * A file nobody can audit is on the audit screen anyway, with the reason.
     *
     * Otherwise it is nowhere: not in the queue to check, because its work has
     * no papers to check against, and not in the pending list, because nothing
     * could ever be written to it.
     */
    public function test_the_audit_screen_names_the_files_it_cannot_help_with(): void
    {
        $file = $this->file($this->workType(false, false), WorkFileModel::PAPER_PENDENCY);

        $stuck = collect($this->actingAs($this->admin)->getJson(route('workfile.paperaudit'))
            ->assertOk()->json('props.stuck'));

        $row = $stuck->firstWhere('id', $file->id);

        $this->assertNotNull($row, 'a file stuck in Paper Pendency is on no screen at all');
        $this->assertSame($file->file_no, $row['file_no']);
        $this->assertStringContainsString('no papers', strtolower((string) $row['why']));
    }

    /** A file with a checklist is not "stuck" — it is in the pending list. */
    public function test_a_file_with_a_checklist_is_not_called_stuck(): void
    {
        $type = $this->workType(true);
        $file = $this->file($type, WorkFileModel::IN_OFFICE);
        $this->leavePending($file, $type, 'Not brought in');

        $page = $this->actingAs($this->admin)->getJson(route('workfile.paperaudit'))->assertOk();

        $this->assertNull(collect($page->json('props.stuck'))->firstWhere('id', $file->id));
        $this->assertNotNull(collect($page->json('props.pending'))->firstWhere('file_id', $file->id));
    }
}
