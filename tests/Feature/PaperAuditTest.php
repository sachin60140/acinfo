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
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Step 2: a file's papers checked, and the pendency that follows from it.
 *
 * The rules worth holding:
 *  - a checklist is every paper the file's unfinished work needs, a shared
 *    paper once, and nothing is saved with a line left unanswered;
 *  - a pending paper puts only the works that need it into Paper Pendency, and
 *    marking it received takes them out again;
 *  - once checked, a file's list stands still — editing the master list later
 *    does not rewrite it — but a work added afterwards brings its own papers;
 *  - Paper Pendency is never set or cleared by hand.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class PaperAuditTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private WorkTypeModel $hpt;

    private WorkTypeModel $tr;

    /** @var array<string, PaperTypeModel> */
    private array $paper = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Audit Admin';
        $this->admin->email = 'audit-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');

        foreach (['RC', 'Form 35', 'Bank NOC', 'Form 29', 'Form 30', 'Undertaking'] as $i => $name) {
            $paper = new PaperTypeModel;
            $paper->name = $name.' '.uniqid();
            $paper->sort = $i + 1;
            $paper->save();
            $this->paper[$name] = $paper;
        }

        $this->hpt = $this->workType('HPT', ['RC' => 'required', 'Form 35' => 'required', 'Bank NOC' => 'required']);
        $this->tr = $this->workType('TR', ['RC' => 'required', 'Form 29' => 'required', 'Form 30' => 'required', 'Undertaking' => 'optional']);
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' for audit';
        $party->mobile = '93200'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function workType(string $name, array $needs): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->is_active = 1;
        $type->save();

        foreach ($needs as $paper => $need) {
            $this->paper[$paper]->setNeeds([$type->id => $need]);
        }

        return $type;
    }

    /** A received folder with one work per type given. */
    private function file(array $types, array $attributes = []): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-AU-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01AU'.random_int(1000, 9999);
        $file->work_type_id = $types[0]->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 1000 * count($types);
        $file->status = WorkFileModel::IN_OFFICE;
        $file->forceFill($attributes);
        $file->save();

        foreach ($types as $type) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = 1000;
            $item->status = $attributes['status'] ?? WorkFileModel::IN_OFFICE;
            $item->save();
        }

        return $file->fresh();
    }

    private function id(string $paper): int
    {
        return $this->paper[$paper]->id;
    }

    /** Post a checklist: paper => state, or paper => [state, note, office_note]. */
    private function check(WorkFileModel $file, array $answers)
    {
        $papers = [];

        foreach ($answers as $paper => $answer) {
            [$state, $note, $office] = array_pad((array) $answer, 3, null);
            $papers[$this->id($paper)] = ['state' => $state, 'note' => $note, 'office_note' => $office];
        }

        return $this->actingAs($this->admin)->post(route('workfile.papers', $file->id), ['papers' => $papers]);
    }

    private function itemFor(WorkFileModel $file, WorkTypeModel $type): WorkFileItemModel
    {
        return WorkFileItemModel::where('work_file_id', $file->id)->where('work_type_id', $type->id)->firstOrFail();
    }

    private function queue(): array
    {
        return collect($this->actingAs($this->admin)->getJson(route('workfile.paperaudit'))->assertOk()->json('props.toCheck'))
            ->pluck('id')->all();
    }

    private function allIn(): array
    {
        return ['RC' => 'received', 'Form 35' => 'received', 'Bank NOC' => 'received',
            'Form 29' => 'received', 'Form 30' => 'received', 'Undertaking' => 'not_needed'];
    }

    // ------------------------------------------------------------------ the list

    public function test_a_received_file_waits_for_its_papers_to_be_checked(): void
    {
        $file = $this->file([$this->hpt]);

        $this->assertContains($file->id, $this->queue());

        $row = collect($this->actingAs($this->admin)
            ->getJson(route('workfile.index', ['status' => WorkFileModel::AWAITING_AUDIT]))
            ->assertOk()->json('props.rows'))->firstWhere('id', $file->id);

        $this->assertNotNull($row, 'not on the files list\'s Papers to check view');
        $this->assertStringContainsString('Papers to check', $row['works_note']);
    }

    /**
     * A file already in Paper Pendency before checklists existed carries its
     * missing papers in a remark and in Details. Whoever checks it sees both.
     */
    public function test_what_was_noted_before_the_checklist_is_shown(): void
    {
        $file = $this->file([$this->hpt], ['description' => 'Without Challan & Affidavit']);
        $this->actingAs($this->admin);
        $file->logStatus($file->status, 'NOC awaited from Mannapuram');

        $row = collect($this->actingAs($this->admin)->getJson(route('workfile.paperaudit'))->json('props.toCheck'))
            ->firstWhere('id', $file->id);

        $this->assertSame('Without Challan & Affidavit', $row['description']);
        $this->assertSame('NOC awaited from Mannapuram', $row['last_remark']);

        $header = $this->actingAs($this->admin)->getJson(route('workfile.papers', $file->id))->json('props.file');

        $this->assertSame('Without Challan & Affidavit', $header['description']);
        $this->assertSame('NOC awaited from Mannapuram', $header['last_remark']);
    }

    /** One RC for both works, marked with both; each work's own papers once. */
    public function test_the_checklist_is_every_paper_once_with_the_works_that_need_it(): void
    {
        $file = $this->file([$this->hpt, $this->tr]);

        $lines = collect($this->actingAs($this->admin)
            ->getJson(route('workfile.papers', $file->id))->assertOk()->json('props.lines'))
            ->keyBy('paper_type_id');

        $this->assertCount(6, $lines);
        $this->assertCount(2, $lines[$this->id('RC')]['works']);
        $this->assertSame([$this->hpt->name], $lines[$this->id('Form 35')]['works']);

        // Required starts unanswered; only-if-applicable starts as not needed.
        $this->assertNull($lines[$this->id('Form 30')]['state']);
        $this->assertSame('not_needed', $lines[$this->id('Undertaking')]['state']);
        $this->assertFalse($lines[$this->id('Undertaking')]['required']);
    }

    public function test_a_line_left_unanswered_is_refused_and_nothing_is_saved(): void
    {
        $file = $this->file([$this->hpt]);

        $this->check($file, ['RC' => 'received', 'Form 35' => 'received'])
            ->assertSessionHasErrors('papers.'.$this->id('Bank NOC').'.state');

        $this->assertSame(0, WorkFilePaperModel::where('work_file_id', $file->id)->count());
        $this->assertNull($this->itemFor($file, $this->hpt)->papers_audited_at);
    }

    public function test_a_required_paper_marked_not_needed_must_say_why(): void
    {
        $file = $this->file([$this->hpt]);

        $this->check($file, ['RC' => 'received', 'Form 35' => 'received', 'Bank NOC' => 'not_needed'])
            ->assertSessionHasErrors('papers.'.$this->id('Bank NOC').'.note');

        $this->check($file, ['RC' => 'received', 'Form 35' => 'received', 'Bank NOC' => ['not_needed', null, 'RTO accepted the closure letter']])
            ->assertSessionHasNoErrors();

        $this->assertSame('not_needed', WorkFilePaperModel::where('work_file_id', $file->id)
            ->where('paper_type_id', $this->id('Bank NOC'))->value('state'));
    }

    // ---------------------------------------------------------- saving the list

    public function test_everything_in_leaves_the_file_ready_and_off_the_queue(): void
    {
        $file = $this->file([$this->hpt, $this->tr]);

        $this->check($file, $this->allIn())->assertSessionHasNoErrors()->assertSessionHas('success');

        $file->refresh();

        $this->assertSame(WorkFileModel::IN_OFFICE, $file->status);
        $this->assertNotContains($file->id, $this->queue());
        $this->assertNotNull($this->itemFor($file, $this->hpt)->papers_audited_at);

        // Received today, and said so on the history.
        $this->assertSame(now()->toDateString(), substr((string) WorkFilePaperModel::where('work_file_id', $file->id)
            ->where('paper_type_id', $this->id('RC'))->value('received_on'), 0, 10));

        $entry = $file->statusLog()->where('event', WorkFileModel::PAPERS)->first();
        $this->assertSame('Papers checked. All papers received.', $entry->remark);
    }

    /**
     * A missing Form 30 holds the transfer, not the hypothecation removal —
     * and the folder, rolled up from its works, says papers are pending.
     */
    public function test_a_pending_paper_holds_only_the_works_that_need_it(): void
    {
        $file = $this->file([$this->hpt, $this->tr]);

        $this->check($file, ['Form 30' => ['pending', 'Buyer has not signed']] + $this->allIn())
            ->assertSessionHasNoErrors();

        $this->assertSame(WorkFileModel::PAPER_PENDENCY, $this->itemFor($file, $this->tr)->status);
        $this->assertSame(WorkFileModel::IN_OFFICE, $this->itemFor($file, $this->hpt)->status);

        $rows = collect($this->actingAs($this->admin)
            ->getJson(route('workfile.index', ['status' => WorkFileModel::PAPERS_PENDING]))
            ->json('props.rows'))->keyBy('id');

        $this->assertTrue($rows->has($file->id));
        $this->assertStringContainsString('Papers pending: '.$this->paper['Form 30']->name, $rows[$file->id]['works_note']);

        $entry = $file->fresh()->statusLog()->where('event', WorkFileModel::PAPERS)->first();
        $this->assertSame('Papers checked. Pending: '.$this->paper['Form 30']->name.'.', $entry->remark);
    }

    public function test_the_paper_audit_screen_lists_what_is_pending_and_what_it_holds(): void
    {
        $file = $this->file([$this->hpt, $this->tr]);
        $this->check($file, ['Form 30' => ['pending', 'Buyer has not signed', 'Call Rakesh']] + $this->allIn());

        $line = collect($this->actingAs($this->admin)->getJson(route('workfile.paperaudit'))->json('props.pending'))
            ->firstWhere('file_id', $file->id);

        $this->assertSame($this->paper['Form 30']->name, $line['paper']);
        $this->assertSame([$this->tr->name], $line['works']);
        $this->assertSame('Buyer has not signed', $line['note']);
        $this->assertSame('Call Rakesh', $line['office_note']);
    }

    // ----------------------------------------------------------- mark received

    /**
     * The counter's action: the customer brings Form 30 for one file and a bank
     * NOC for another, and both are ticked off at once.
     */
    public function test_pending_papers_are_marked_received_across_files(): void
    {
        $one = $this->file([$this->tr]);
        $two = $this->file([$this->hpt], ['vendor_id' => $this->party('vendor')->id]);

        $this->check($one, ['Form 30' => 'pending'] + $this->allIn());
        $this->check($two, ['Bank NOC' => 'pending'] + $this->allIn());

        $lines = WorkFilePaperModel::where('state', 'pending')->whereIn('work_file_id', [$one->id, $two->id])->pluck('id')->all();

        $this->actingAs($this->admin)->post(route('workfile.paperaudit'), [
            'received' => $lines,
            'received_on' => '2026-09-10',
        ])->assertRedirect(route('workfile.paperaudit'))->assertSessionHas('success');

        $this->assertSame(0, WorkFilePaperModel::where('state', 'pending')->whereIn('work_file_id', [$one->id, $two->id])->count());
        $this->assertSame('2026-09-10', substr((string) WorkFilePaperModel::find($lines[0])->received_on, 0, 10));

        // Back where it would be without the papers holding it: in the office,
        // or with its vendor if it has one.
        $this->assertSame(WorkFileModel::IN_OFFICE, $this->itemFor($one, $this->tr)->status);
        $this->assertSame(WorkFileModel::DISPATCHED, $this->itemFor($two, $this->hpt)->status);

        $this->assertSame(
            'Received: '.$this->paper['Form 30']->name.'. All papers received.',
            $one->fresh()->statusLog()->where('event', WorkFileModel::PAPERS)->first()->remark
        );
    }

    /** Only lines still pending are touched, so a stale page cannot undo anyone. */
    public function test_marking_received_ignores_what_is_no_longer_pending(): void
    {
        $file = $this->file([$this->hpt]);
        $this->check($file, ['Bank NOC' => ['not_needed', null, 'Waived']] + $this->allIn());

        $notNeeded = WorkFilePaperModel::where('work_file_id', $file->id)->where('paper_type_id', $this->id('Bank NOC'))->first();

        $this->actingAs($this->admin)->post(route('workfile.paperaudit'), [
            'received' => [$notNeeded->id],
            'received_on' => now()->toDateString(),
        ])->assertSessionHas('error');

        $this->assertSame('not_needed', $notNeeded->fresh()->state);
    }

    public function test_marking_received_cannot_be_dated_in_the_future(): void
    {
        $this->actingAs($this->admin)->post(route('workfile.paperaudit'), [
            'received' => [1],
            'received_on' => now()->addDays(3)->toDateString(),
        ])->assertSessionHasErrors('received_on');
    }

    /** The same, done on the file's own checklist. */
    public function test_ticking_a_pending_paper_on_the_checklist_clears_the_pendency(): void
    {
        $file = $this->file([$this->tr]);
        $this->check($file, ['Form 30' => 'pending'] + $this->allIn());

        $this->check($file, $this->allIn())->assertSessionHasNoErrors();

        $this->assertSame(WorkFileModel::IN_OFFICE, $this->itemFor($file, $this->tr)->status);
        $this->assertSame(WorkFileModel::IN_OFFICE, $file->fresh()->status);
    }

    // ------------------------------------------------------- the list stands still

    public function test_a_checked_file_keeps_the_list_it_was_checked_against(): void
    {
        $file = $this->file([$this->hpt]);
        $this->check($file, $this->allIn());

        // The master list changes afterwards: HPT now needs Form 29 too.
        $this->paper['Form 29']->setNeeds([$this->hpt->id => 'required']);

        $this->assertNotContains($file->id, $this->queue(), 'a checked file was sent back to audit');
        $this->assertArrayNotHasKey($this->id('Form 29'), $file->fresh()->paperChecklist());
    }

    /** A work added later asks for its own papers, and not for the RC again. */
    public function test_a_work_added_later_brings_its_own_papers(): void
    {
        $file = $this->file([$this->hpt]);
        $this->check($file, $this->allIn());

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $this->tr->id;
        $item->customer_amount = 1000;
        $item->status = WorkFileModel::IN_OFFICE;
        $item->save();

        $this->assertContains($file->id, $this->queue());

        $lines = $file->fresh()->paperChecklist();

        $this->assertSame('received', $lines[$this->id('RC')]['state'], 'the RC was asked for again');
        $this->assertCount(2, $lines[$this->id('RC')]['works']);
        $this->assertNull($lines[$this->id('Form 29')]['state']);
    }

    /** Cancelling a work drops papers only it needed from everything that counts them. */
    public function test_a_cancelled_work_stops_holding_its_papers(): void
    {
        $file = $this->file([$this->hpt, $this->tr]);
        $this->check($file, ['Form 30' => 'pending'] + $this->allIn());

        $this->actingAs($this->admin)->post(route('workfile.status'), [
            'statuses' => [$this->itemFor($file, $this->tr)->id => WorkFileModel::CANCELLED],
            'remarks' => [$this->itemFor($file, $this->tr)->id => 'Buyer backed out'],
        ])->assertSessionHasNoErrors();

        $filtered = collect($this->actingAs($this->admin)
            ->getJson(route('workfile.index', ['status' => WorkFileModel::PAPERS_PENDING]))
            ->json('props.rows'))->pluck('id');

        $this->assertNotContains($file->id, $filtered);
        $this->assertArrayNotHasKey($this->id('Form 30'), $file->fresh()->paperChecklist());
    }

    public function test_a_retired_paper_is_not_asked_for(): void
    {
        $this->paper['Bank NOC']->is_active = false;
        $this->paper['Bank NOC']->save();

        $file = $this->file([$this->hpt]);

        $this->assertArrayNotHasKey($this->id('Bank NOC'), $file->paperChecklist());
    }

    // ------------------------------------------------- Update Status keeps out

    public function test_paper_pendency_cannot_be_set_by_hand(): void
    {
        $file = $this->file([$this->hpt]);
        $item = $this->itemFor($file, $this->hpt);

        $this->actingAs($this->admin)->post(route('workfile.status'), [
            'statuses' => [$item->id => WorkFileModel::PAPER_PENDENCY],
        ])->assertSessionHas('error');

        $this->assertSame(WorkFileModel::IN_OFFICE, $item->fresh()->status);
    }

    /**
     * Work in Paper Pendency moves like any other work.
     *
     * It used to be held there until the papers were ticked, which trapped
     * every file whose work has no paper list and every file sent out on an
     * override — see PaperPendencyStuckTest. The paper stays pending on the
     * checklist either way; what moves is the work.
     */
    public function test_work_in_paper_pendency_moves_like_any_other(): void
    {
        $file = $this->file([$this->tr]);
        $this->check($file, ['Form 30' => 'pending'] + $this->allIn());
        $item = $this->itemFor($file, $this->tr);

        $this->actingAs($this->admin)->post(route('workfile.status'), [
            'statuses' => [$item->id => WorkFileModel::DISPATCHED],
            'remarks' => [$item->id => 'Vendor took it without Form 30'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(WorkFileModel::DISPATCHED, $item->fresh()->status);
        $this->assertNotEmpty(WorkFileModel::papersNotReady([$file->id]), 'moving the work marked the paper as in');

        $this->actingAs($this->admin)->post(route('workfile.status'), [
            'statuses' => [$item->id => WorkFileModel::CANCELLED],
            'remarks' => [$item->id => 'Customer withdrew'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(WorkFileModel::CANCELLED, $item->fresh()->status);
    }

    /** A work left as it is on the board, while in Paper Pendency, is not a move. */
    public function test_saving_a_remark_on_work_in_paper_pendency_is_allowed(): void
    {
        $file = $this->file([$this->tr]);
        $this->check($file, ['Form 30' => 'pending'] + $this->allIn());
        $item = $this->itemFor($file, $this->tr);

        $this->actingAs($this->admin)->post(route('workfile.status'), [
            'statuses' => [$item->id => WorkFileModel::PAPER_PENDENCY],
            'remarks' => [$item->id => 'Chased the buyer'],
        ])->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------ the edit screen

    public function test_the_edit_screen_sums_up_the_papers(): void
    {
        $file = $this->file([$this->tr]);

        $papers = fn () => $this->actingAs($this->admin)->getJson(route('workfile.edit', $file->id))->json('props.papers');

        $this->assertSame('to_check', $papers()['state']);

        $this->check($file, ['Form 30' => 'pending'] + $this->allIn());
        $this->assertSame('pending', $papers()['state']);
        $this->assertSame([$this->paper['Form 30']->name], $papers()['pending']);

        $this->check($file, $this->allIn());
        $this->assertSame('complete', $papers()['state']);
        $this->assertStringContainsString('/admin/file/'.$file->id.'/papers', $papers()['url']);
    }

    public function test_the_office_history_shows_the_check(): void
    {
        $file = $this->file([$this->tr]);
        $this->check($file, ['Form 30' => 'pending'] + $this->allIn());

        $entry = collect($this->actingAs($this->admin)->getJson(route('workfile.edit', $file->id))->json('props.timeline'))
            ->firstWhere('kind', 'papers');

        $this->assertNotNull($entry);
        $this->assertSame($this->admin->name, $entry['user']);
        $this->assertStringContainsString('Pending:', $entry['remark']);
    }

    public function test_nobody_signed_out_can_reach_it(): void
    {
        $file = $this->file([$this->hpt]);

        $this->get(route('workfile.paperaudit'))->assertRedirect(url('/admin'));
        $this->get(route('workfile.papers', $file->id))->assertRedirect(url('/admin'));
        $this->post(route('workfile.papers', $file->id), ['papers' => []])->assertRedirect(url('/admin'));

        $this->assertSame(0, WorkFilePaperModel::where('work_file_id', $file->id)->count());
    }
}
