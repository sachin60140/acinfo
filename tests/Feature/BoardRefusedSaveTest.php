<?php

namespace Tests\Feature;

use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A save from the status board that the server refuses.
 *
 * The board is one form for every work on it, so one refusal sends the whole
 * sitting back. Found in the health check: Paper Returned to Customer with no
 * remark was let through by the board, which only asked a reason of Cancelled,
 * then refused by the server in words about cancelling — and the board was
 * drawn fresh, so every status chosen, approval date and remark typed on it
 * was gone. An approval screenshot over the size limit did the same.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class BoardRefusedSaveTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private WorkTypeModel $tr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Board Refusal Admin';
        $this->admin->email = 'board-refusal-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = new PartyModel;
        $this->customer->party_type = 'customer';
        $this->customer->name = 'Customer '.uniqid().' refusal';
        $this->customer->mobile = '93600'.random_int(10000, 99999);
        $this->customer->is_active = 1;
        $this->customer->save();

        $this->tr = new WorkTypeModel;
        $this->tr->name = 'TR '.uniqid();
        $this->tr->is_active = 1;
        $this->tr->save();
    }

    /** A folder of one work, charged to the customer. */
    private function job(string $status = WorkFileModel::IN_OFFICE): WorkFileItemModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-BR-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01BR'.random_int(1000, 9999);
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

        return $item->fresh();
    }

    private function board(): string
    {
        return route('workfile.status', ['status' => 'all']);
    }

    /** The board's own props, as the page that was sent back draws them. */
    private function props(): array
    {
        return $this->actingAs($this->admin)->getJson($this->board())->assertOk()->json('props');
    }

    // ------------------------------------------------- a return needs a reason

    /**
     * The board asks a reason of exactly what the server refuses without one,
     * the same list the Update dialog on the Work Report is handed.
     */
    public function test_the_board_asks_a_reason_for_a_return_as_well_as_a_cancellation(): void
    {
        $this->job();

        $keys = $this->props()['reasonKeys'] ?? [];

        $this->assertContains(WorkFileModel::CANCELLED, $keys);
        $this->assertContains(WorkFileModel::RETURNED, $keys);
    }

    public function test_a_return_without_a_reason_is_refused_in_words_about_returning(): void
    {
        $job = $this->job();

        $this->actingAs($this->admin)->from($this->board())->post(route('workfile.status'), [
            'statuses' => [$job->id => WorkFileModel::RETURNED],
            'was' => [$job->id => WorkFileModel::IN_OFFICE],
            'remarks' => [$job->id => ''],
        ])->assertRedirect($this->board());

        $this->assertSame(WorkFileModel::IN_OFFICE, $job->fresh()->status, 'refused');

        $error = (string) session('error');

        $this->assertStringContainsString('needs a reason', $error);
        // The reader was returning papers, not cancelling anything.
        $this->assertMatchesRegularExpression('/\breturn/i', $error);
    }

    // ------------------------------------------------ what was typed, put back

    /**
     * The finding's own case: one work returned with no reason, others moved
     * and remarked in the same sitting. The board that comes back is handed
     * all of it, and the dates the approvals were given.
     */
    public function test_a_refused_board_save_hands_the_board_what_was_typed(): void
    {
        $returning = $this->job();
        $approving = $this->job(WorkFileModel::DISPATCHED);
        $approving->approval_screenshot = 'approval-'.uniqid().'.png';
        $approving->save();
        $chasing = $this->job();

        $this->actingAs($this->admin)->from($this->board())->post(route('workfile.status'), [
            'statuses' => [
                $returning->id => WorkFileModel::RETURNED,
                $approving->id => WorkFileModel::APPROVED,
                $chasing->id => WorkFileModel::IN_OFFICE,
            ],
            'was' => [
                $returning->id => WorkFileModel::IN_OFFICE,
                $approving->id => WorkFileModel::DISPATCHED,
                $chasing->id => WorkFileModel::IN_OFFICE,
            ],
            'remarks' => [
                $returning->id => '',
                $approving->id => 'RTO approved it',
                $chasing->id => 'handed to runner',
            ],
            'approved_on' => [$approving->id => '2026-09-05'],
            'was_approved_on' => [$approving->id => ''],
        ])->assertRedirect($this->board())->assertSessionHas('error');

        // Nothing of the sitting was saved.
        $this->assertSame(WorkFileModel::DISPATCHED, $approving->fresh()->status);

        $restore = $this->props()['restore'] ?? null;

        $this->assertNotNull($restore, 'the board is handed what the refused save held');
        $this->assertSame(WorkFileModel::RETURNED, $restore['statuses'][$returning->id]);
        $this->assertSame(WorkFileModel::APPROVED, $restore['statuses'][$approving->id]);
        $this->assertSame(WorkFileModel::DISPATCHED, $restore['was'][$approving->id]);
        $this->assertSame('RTO approved it', $restore['remarks'][$approving->id]);
        $this->assertSame('handed to runner', $restore['remarks'][$chasing->id]);
        $this->assertSame('2026-09-05', $restore['approved_on'][$approving->id]);

        // And what date each approval showed, so a date corrected since is
        // not typed back over; see StatusBoard.vue.
        $this->assertArrayHasKey($approving->id, $restore['was_approved_on']);
    }

    /** Refused by validation rather than by a rule of the save: the same. */
    public function test_a_screenshot_over_the_limit_also_comes_back_with_what_was_typed(): void
    {
        $approving = $this->job(WorkFileModel::DISPATCHED);
        $chasing = $this->job();

        $this->actingAs($this->admin)->from($this->board())->post(route('workfile.status'), [
            'statuses' => [$approving->id => WorkFileModel::APPROVED, $chasing->id => WorkFileModel::IN_OFFICE],
            'was' => [$approving->id => WorkFileModel::DISPATCHED, $chasing->id => WorkFileModel::IN_OFFICE],
            'remarks' => [$approving->id => '', $chasing->id => 'called the vendor'],
            'approved_on' => [$approving->id => '2026-09-05'],
            'screenshots' => [$approving->id => UploadedFile::fake()->create('approval.png', 5000, 'image/png')],
        ])->assertRedirect($this->board())->assertSessionHasErrors();

        $restore = $this->props()['restore'] ?? null;

        $this->assertNotNull($restore);
        $this->assertSame(WorkFileModel::APPROVED, $restore['statuses'][$approving->id]);
        $this->assertSame('called the vendor', $restore['remarks'][$chasing->id]);
    }

    /**
     * Refused because a colleague moved one of the works meanwhile: the board
     * comes back as the work stands now with the rest put back, so the message
     * must not send the reader to reload — a reload draws a blank board and
     * throws away what was just put back (found in review).
     */
    public function test_a_work_moved_meanwhile_is_not_answered_with_reload_the_page(): void
    {
        $moved = $this->job();
        $chasing = $this->job();

        // A colleague sends it out after this board was drawn.
        $moved->status = WorkFileModel::DISPATCHED;
        $moved->save();

        $this->actingAs($this->admin)->from($this->board())->post(route('workfile.status'), [
            'statuses' => [$moved->id => WorkFileModel::CANCELLED, $chasing->id => WorkFileModel::IN_OFFICE],
            'was' => [$moved->id => WorkFileModel::IN_OFFICE, $chasing->id => WorkFileModel::IN_OFFICE],
            'remarks' => [$moved->id => 'buyer backed out', $chasing->id => 'handed to runner'],
        ])->assertRedirect($this->board())->assertSessionHas('error', fn ($message) => str_contains($message, 'changed since')
            && str_contains($message, 'shown below as it stands now')
            && ! str_contains(strtolower($message), 'reload'));

        // And the board that comes back has the rest of the sitting.
        $this->assertSame('handed to runner', $this->props()['restore']['remarks'][$chasing->id] ?? null);
    }

    public function test_a_board_drawn_fresh_has_nothing_to_put_back(): void
    {
        $this->job();

        $this->assertNull($this->props()['restore'] ?? null);
    }
}
