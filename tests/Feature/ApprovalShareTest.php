<?php

namespace Tests\Feature;

use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Telling a customer on WhatsApp that their work was approved.
 *
 * The message is written in the browser and tested there
 * (approval-share.test.js). This is when it is offered and what it is handed:
 * after any save that moved a work into Approval Done — on the board, in the
 * Work Report's or In-house Work's dialog, or on the edit screen — one notice
 * per file, to that file's customer, and never a vendor or a remark.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class ApprovalShareTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Approval Share Admin';
        $this->admin->email = 'approval-share-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->tr = $this->workType('TR');
        $this->hpa = $this->workType('HPA');
    }

    private function workType(string $name): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    private function party(string $type = 'customer', ?string $whatsapp = null): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' approved';
        $party->mobile = '93300'.random_int(10000, 99999);
        $party->whatsapp = $whatsapp;
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /**
     * A folder under verification, one work per type, each with its approval
     * document already on file so the board will take the approval.
     *
     * @param  array<int, WorkTypeModel>  $types
     */
    private function file(PartyModel $customer, array $types, ?PartyModel $vendor = null): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-AS-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01AS'.random_int(1000, 9999);
        $file->work_type_id = $types[0]->id;
        $file->customer_id = $customer->id;
        $file->customer_amount = 3000 * count($types);
        $file->status = 'under_verification';
        $file->save();

        foreach ($types as $type) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = 3000;
            $item->vendor_id = $vendor?->id;
            $item->status = 'under_verification';
            $item->approval_screenshot = 'approval-'.uniqid().'.png';
            $item->save();
        }

        $file->syncLedger();

        return $file->fresh();
    }

    private function job(WorkFileModel $file, WorkTypeModel $type): WorkFileItemModel
    {
        return WorkFileItemModel::where('work_file_id', $file->id)->where('work_type_id', $type->id)->firstOrFail();
    }

    /** Approve works on the board: [item, remark]. */
    private function approve(array $jobs, array $extra = [])
    {
        $post = ['statuses' => [], 'was' => [], 'approved_on' => [], 'remarks' => []];

        foreach ($jobs as [$job, $remark]) {
            $post['statuses'][$job->id] = WorkFileModel::APPROVED;
            $post['was'][$job->id] = $job->status;
            $post['approved_on'][$job->id] = '2026-09-18';
            $post['remarks'][$job->id] = $remark;
        }

        return $this->actingAs($this->admin)
            ->from(route('workfile.status'))
            ->post(route('workfile.status'), $post + $extra);
    }

    // ------------------------------------------------------------- offered

    public function test_approving_on_the_board_offers_to_tell_the_customer(): void
    {
        $customer = $this->party();
        $file = $this->file($customer, [$this->tr]);

        $this->approve([[$this->job($file, $this->tr), '']])->assertSessionHas('success');

        $this->assertSame([[
            'id' => $file->id,
            'fileNo' => $file->file_no,
            'vehicle' => $file->registration_no,
            'customer' => $customer->name,
            'mobile' => $customer->mobile,
            'works' => [['work' => $this->tr->name, 'on' => '18-09-2026']],
            'pending' => [],
            'papersReady' => true,
            'balance' => 3000.0,
        ]], session('approved'));
    }

    /** Half a folder through: the message says what is still being worked on. */
    public function test_a_part_approval_names_what_is_still_in_progress(): void
    {
        $file = $this->file($this->party(), [$this->tr, $this->hpa]);

        $this->approve([[$this->job($file, $this->tr), '']]);

        $notice = session('approved')[0];

        $this->assertSame([$this->tr->name], array_column($notice['works'], 'work'));
        $this->assertSame([$this->hpa->name], $notice['pending']);
        $this->assertFalse($notice['papersReady'], 'papers said ready with work still in progress');
    }

    public function test_two_files_approved_in_one_save_are_two_messages_to_their_own_customers(): void
    {
        $first = $this->party();
        $second = $this->party();
        $a = $this->file($first, [$this->tr]);
        $b = $this->file($second, [$this->tr]);

        $this->approve([[$this->job($a, $this->tr), ''], [$this->job($b, $this->tr), '']]);

        $this->assertSame([$first->name, $second->name], array_column(session('approved'), 'customer'));
    }

    public function test_it_goes_to_a_saved_whatsapp_number(): void
    {
        $customer = $this->party('customer', '94310'.random_int(10000, 99999));
        $file = $this->file($customer, [$this->tr]);

        $this->approve([[$this->job($file, $this->tr), '']]);

        $this->assertSame($customer->whatsapp, session('approved')[0]['mobile']);
    }

    /** The edit screen approves a folder of one work, and offers the same. */
    public function test_approving_on_the_edit_screen_offers_it_too(): void
    {
        $customer = $this->party();
        $file = $this->file($customer, [$this->tr]);
        $file->approval_screenshot = 'approval-'.uniqid().'.png';
        $file->save();

        $this->actingAs($this->admin)->post(route('workfile.edit', $file->id), [
            'file_no' => $file->file_no,
            'received_date' => '2026-09-01',
            'work_type_id' => $this->tr->id,
            'registration_no' => $file->registration_no,
            'customer_id' => $customer->id,
            'customer_amount' => 3000,
            'status' => WorkFileModel::APPROVED,
        ])->assertSessionHas('success');

        $this->assertSame($file->file_no, session('approved')[0]['fileNo']);
    }

    /** The edit post a page makes for a folder: its works as drawn, and what else is asked. */
    private function editPost(WorkFileModel $file, array $extra): array
    {
        $post = [
            'file_no' => $file->file_no,
            'received_date' => '2026-09-01',
            'work_type_id' => $file->work_type_id,
            'registration_no' => $file->registration_no,
            'customer_id' => $file->customer_id,
            'customer_amount' => $file->customer_amount,
            'status' => $file->status,
        ];

        return array_merge($post, $extra);
    }

    /**
     * Found in review: approving the one work on the edit screen while adding
     * another in the same save left the folder Partly Approved, and the
     * message was not offered. It is, naming the new work as in progress.
     */
    public function test_approving_on_the_edit_screen_while_adding_a_work_offers_it(): void
    {
        $file = $this->file($this->party(), [$this->tr]);
        $file->approval_screenshot = 'approval-'.uniqid().'.png';
        $file->save();

        $this->actingAs($this->admin)->post(route('workfile.edit', $file->id), $this->editPost($file, [
            'status' => WorkFileModel::APPROVED,
            'new_works' => [['work_type_id' => $this->hpa->id, 'amount' => 1500]],
        ]))->assertSessionHas('success');

        $notice = session('approved')[0];

        $this->assertSame([$this->tr->name], array_column($notice['works'], 'work'));
        $this->assertSame([$this->hpa->name], $notice['pending']);
    }

    // --------------------------------------------------------- not offered

    /**
     * Found in review: taking the last pending work off a folder turns it
     * Approval Done with nothing approved in that save. The approval made
     * weeks before, and offered then, is not offered again as news.
     */
    public function test_removing_the_last_pending_work_offers_nothing(): void
    {
        $file = $this->file($this->party(), [$this->tr, $this->hpa]);
        $this->approve([[$this->job($file, $this->tr), '']]);

        $file->refresh();
        $hpa = $this->job($file, $this->hpa);
        $items = [];

        foreach ($file->items as $item) {
            $items[$item->id] = ['work_type_id' => $item->work_type_id, 'customer_amount' => $item->customer_amount];
        }

        $this->actingAs($this->admin)->post(route('workfile.edit', $file->id), $this->editPost($file, [
            'items' => $items,
            'remove_works' => [$hpa->id],
        ]))->assertSessionHas('success')->assertSessionMissing('approved');

        $this->assertSame(WorkFileModel::APPROVED, $file->fresh()->status, 'the premise: the folder is now approved');
    }


    public function test_nothing_is_offered_when_nothing_was_approved(): void
    {
        $file = $this->file($this->party(), [$this->tr]);
        $job = $this->job($file, $this->tr);

        $this->actingAs($this->admin)->from(route('workfile.status'))->post(route('workfile.status'), [
            'statuses' => [$job->id => 'part_pesi_required'],
            'was' => [$job->id => 'under_verification'],
        ])->assertSessionHas('success')->assertSessionMissing('approved');
    }

    /** Already approved and only a remark added: nothing newly approved to tell. */
    public function test_a_remark_on_work_already_approved_offers_nothing(): void
    {
        $file = $this->file($this->party(), [$this->tr]);
        $this->approve([[$this->job($file, $this->tr), '']]);

        $job = $this->job($file, $this->tr);

        $this->actingAs($this->admin)->from(route('workfile.status'))->post(route('workfile.status'), [
            'statuses' => [$job->id => WorkFileModel::APPROVED],
            'was' => [$job->id => WorkFileModel::APPROVED],
            'remarks' => [$job->id => 'Customer informed by phone'],
        ])->assertSessionHas('success')->assertSessionMissing('approved');
    }

    /** A customer is never told who did the work, nor what the office wrote. */
    public function test_never_a_vendor_or_a_remark(): void
    {
        $vendor = $this->party('vendor');
        $file = $this->file($this->party(), [$this->tr], $vendor);

        $this->approve([[$this->job($file, $this->tr), 'Chased '.$vendor->name.' twice, slow again']]);

        $this->assertNotEmpty(session('approved'), 'the premise: there is a message to check');

        $said = json_encode(session('approved'));

        $this->assertStringNotContainsString($vendor->name, $said);
        $this->assertStringNotContainsString('slow again', $said);
    }

    // ------------------------------------------------------------ the page

    public function test_the_page_the_save_lands_on_offers_it_once(): void
    {
        $file = $this->file($this->party(), [$this->tr]);
        $report = route('report.files', ['party_type' => 'customer']);

        $this->approve([[$this->job($file, $this->tr), '']], ['return_to' => $report])->assertRedirect($report);

        $this->actingAs($this->admin)->get($report)->assertOk()->assertSee('data-vue="vue-approval-share"', false);
        $this->actingAs($this->admin)->get($report)->assertOk()->assertDontSee('data-vue="vue-approval-share"', false);
    }

    /**
     * What the page hands the component is what it takes. VueMountTest only
     * sees screens as they first load, and this appears only after a save.
     */
    public function test_the_page_hands_the_component_exactly_the_props_it_declares(): void
    {
        $file = $this->file($this->party(), [$this->tr]);
        $this->approve([[$this->job($file, $this->tr), '']]);

        $html = $this->actingAs($this->admin)->get(route('workfile.status'))->assertOk()->getContent();

        preg_match('#data-vue="vue-approval-share" data-props="(.*?)"#s', $html, $mount);
        $this->assertNotEmpty($mount, 'the board drew no approval message');
        $passed = array_keys(json_decode(html_entity_decode($mount[1], ENT_QUOTES, 'UTF-8'), true));

        $this->assertMatchesRegularExpression(
            "#'vue-approval-share':\s*ApprovalShare,#",
            file_get_contents(resource_path('js/mounts.js'))
        );

        preg_match('#defineProps\(\{(.*?)\n\}\);#s', file_get_contents(resource_path('js/components/ApprovalShare.vue')), $block);
        preg_match_all('#^\s{4}(\w+):\s*\{([^}]*)\}#m', $block[1], $props, PREG_SET_ORDER);

        $declared = [];

        foreach ($props as $prop) {
            $declared[$prop[1]] = str_contains($prop[2], 'required: true');
        }

        $this->assertSame([], array_values(array_diff($passed, array_keys($declared))), 'passed but not declared');
        $this->assertSame([], array_values(array_diff(array_keys(array_filter($declared)), $passed)), 'required but not passed');
    }
}
