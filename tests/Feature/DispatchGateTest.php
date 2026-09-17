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
 * Step 3 follows step 2: a file goes to a vendor once its papers are ready.
 *
 * Ready means checked, and nothing pending. A file that is not can still go —
 * the RTO sometimes takes a paper later — but only with a reason, which the
 * office keeps and the customer never reads. And a file sent early keeps its
 * pending work in Paper Pendency, rather than having the dispatch quietly
 * sweep it into File Dispatch with the rest of the folder.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class DispatchGateTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $vendor;

    private WorkTypeModel $hpt;

    private WorkTypeModel $tr;

    private WorkTypeModel $plain;

    private PaperTypeModel $rc;

    private PaperTypeModel $form30;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Gate Admin';
        $this->admin->email = 'gate-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');
        $this->vendor = $this->party('vendor');

        $this->rc = $this->paper('RC');
        $this->form30 = $this->paper('Form 30');

        $this->hpt = $this->workType('HPT', [$this->rc]);
        $this->tr = $this->workType('TR', [$this->rc, $this->form30]);
        // One the office has not given a paper list: nothing to check, never held.
        $this->plain = $this->workType('Plain', []);
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' for gate';
        $party->mobile = '93400'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function paper(string $name): PaperTypeModel
    {
        $paper = new PaperTypeModel;
        $paper->name = $name.' '.uniqid();
        $paper->save();

        return $paper;
    }

    private function workType(string $name, array $papers): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->is_active = 1;
        $type->save();

        foreach ($papers as $paper) {
            $paper->setNeeds([$type->id => 'required']);
        }

        return $type;
    }

    private function file(array $types): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-GT-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01GT'.random_int(1000, 9999);
        $file->work_type_id = $types[0]->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 1000 * count($types);
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        foreach ($types as $type) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = 1000;
            $item->status = WorkFileModel::IN_OFFICE;
            $item->save();
        }

        $file->syncLedger();

        return $file->fresh();
    }

    private function audit(WorkFileModel $file, bool $form30Pending = false): void
    {
        $answers = [];

        foreach ($file->paperChecklist() as $paperId => $line) {
            $answers[$paperId] = ['state' => 'received'];
        }

        if ($form30Pending) {
            $answers[$this->form30->id] = ['state' => 'pending', 'note' => 'Buyer to sign'];
        }

        $this->actingAs($this->admin);
        $file->savePaperChecklist($answers);
    }

    private function give(array $files, array $overrides = [])
    {
        $amounts = [];

        foreach ($files as $file) {
            foreach ($file->items as $item) {
                $amounts[$item->id] = 500;
            }
        }

        return $this->actingAs($this->admin)->post(route('workfile.assign'), [
            'vendor_id' => $this->vendor->id,
            'vendor_date' => '2026-09-05',
            'files' => array_map(fn ($file) => $file->id, $files),
            'amounts' => $amounts,
            'overrides' => $overrides,
        ]);
    }

    private function statusOf(WorkFileModel $file, WorkTypeModel $type): string
    {
        return WorkFileItemModel::where('work_file_id', $file->id)->where('work_type_id', $type->id)->value('status');
    }

    // ------------------------------------------------------------------ ready

    public function test_a_file_with_its_papers_complete_goes_out_as_before(): void
    {
        $file = $this->file([$this->hpt]);
        $this->audit($file);

        $this->give([$file])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertSame($this->vendor->id, (int) $file->fresh()->vendor_id);
        $this->assertSame(WorkFileModel::DISPATCHED, $file->fresh()->status);
    }

    public function test_a_file_whose_work_needs_no_papers_is_never_held(): void
    {
        $file = $this->file([$this->plain]);

        $this->give([$file])->assertSessionMissing('error');

        $this->assertSame($this->vendor->id, (int) $file->fresh()->vendor_id);
    }

    // ---------------------------------------------------------------- not ready

    public function test_a_file_not_yet_checked_is_held_and_named(): void
    {
        $file = $this->file([$this->hpt]);

        $this->give([$file])->assertSessionHas('error', fn ($message) => str_contains($message, $file->file_no)
            && str_contains($message, 'papers not checked yet'));

        $this->assertNull($file->fresh()->vendor_id);
    }

    public function test_a_file_with_papers_pending_is_held_and_says_which(): void
    {
        $file = $this->file([$this->tr]);
        $this->audit($file, true);

        $this->give([$file])->assertSessionHas('error', fn ($message) => str_contains($message, 'papers pending: '.$this->form30->name));

        $this->assertNull($file->fresh()->vendor_id);
    }

    /** One file held back holds the whole batch, so nothing half-goes. */
    public function test_one_unready_file_holds_the_batch(): void
    {
        $ready = $this->file([$this->hpt]);
        $this->audit($ready);

        $unready = $this->file([$this->hpt]);

        $this->give([$ready, $unready])->assertSessionHas('error');

        $this->assertNull($ready->fresh()->vendor_id);
        $this->assertNull($unready->fresh()->vendor_id);
    }

    public function test_a_blank_reason_is_no_reason(): void
    {
        $file = $this->file([$this->hpt]);

        $this->give([$file], [$file->id => '   '])->assertSessionHas('error');

        $this->assertNull($file->fresh()->vendor_id);
    }

    // ---------------------------------------------------------------- overrides

    /**
     * Sent early with a reason: it goes, the reason is kept, and the work still
     * waiting on Form 30 stays in Paper Pendency while the rest is dispatched.
     */
    public function test_a_file_can_go_early_with_a_reason_and_its_pending_work_stays_held(): void
    {
        $file = $this->file([$this->hpt, $this->tr]);
        $this->audit($file, true);

        $this->give([$file], [$file->id => 'RTO will take Form 30 at verification'])
            ->assertSessionMissing('error');

        $file->refresh();

        $this->assertSame($this->vendor->id, (int) $file->vendor_id);
        $this->assertSame(WorkFileModel::DISPATCHED, $this->statusOf($file, $this->hpt));
        $this->assertSame(WorkFileModel::PAPER_PENDENCY, $this->statusOf($file, $this->tr));
        $this->assertSame(WorkFileModel::PAPER_PENDENCY, $file->status, 'the folder says what is holding it');

        $override = $file->statusLog()->where('event', WorkFileModel::PAPERS_OVERRIDE)->first();

        $this->assertNotNull($override, 'the reason was not kept');
        $this->assertStringContainsString('RTO will take Form 30 at verification', $override->remark);
        $this->assertStringContainsString('papers pending: '.$this->form30->name, $override->remark);
    }

    /** And when the paper comes in, the work joins the rest with the vendor. */
    public function test_a_paper_arriving_after_dispatch_sends_the_work_to_the_vendor(): void
    {
        $file = $this->file([$this->hpt, $this->tr]);
        $this->audit($file, true);
        $this->give([$file], [$file->id => 'Buyer signing tomorrow']);

        WorkFileModel::receivePapers(WorkFilePaperModel::where('work_file_id', $file->id)->where('state', 'pending')->pluck('id')->all());

        $this->assertSame(WorkFileModel::DISPATCHED, $this->statusOf($file, $this->tr));
        $this->assertSame(WorkFileModel::DISPATCHED, $file->fresh()->status);
    }

    /** Why the office sent a file early is the office's business. */
    public function test_the_customer_never_reads_the_reason(): void
    {
        $file = $this->file([$this->hpt]);
        $this->give([$file], [$file->id => 'Owner is a friend of the inspector']);

        $timeline = WorkFileModel::customerTimeline($file->id);

        foreach ($timeline as $entry) {
            $this->assertStringNotContainsString('friend of the inspector', (string) $entry['remark']);
        }

        $latest = WorkFileModel::latestCustomerUpdates([$file->id])[$file->id]['remark'] ?? '';
        $this->assertStringNotContainsString('friend of the inspector', (string) $latest);

        $body = $this->withSession(['customer_id' => $this->customer->id])
            ->get(route('customer.file', $file->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('friend of the inspector', $body);
    }

    // ------------------------------------------------------------------ the screen

    public function test_the_screen_says_which_files_are_not_ready_and_why(): void
    {
        $unchecked = $this->file([$this->hpt]);
        $pending = $this->file([$this->tr]);
        $this->audit($pending, true);
        $ready = $this->file([$this->hpt]);
        $this->audit($ready);

        $files = collect($this->actingAs($this->admin)->getJson(route('workfile.assign'))->assertOk()->json('props.files'))
            ->keyBy('id');

        $this->assertSame('to_check', $files[$unchecked->id]['papers']);
        $this->assertSame('Papers not checked yet', $files[$unchecked->id]['papers_note']);

        $this->assertSame('pending', $files[$pending->id]['papers']);
        $this->assertSame('Papers pending: '.$this->form30->name, $files[$pending->id]['papers_note']);

        $this->assertSame('ready', $files[$ready->id]['papers']);
        $this->assertNull($files[$ready->id]['papers_note']);
        $this->assertStringContainsString('/admin/file/'.$ready->id.'/papers', $files[$ready->id]['papers_url']);
    }

    public function test_a_bounced_batch_keeps_its_reasons(): void
    {
        $one = $this->file([$this->hpt]);
        $two = $this->file([$this->hpt]);

        // $two has no reason, so the batch bounces — with $one's reason kept.
        $this->give([$one, $two], [$one->id => 'Kept reason']);

        $this->assertSame('Kept reason', session()->getOldInput('overrides.'.$one->id));
    }
}
