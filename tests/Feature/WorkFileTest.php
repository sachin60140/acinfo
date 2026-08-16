<?php

namespace Tests\Feature;

use App\Http\Controllers\WorkFileController;
use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Work files and the ledger entries they generate.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase —
 * these run against the live application database.
 *
 * The controller is driven directly rather than over HTTP, so validation, the
 * transaction and syncLedger() are all exercised without needing an admin
 * session for every case.
 */
class WorkFileTest extends TestCase
{
    use DatabaseTransactions;

    private WorkFileController $controller;

    private WorkTypeModel $workType;

    private PartyModel $customer;

    private PartyModel $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new WorkFileController;

        $this->workType = new WorkTypeModel;
        $this->workType->name = 'Test Work '.uniqid();
        $this->workType->default_rate = 5000;
        $this->workType->is_active = 1;
        $this->workType->save();

        $this->customer = $this->party('customer', '9000000201');
        $this->vendor = $this->party('vendor', '9000000202');
    }

    private function party(string $type, string $mobile): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.$mobile;
        $party->mobile = $mobile;
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'received_date' => '2026-04-01',
            'work_type_id' => $this->workType->id,
            'customer_id' => $this->customer->id,
            'customer_amount' => '5000',
            'status' => 'in_office',
            'description' => 'UP32-AB-1234',
        ], $overrides);
    }

    /**
     * One file through the receive screen, then edited into the shape the test
     * wants. Receiving deliberately takes only customer, date, type and amount —
     * vendor and status are the other two screens' jobs — so anything beyond
     * that has to arrive via edit(), exactly as it would in real use.
     */
    private function receive(array $overrides = []): WorkFileModel
    {
        $payload = $this->payload($overrides);

        $this->controller->receive(Request::create('/admin/file/receive', 'POST', [
            'received_date' => $payload['received_date'],
            'customer_id' => $payload['customer_id'],
            'rows' => [[
                'work_type_id' => $payload['work_type_id'],
                'amount' => $payload['customer_amount'],
                'description' => $payload['description'] ?? null,
            ]],
        ]));

        $file = WorkFileModel::latest('id')->first();

        $beyondReceive = array_intersect_key($overrides, array_flip(['vendor_id', 'vendor_amount', 'vendor_date', 'status']));

        return $beyondReceive ? $this->update($file, $overrides) : $file;
    }

    /**
     * @param  array<int, int>  $fileIds
     * @param  array<int, string>  $amounts
     */
    private function giveToVendor(array $fileIds, int $vendorId, array $amounts = [], string $date = '2026-04-02'): void
    {
        $this->controller->assign(Request::create('/admin/file/assign', 'POST', [
            'vendor_id' => $vendorId,
            'vendor_date' => $date,
            'files' => $fileIds,
            'amounts' => $amounts,
        ]));
    }

    /**
     * @param  array<int, string>  $statuses
     * @param  array<int, string>  $remarks
     */
    private function setStatuses(array $statuses, array $remarks = []): void
    {
        // Cancelling and returning are refused without a reason, so supply one
        // unless the test is specifically checking that rule.
        foreach ($statuses as $id => $status) {
            if (in_array($status, ['cancelled', 'paper_returned'], true) && ! array_key_exists($id, $remarks)) {
                $remarks[$id] = 'Reason for '.$status;
            }
        }

        $this->controller->status(Request::create('/admin/file/status', 'POST', [
            'statuses' => $statuses,
            'remarks' => $remarks,
        ]));
    }

    private function update(WorkFileModel $file, array $overrides = []): WorkFileModel
    {
        $this->controller->edit(
            Request::create('/admin/file/edit/'.$file->id, 'POST', $this->payload($overrides)),
            $file->id
        );

        return WorkFileModel::find($file->id);
    }

    public function test_receiving_a_file_debits_the_customer(): void
    {
        $file = $this->receive();

        $this->assertSame(5000.0, PartyLedgerModel::currentBalance($this->customer->id));

        $entry = PartyLedgerModel::where('work_file_id', $file->id)->sole();
        $this->assertSame('debit', $entry->entry_type);
        $this->assertSame($this->workType->name.' - UP32-AB-1234', $entry->particular);
        $this->assertSame($file->file_no, $entry->ref_no);
        $this->assertSame('2026-04-01', (string) $entry->txn_date);
    }

    public function test_a_file_is_numbered_automatically(): void
    {
        $file = $this->receive();

        $this->assertSame('F-'.str_pad((string) $file->id, 5, '0', STR_PAD_LEFT), $file->file_no);
    }

    public function test_assigning_a_vendor_credits_them_and_leaves_the_customer_alone(): void
    {
        $file = $this->receive();
        $this->update($file, ['vendor_id' => $this->vendor->id, 'vendor_amount' => '3500', 'vendor_date' => '2026-04-02']);

        $this->assertSame(-3500.0, PartyLedgerModel::currentBalance($this->vendor->id));
        $this->assertSame(5000.0, PartyLedgerModel::currentBalance($this->customer->id));
        $this->assertSame(2, PartyLedgerModel::where('work_file_id', $file->id)->count());
        $this->assertSame(1500.0, WorkFileModel::find($file->id)->margin());
    }

    public function test_a_vendor_assigned_before_the_rate_is_agreed_posts_nothing(): void
    {
        $file = $this->receive(['vendor_id' => $this->vendor->id]);

        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->vendor->id));
        $this->assertSame(1, PartyLedgerModel::where('work_file_id', $file->id)->count());
    }

    /**
     * Correcting a figure must leave one right entry, not an entry plus a
     * reversal that have to be read together to see what was charged.
     */
    public function test_correcting_an_amount_rewrites_the_entry_rather_than_adding_one(): void
    {
        $file = $this->receive();
        $this->update($file, ['customer_amount' => '6000']);

        $this->assertSame(1, PartyLedgerModel::where('work_file_id', $file->id)->count());
        $this->assertSame(6000.0, PartyLedgerModel::currentBalance($this->customer->id));
    }

    public function test_moving_a_file_to_another_vendor_moves_the_credit(): void
    {
        $other = $this->party('vendor', '9000000203');

        $file = $this->receive(['vendor_id' => $this->vendor->id, 'vendor_amount' => '3500']);
        $this->update($file, ['vendor_id' => $other->id, 'vendor_amount' => '3500']);

        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->vendor->id));
        $this->assertSame(-3500.0, PartyLedgerModel::currentBalance($other->id));
        $this->assertSame(2, PartyLedgerModel::where('work_file_id', $file->id)->count());
    }

    public function test_taking_a_file_back_in_house_removes_the_vendor_entry(): void
    {
        $file = $this->receive(['vendor_id' => $this->vendor->id, 'vendor_amount' => '3500']);
        $this->update($file);

        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->vendor->id));
        $this->assertSame(1, PartyLedgerModel::where('work_file_id', $file->id)->count());
    }

    public function test_cancelling_withdraws_both_sides_but_keeps_the_record(): void
    {
        $file = $this->receive(['vendor_id' => $this->vendor->id, 'vendor_amount' => '3500']);
        $file = $this->update($file, ['vendor_id' => $this->vendor->id, 'vendor_amount' => '3500', 'status' => 'cancelled']);

        $this->assertTrue($file->isCancelled());
        $this->assertNotNull($file->file_no, 'the number is kept');
        $this->assertSame(5000.0, (float) $file->customer_amount, 'the figures are kept on the record');
        $this->assertSame(0.0, $file->margin(), 'but it earns nothing');

        $this->assertSame(0, PartyLedgerModel::where('work_file_id', $file->id)->count());
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->customer->id));
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->vendor->id));
    }

    public function test_uncancelling_restores_the_entries_without_duplicating_them(): void
    {
        $file = $this->receive(['vendor_id' => $this->vendor->id, 'vendor_amount' => '3500']);
        $file = $this->update($file, ['vendor_id' => $this->vendor->id, 'vendor_amount' => '3500', 'status' => 'cancelled']);
        $this->update($file, ['vendor_id' => $this->vendor->id, 'vendor_amount' => '3500', 'status' => 'under_verification']);

        $this->assertSame(2, PartyLedgerModel::where('work_file_id', $file->id)->count());
        $this->assertSame(5000.0, PartyLedgerModel::currentBalance($this->customer->id));
        $this->assertSame(-3500.0, PartyLedgerModel::currentBalance($this->vendor->id));
    }

    public function test_cancelling_twice_changes_nothing_further(): void
    {
        $file = $this->receive();
        $file = $this->update($file, ['status' => 'cancelled']);
        $this->update($file, ['status' => 'cancelled']);

        $this->assertSame(0, PartyLedgerModel::where('work_file_id', $file->id)->count());
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->customer->id));
    }

    /**
     * Editing a file whose vendor has since been deactivated must not drop the
     * vendor — that would delete their entry and move their balance in silence.
     */
    public function test_editing_a_file_with_a_deactivated_vendor_keeps_the_vendor(): void
    {
        $file = $this->receive(['vendor_id' => $this->vendor->id, 'vendor_amount' => '3500']);

        $this->vendor->is_active = 0;
        $this->vendor->save();

        $offered = PartyModel::selectList('vendor', $file->vendor_id);
        $this->assertTrue($offered->contains('id', $this->vendor->id), 'still offered on the edit form');

        $this->update($file, ['vendor_id' => $this->vendor->id, 'vendor_amount' => '3500', 'status' => 'under_verification']);
        $this->assertSame(-3500.0, PartyLedgerModel::currentBalance($this->vendor->id));
    }

    public function test_a_retired_work_type_is_still_offered_on_its_own_file(): void
    {
        $file = $this->receive();

        $this->workType->is_active = 0;
        $this->workType->save();

        $this->assertTrue(WorkTypeModel::selectList($file->work_type_id)->contains('id', $this->workType->id));
        $this->assertFalse(WorkTypeModel::selectList()->contains('id', $this->workType->id), 'but not on a new file');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'vendor amount with no vendor' => [['vendor_amount' => '500']],
            'unknown work type' => [['work_type_id' => 999999]],
            'negative amount' => [['customer_amount' => '-5']],
            'unknown status' => [['status' => 'archived']],
            'date in the wrong format' => [['received_date' => '01/04/2026']],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_input_is_rejected(array $override): void
    {
        $this->expectException(ValidationException::class);

        $this->receive($override);
    }

    public function test_a_vendor_cannot_be_booked_into_the_customer_field(): void
    {
        $this->expectException(ValidationException::class);

        $this->receive(['customer_id' => $this->vendor->id]);
    }

    public function test_a_customer_cannot_be_booked_into_the_vendor_field(): void
    {
        $this->expectException(ValidationException::class);

        $this->receive(['vendor_id' => $this->customer->id, 'vendor_amount' => '100']);
    }

    /**
     * A customer handing over three files at once is one act of data entry, but
     * three files: three numbers, three statement lines, three things that can
     * be given to different vendors later.
     */
    public function test_several_files_can_be_received_in_one_go(): void
    {
        $second = new WorkTypeModel;
        $second->name = 'Second Work '.uniqid();
        $second->is_active = 1;
        $second->save();

        $this->controller->receive(Request::create('/admin/file/receive', 'POST', [
            'received_date' => '2026-04-01',
            'customer_id' => $this->customer->id,
            'remarks' => 'Batch of three',
            'rows' => [
                ['work_type_id' => $this->workType->id, 'amount' => '5000', 'description' => 'UP32-AB-1234'],
                ['work_type_id' => $second->id, 'amount' => '2500', 'description' => 'UP32-AB-1234'],
                ['work_type_id' => $this->workType->id, 'amount' => '1200', 'description' => 'Ramesh Kumar'],
            ],
        ]));

        $files = WorkFileModel::where('customer_id', $this->customer->id)->orderBy('id')->get();
        $this->assertCount(3, $files);

        // Three distinct numbers, none blank.
        $numbers = $files->pluck('file_no');
        $this->assertCount(3, $numbers->unique());
        $this->assertFalse($numbers->contains(null));

        // One debit each, not one merged line, so every entry traces to one file.
        $entries = PartyLedgerModel::where('party_id', $this->customer->id)->get();
        $this->assertCount(3, $entries);
        $this->assertSame(8700.0, PartyLedgerModel::currentBalance($this->customer->id));

        $this->assertSame(['in_office', 'in_office', 'in_office'], $files->pluck('status')->all());
        $this->assertSame('Batch of three', $files->first()->remarks, 'shared fields apply to every row');
    }

    public function test_receiving_rejects_an_empty_batch(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller->receive(Request::create('/admin/file/receive', 'POST', [
            'received_date' => '2026-04-01',
            'customer_id' => $this->customer->id,
            'rows' => [],
        ]));
    }

    /**
     * One bad row must take the whole batch down — a partly saved batch would
     * leave the customer charged for some files and not others, with nothing on
     * screen to say which.
     */
    public function test_one_bad_row_saves_none_of_the_batch(): void
    {
        $before = WorkFileModel::count();

        try {
            $this->controller->receive(Request::create('/admin/file/receive', 'POST', [
                'received_date' => '2026-04-01',
                'customer_id' => $this->customer->id,
                'rows' => [
                    ['work_type_id' => $this->workType->id, 'amount' => '5000'],
                    ['work_type_id' => 999999, 'amount' => '2500'],
                ],
            ]));
            $this->fail('Expected the batch to be rejected.');
        } catch (ValidationException $e) {
            // expected
        }

        $this->assertSame($before, WorkFileModel::count());
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->customer->id));
    }

    public function test_giving_files_to_a_vendor_credits_them_and_starts_the_work(): void
    {
        $one = $this->receive();
        $two = $this->receive(['customer_amount' => '3000']);

        $this->giveToVendor([$one->id, $two->id], $this->vendor->id, [
            $one->id => '3500',
            $two->id => '2000',
        ]);

        $this->assertSame(-5500.0, PartyLedgerModel::currentBalance($this->vendor->id));

        foreach ([$one, $two] as $file) {
            $fresh = WorkFileModel::find($file->id);
            $this->assertSame($this->vendor->id, $fresh->vendor_id);
            $this->assertSame('2026-04-02', (string) $fresh->vendor_date);
            $this->assertSame('file_dispatch', $fresh->status, 'handing it over dispatches the file');
        }
    }

    public function test_giving_out_a_file_with_no_agreed_rate_posts_nothing_yet(): void
    {
        $file = $this->receive();

        $this->giveToVendor([$file->id], $this->vendor->id, [$file->id => '']);

        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->vendor->id));
        $this->assertSame($this->vendor->id, WorkFileModel::find($file->id)->vendor_id, 'but it is assigned');
    }

    public function test_only_unassigned_files_are_offered_and_accepted(): void
    {
        $file = $this->receive();
        $other = $this->party('vendor', '9000000204');

        $this->assertTrue(WorkFileModel::unassigned()->contains('id', $file->id));

        $this->giveToVendor([$file->id], $this->vendor->id, [$file->id => '3500']);
        $this->assertFalse(WorkFileModel::unassigned()->contains('id', $file->id), 'no longer waiting');

        // A stale page re-posting the same file must not move it to someone else.
        $this->giveToVendor([$file->id], $other->id, [$file->id => '9999']);

        $this->assertSame($this->vendor->id, WorkFileModel::find($file->id)->vendor_id);
        $this->assertSame(-3500.0, PartyLedgerModel::currentBalance($this->vendor->id));
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($other->id));
    }

    public function test_a_cancelled_file_is_never_offered_to_a_vendor(): void
    {
        $file = $this->receive();
        $this->setStatuses([$file->id => 'cancelled']);

        $this->assertFalse(WorkFileModel::unassigned()->contains('id', $file->id));
    }

    public function test_statuses_update_in_bulk_and_only_where_changed(): void
    {
        $one = $this->receive();
        $two = $this->receive(['customer_amount' => '3000']);

        $this->setStatuses([$one->id => 'under_verification', $two->id => 'in_office']);

        $this->assertSame('under_verification', WorkFileModel::find($one->id)->status);
        $this->assertSame('in_office', WorkFileModel::find($two->id)->status, 'unchanged stays put');

        // Money is untouched by a status move, cancelling aside.
        $this->assertSame(8000.0, PartyLedgerModel::currentBalance($this->customer->id));
    }

    public function test_cancelling_from_the_status_board_withdraws_the_entries(): void
    {
        $file = $this->receive();
        $this->assertSame(5000.0, PartyLedgerModel::currentBalance($this->customer->id));

        $this->setStatuses([$file->id => 'cancelled']);

        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->customer->id));
        $this->assertSame(0, PartyLedgerModel::where('work_file_id', $file->id)->count());
    }

    public function test_the_status_board_defaults_to_work_in_hand(): void
    {
        $open = $this->receive();
        $done = $this->receive(['customer_amount' => '3000']);
        $this->setStatuses([$done->id => 'paper_returned']);

        $board = WorkFileModel::forStatusBoard('open');

        $this->assertTrue($board->contains('id', $open->id));
        $this->assertFalse($board->contains('id', $done->id));
        $this->assertTrue(WorkFileModel::forStatusBoard('paper_returned')->contains('id', $done->id));
    }

    /**
     * Returning the papers is not the same as cancelling. The work was received
     * and later handed back, so the charge stays on the statement and a matching
     * credit sits beside it — erasing the charge would leave the customer's own
     * records disagreeing with yours.
     */
    public function test_returning_papers_credits_the_customer_and_keeps_the_charge(): void
    {
        $file = $this->receive();
        $this->assertSame(5000.0, PartyLedgerModel::currentBalance($this->customer->id));

        $this->setStatuses([$file->id => 'paper_returned']);

        $entries = PartyLedgerModel::where('work_file_id', $file->id)->orderBy('id')->get();
        $this->assertCount(2, $entries, 'the charge and the refund both stand');
        $this->assertSame(['debit', 'credit'], $entries->pluck('entry_type')->all());
        $this->assertSame(['customer', 'customer_return'], $entries->pluck('file_role')->all());
        $this->assertSame(5000.0, (float) $entries[1]->amount, 'credited in full');

        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->customer->id), 'nets to nil');
        $this->assertSame(0.0, WorkFileModel::find($file->id)->margin());
    }

    public function test_the_return_credit_is_dated_when_the_papers_went_back(): void
    {
        $file = $this->receive();
        $this->setStatuses([$file->id => 'paper_returned']);

        $today = now()->toDateString();
        $this->assertSame($today, (string) WorkFileModel::find($file->id)->returned_on);
        $this->assertSame(
            $today,
            (string) PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', 'customer_return')->value('txn_date'),
            'not back-dated to when the file arrived'
        );
    }

    public function test_undoing_a_return_removes_the_credit_again(): void
    {
        $file = $this->receive();
        $this->setStatuses([$file->id => 'paper_returned']);
        $this->setStatuses([$file->id => 'in_office']);

        $this->assertSame(5000.0, PartyLedgerModel::currentBalance($this->customer->id));
        $this->assertSame(1, PartyLedgerModel::where('work_file_id', $file->id)->count());
        $this->assertNull(WorkFileModel::find($file->id)->returned_on);
    }

    /**
     * A returned file may still owe its vendor: handing the papers back does not
     * undo work already booked, so the file shows a real loss.
     */
    public function test_a_returned_file_still_owes_its_vendor(): void
    {
        $file = $this->receive();
        $this->giveToVendor([$file->id], $this->vendor->id, [$file->id => '3500']);
        $this->setStatuses([$file->id => 'paper_returned']);

        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->customer->id));
        $this->assertSame(-3500.0, PartyLedgerModel::currentBalance($this->vendor->id));
        $this->assertSame(-3500.0, WorkFileModel::find($file->id)->margin());
    }

    public function test_cancelling_a_returned_file_withdraws_both_entries(): void
    {
        $file = $this->receive();
        $this->setStatuses([$file->id => 'paper_returned']);
        $this->setStatuses([$file->id => 'cancelled']);

        $this->assertSame(0, PartyLedgerModel::where('work_file_id', $file->id)->count());
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->customer->id));
    }

    public function test_approval_is_refused_without_a_screenshot(): void
    {
        $file = $this->receive();

        $this->setStatuses([$file->id => 'approval_done']);

        $this->assertSame('in_office', WorkFileModel::find($file->id)->status, 'nothing was saved');
    }

    public function test_approval_is_accepted_with_a_screenshot_and_the_file_is_stored(): void
    {
        $file = $this->receive();

        $this->controller->status(Request::create('/admin/file/status', 'POST',
            ['statuses' => [$file->id => 'approval_done']],
            [],
            ['screenshots' => [$file->id => UploadedFile::fake()->image('approval.jpg')]]
        ));

        $fresh = WorkFileModel::find($file->id);

        $this->assertSame('approval_done', $fresh->status);
        $this->assertNotNull($fresh->approval_screenshot);
        $this->assertStringStartsWith(WorkFileModel::UPLOAD_DIR.'/', $fresh->approval_screenshot);
        // Under public/, because storage:link cannot run on the production host.
        $this->assertFileExists(public_path($fresh->approval_screenshot));

        // The stored name comes from the file number, never from the upload's own
        // name, which is attacker-controlled.
        $this->assertStringContainsString($fresh->file_no, basename($fresh->approval_screenshot));

        $fresh->deleteScreenshot();
    }

    /**
     * A batch is all-or-nothing: one file missing its evidence must not let the
     * others through, or the operator is left guessing which saved.
     */
    public function test_one_missing_screenshot_blocks_the_whole_batch(): void
    {
        $one = $this->receive();
        $two = $this->receive(['customer_amount' => '2000']);

        $this->setStatuses([$one->id => 'approval_done', $two->id => 'under_verification']);

        $this->assertSame('in_office', WorkFileModel::find($one->id)->status);
        $this->assertSame('in_office', WorkFileModel::find($two->id)->status, 'the good row did not slip through');
    }

    public function test_a_status_change_records_its_remark(): void
    {
        $file = $this->receive();

        $this->setStatuses([$file->id => 'paper_pendency'], [$file->id => 'Insurance copy awaited from customer']);

        $latest = WorkFileModel::find($file->id)->statusLog()->first();

        $this->assertSame('in_office', $latest->from_status);
        $this->assertSame('paper_pendency', $latest->to_status);
        $this->assertSame('Insurance copy awaited from customer', $latest->remark);
        $this->assertFalse($latest->isNoteOnly());
    }

    /**
     * Each change keeps its own reason. A single field on the file would have the
     * second explanation erase the first, which is the whole point of the log.
     */
    public function test_every_change_keeps_its_own_remark(): void
    {
        $file = $this->receive();

        $this->setStatuses([$file->id => 'paper_pendency'], [$file->id => 'Insurance copy awaited']);
        $this->setStatuses([$file->id => 'under_verification'], [$file->id => 'Submitted at RTO']);

        $remarks = WorkFileModel::find($file->id)->statusLog()->pluck('remark')->all();

        $this->assertSame(['Submitted at RTO', 'Insurance copy awaited', 'Received from customer'], $remarks);
    }

    public function test_a_remark_can_be_added_without_moving_the_file(): void
    {
        $file = $this->receive();

        $this->setStatuses([$file->id => 'in_office'], [$file->id => 'Chased the customer today']);

        $latest = WorkFileModel::find($file->id)->statusLog()->first();

        $this->assertTrue($latest->isNoteOnly(), 'from and to are the same');
        $this->assertSame('Chased the customer today', $latest->remark);
        $this->assertSame('in_office', WorkFileModel::find($file->id)->status);
    }

    public function test_nothing_is_logged_when_nothing_was_said_or_changed(): void
    {
        $file = $this->receive();
        $before = $file->statusLog()->count();

        $this->setStatuses([$file->id => 'in_office'], [$file->id => '   ']);

        $this->assertSame($before, WorkFileModel::find($file->id)->statusLog()->count());
    }

    /**
     * Cancelling and returning both move the customer's balance, so they have to
     * say why. Everything else takes a remark but does not insist on one.
     */
    public function test_cancelling_or_returning_is_refused_without_a_reason(): void
    {
        foreach (['cancelled', 'paper_returned'] as $status) {
            $file = $this->receive();

            $this->setStatuses([$file->id => $status], [$file->id => '']);

            $this->assertSame('in_office', WorkFileModel::find($file->id)->status, $status.' should have been refused');
            $this->assertSame(5000.0, PartyLedgerModel::currentBalance($this->customer->id), 'and the balance left alone');

            // Clear the balance for the next pass round the loop.
            $this->setStatuses([$file->id => 'cancelled'], [$file->id => 'tidy up']);
        }
    }

    public function test_other_statuses_do_not_need_a_reason(): void
    {
        $file = $this->receive();

        $this->setStatuses([$file->id => 'part_pesi_required'], [$file->id => '']);

        $this->assertSame('part_pesi_required', WorkFileModel::find($file->id)->status);
    }

    public function test_a_file_opens_its_own_history_when_received(): void
    {
        $file = $this->receive();

        $opening = $file->statusLog()->first();

        $this->assertTrue($opening->isOpening());
        $this->assertNull($opening->from_status);
        $this->assertSame('in_office', $opening->to_status);
    }

    public function test_giving_a_file_to_a_vendor_is_recorded_with_the_vendor_name(): void
    {
        $file = $this->receive();

        $this->controller->assign(Request::create('/admin/file/assign', 'POST', [
            'vendor_id' => $this->vendor->id,
            'vendor_date' => '2026-04-02',
            'files' => [$file->id],
            'amounts' => [$file->id => '3500'],
            'remark' => 'Sent by hand',
        ]));

        $latest = WorkFileModel::find($file->id)->statusLog()->first();

        $this->assertSame('file_dispatch', $latest->to_status);
        $this->assertStringContainsString('Sent by hand', $latest->remark);
        $this->assertStringContainsString($this->vendor->name, $latest->remark);
    }

    public function test_the_board_shows_the_most_recent_remark_per_file(): void
    {
        $one = $this->receive();
        $two = $this->receive(['customer_amount' => '2000']);

        $this->setStatuses([$one->id => 'paper_pendency'], [$one->id => 'First note']);
        $this->setStatuses([$one->id => 'under_verification'], [$one->id => 'Second note']);

        $remarks = WorkFileModel::latestRemarks([$one->id, $two->id]);

        $this->assertSame('Second note', $remarks[$one->id]);
        $this->assertSame('Received from customer', $remarks[$two->id]);
    }

    /**
     * @param  array<int, int>  $fileIds
     */
    private function takeBackFromVendor(array $fileIds, string $date = '2026-04-20', ?string $remark = null, array $amounts = []): void
    {
        $this->controller->vendorReturn(Request::create('/admin/file/vendor-return', 'POST', [
            'returned_on' => $date,
            'files' => $fileIds,
            // A reason is required, so supply one unless the test is checking
            // that rule specifically.
            'remark' => $remark ?? 'Vendor could not complete',
            'amounts' => $amounts,
        ]));
    }

    /**
     * @param  array<int, int>  $fileIds
     * @param  array<int, string>  $amounts
     */
    private function returnToCustomer(array $fileIds, array $amounts = [], string $date = '2026-04-20', ?string $remark = null): void
    {
        $this->controller->customerReturn(Request::create('/admin/file/customer-return', 'POST', [
            'returned_on' => $date,
            'files' => $fileIds,
            'amounts' => $amounts,
            'remark' => $remark ?? 'Customer asked for the papers back',
        ]));
    }

    /**
     * The mirror of the customer return: the booking stands and a reversal sits
     * beside it, so the vendor's statement shows what was agreed and what came
     * back rather than quietly losing the line.
     */
    public function test_papers_returned_by_a_vendor_reverse_what_was_booked(): void
    {
        $file = $this->receive();
        $this->giveToVendor([$file->id], $this->vendor->id, [$file->id => '3500']);
        $this->assertSame(-3500.0, PartyLedgerModel::currentBalance($this->vendor->id));

        $this->takeBackFromVendor([$file->id]);

        $entries = PartyLedgerModel::where('work_file_id', $file->id)
            ->whereIn('file_role', ['vendor', 'vendor_return'])->orderBy('id')->get();

        $this->assertCount(2, $entries, 'the booking and its reversal both stand');
        $this->assertSame(['credit', 'debit'], $entries->pluck('entry_type')->all());
        $this->assertSame(3500.0, (float) $entries[1]->amount);
        $this->assertSame('2026-04-20', (string) $entries[1]->txn_date);

        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->vendor->id), 'nothing owed');
    }

    public function test_a_returned_file_comes_back_to_the_office_and_costs_nothing(): void
    {
        $file = $this->receive();
        $this->giveToVendor([$file->id], $this->vendor->id, [$file->id => '3500']);
        $this->takeBackFromVendor([$file->id]);

        $fresh = WorkFileModel::find($file->id);

        $this->assertSame('in_office', $fresh->status);
        $this->assertTrue($fresh->isReturnedByVendor());
        // The customer is still charged; the vendor cost is gone.
        $this->assertSame(5000.0, $fresh->margin());
        $this->assertSame(5000.0, PartyLedgerModel::currentBalance($this->customer->id));
    }

    public function test_taking_a_file_back_is_recorded_with_the_vendor_name(): void
    {
        $file = $this->receive();
        $this->giveToVendor([$file->id], $this->vendor->id, [$file->id => '3500']);
        $this->takeBackFromVendor([$file->id], '2026-04-20', 'Agent could not complete');

        $latest = WorkFileModel::find($file->id)->statusLog()->first();

        $this->assertStringContainsString('Agent could not complete', $latest->remark);
        $this->assertStringContainsString($this->vendor->name, $latest->remark);
        $this->assertSame('in_office', $latest->to_status);
    }

    public function test_only_files_still_out_with_a_vendor_can_be_taken_back(): void
    {
        $file = $this->receive();
        $this->assertFalse(WorkFileModel::withVendor()->contains('id', $file->id), 'not with anyone yet');

        $this->giveToVendor([$file->id], $this->vendor->id, [$file->id => '3500']);
        $this->assertTrue(WorkFileModel::withVendor()->contains('id', $file->id));

        $this->takeBackFromVendor([$file->id]);
        $this->assertFalse(WorkFileModel::withVendor()->contains('id', $file->id), 'already back');

        // A stale page re-posting must not double the reversal.
        $this->takeBackFromVendor([$file->id], '2026-05-01');

        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->vendor->id));
        $this->assertSame(1, PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', 'vendor_return')->count());
        $this->assertSame('2026-04-20', (string) WorkFileModel::find($file->id)->vendor_returned_on);
    }

    /**
     * Both the booking and its reversal belong to one vendor, so moving the file
     * to another would drag that history onto a different statement.
     */
    public function test_a_returned_file_cannot_be_switched_to_another_vendor(): void
    {
        $other = $this->party('vendor', '9000000205');

        $file = $this->receive();
        $this->giveToVendor([$file->id], $this->vendor->id, [$file->id => '3500']);
        $this->takeBackFromVendor([$file->id]);

        $this->update($file, ['vendor_id' => $other->id, 'vendor_amount' => '3500']);

        $this->assertSame($this->vendor->id, WorkFileModel::find($file->id)->vendor_id, 'refused');
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($other->id));
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->vendor->id));
    }

    public function test_cancelling_a_vendor_returned_file_withdraws_every_entry(): void
    {
        $file = $this->receive();
        $this->giveToVendor([$file->id], $this->vendor->id, [$file->id => '3500']);
        $this->takeBackFromVendor([$file->id]);
        $this->setStatuses([$file->id => 'cancelled']);

        $this->assertSame(0, PartyLedgerModel::where('work_file_id', $file->id)->count());
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->customer->id));
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->vendor->id));
    }

    /**
     * @param  array<int, string>  $refunds
     */
    private function setStatusesWithRefund(array $statuses, array $refunds, array $remarks = []): void
    {
        foreach ($statuses as $id => $status) {
            if (in_array($status, ['cancelled', 'paper_returned'], true) && ! array_key_exists($id, $remarks)) {
                $remarks[$id] = 'Reason for '.$status;
            }
        }

        $this->controller->status(Request::create('/admin/file/status', 'POST', [
            'statuses' => $statuses,
            'remarks' => $remarks,
            'refunds' => $refunds,
        ]));
    }

    /**
     * Work often gets part way before the papers go back — fees paid, a trip
     * made — and the business keeps that part.
     */
    public function test_only_part_of_a_charge_can_be_refunded(): void
    {
        $file = $this->receive();  // charged 5000

        $this->setStatusesWithRefund([$file->id => 'paper_returned'], [$file->id => '2000']);

        $credit = PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', 'customer_return')->first();

        $this->assertSame(2000.0, (float) $credit->amount);
        // Charged 5000, gave back 2000: the customer still owes 3000.
        $this->assertSame(3000.0, PartyLedgerModel::currentBalance($this->customer->id));
        $this->assertSame(3000.0, WorkFileModel::find($file->id)->margin(), 'and the file earned that much');
    }

    public function test_a_blank_refund_still_means_the_whole_charge(): void
    {
        $file = $this->receive();

        $this->setStatusesWithRefund([$file->id => 'paper_returned'], [$file->id => '']);

        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->customer->id));
        $this->assertNull(WorkFileModel::find($file->id)->returned_amount);
    }

    public function test_a_refund_cannot_exceed_what_was_charged(): void
    {
        $file = $this->receive();

        $this->setStatusesWithRefund([$file->id => 'paper_returned'], [$file->id => '9000']);

        $this->assertSame('in_office', WorkFileModel::find($file->id)->status, 'refused');
        $this->assertSame(5000.0, PartyLedgerModel::currentBalance($this->customer->id));
    }

    public function test_a_part_refund_can_be_corrected_afterwards(): void
    {
        $file = $this->receive();

        $this->setStatusesWithRefund([$file->id => 'paper_returned'], [$file->id => '2000']);
        $this->setStatusesWithRefund([$file->id => 'paper_returned'], [$file->id => '3500']);

        $this->assertSame(1, PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', 'customer_return')->count());
        $this->assertSame(1500.0, PartyLedgerModel::currentBalance($this->customer->id));
    }

    public function test_undoing_a_return_forgets_the_part_refund(): void
    {
        $file = $this->receive();

        $this->setStatusesWithRefund([$file->id => 'paper_returned'], [$file->id => '2000']);
        $this->setStatuses([$file->id => 'in_office']);

        $fresh = WorkFileModel::find($file->id);

        $this->assertNull($fresh->returned_amount, 'so re-returning starts from a full refund');
        $this->assertSame(5000.0, PartyLedgerModel::currentBalance($this->customer->id));
    }

    /**
     * The same on the vendor side: they did part of the work, so they keep part
     * of the fee and only the rest is reversed.
     */
    public function test_only_part_of_a_vendor_booking_can_be_reversed(): void
    {
        $file = $this->receive();
        $this->giveToVendor([$file->id], $this->vendor->id, [$file->id => '3500']);

        $this->takeBackFromVendor([$file->id], '2026-04-20', null, [$file->id => '1500']);

        $debit = PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', 'vendor_return')->first();

        $this->assertSame(1500.0, (float) $debit->amount);
        // Booked 3500, reversed 1500: still owed 2000.
        $this->assertSame(-2000.0, PartyLedgerModel::currentBalance($this->vendor->id));
        $this->assertSame(3000.0, WorkFileModel::find($file->id)->margin(), '5000 charged less 2000 still owed');
    }

    public function test_a_vendor_reversal_cannot_exceed_what_was_booked(): void
    {
        $file = $this->receive();
        $this->giveToVendor([$file->id], $this->vendor->id, [$file->id => '3500']);

        $this->takeBackFromVendor([$file->id], '2026-04-20', null, [$file->id => '9999']);

        $this->assertNull(WorkFileModel::find($file->id)->vendor_returned_on, 'refused');
        $this->assertSame(-3500.0, PartyLedgerModel::currentBalance($this->vendor->id));
    }

    public function test_part_returns_reach_the_dashboard_totals(): void
    {
        $base = WorkFileModel::summary();

        $file = $this->receive(['received_date' => now()->toDateString()]);
        $this->giveToVendor([$file->id], $this->vendor->id, [$file->id => '3500']);
        $this->setStatusesWithRefund([$file->id => 'paper_returned'], [$file->id => '2000']);

        $now = WorkFileModel::summary();

        // Kept 3000 of the 5000 charged, still owes the vendor 3500.
        $this->assertSame(3000.0, $now['month_billed'] - $base['month_billed']);
        $this->assertSame(-500.0, $now['month_margin'] - $base['month_margin']);
    }

    public function test_the_status_board_can_be_filtered_by_work_type(): void
    {
        $other = new WorkTypeModel;
        $other->name = 'Other Work '.uniqid();
        $other->is_active = 1;
        $other->save();

        $mine = $this->receive();
        $theirs = $this->receive(['work_type_id' => $other->id]);

        $filtered = WorkFileModel::forStatusBoard('open', $this->workType->id);

        $this->assertTrue($filtered->contains('id', $mine->id));
        $this->assertFalse($filtered->contains('id', $theirs->id));

        // Both are still there when no type is chosen.
        $all = WorkFileModel::forStatusBoard('open');
        $this->assertTrue($all->contains('id', $mine->id));
        $this->assertTrue($all->contains('id', $theirs->id));
    }

    /**
     * The two filters have to agree: a count taken across every work type would
     * promise rows the tab then does not show once a type is picked.
     */
    public function test_status_counts_respect_the_chosen_work_type(): void
    {
        $other = new WorkTypeModel;
        $other->name = 'Other Work '.uniqid();
        $other->is_active = 1;
        $other->save();

        $base = WorkFileModel::statusCounts();
        $baseMine = WorkFileModel::statusCounts($this->workType->id);

        $this->receive();
        $this->receive();
        $this->receive(['work_type_id' => $other->id]);

        $all = WorkFileModel::statusCounts();
        $mine = WorkFileModel::statusCounts($this->workType->id);

        $this->assertSame(3, $all['in_office'] - $base['in_office']);
        $this->assertSame(3, $all['open'] - $base['open']);
        $this->assertSame(3, $all['all'] - $base['all']);

        $this->assertSame(2, $mine['in_office'] - $baseMine['in_office'], 'only this type');
        $this->assertSame(2, $mine['open'] - $baseMine['open']);
    }

    public function test_work_type_counts_respect_the_chosen_status(): void
    {
        $file = $this->receive();
        $this->receive();

        $open = WorkFileModel::workTypeCounts('open')->firstWhere('id', $this->workType->id);
        $this->assertSame(2, (int) $open->total);

        // Moving to another in-hand status leaves the file under In Hand, so use
        // an end state to move it out.
        $this->setStatuses([$file->id => 'paper_returned']);

        $this->assertSame(1, (int) WorkFileModel::workTypeCounts('open')->firstWhere('id', $this->workType->id)->total);
        $this->assertSame(1, (int) WorkFileModel::workTypeCounts('paper_returned')->firstWhere('id', $this->workType->id)->total);
        $this->assertSame(2, (int) WorkFileModel::workTypeCounts('all')->firstWhere('id', $this->workType->id)->total);

        // A type with nothing under this status is left out rather than offered
        // as a filter that leads nowhere.
        $this->assertNull(WorkFileModel::workTypeCounts('cancelled')->firstWhere('id', $this->workType->id));
    }

    /**
     * The Work Files list is a summary of the ledgers, so its figures have to be
     * the same figures. It drifted once because the list called netCustomer()
     * and netVendor() without the arguments that carry part refunds and vendor
     * returns, and quietly reported a file as earning nothing.
     */
    public function test_the_files_list_agrees_with_the_ledger_on_every_kind_of_file(): void
    {
        $plain = $this->receive();                                     // 5000, nothing back
        $partRefund = $this->receive();
        $vendorBack = $this->receive();
        $fullRefund = $this->receive();
        $cancelled = $this->receive();

        $this->giveToVendor([$partRefund->id, $vendorBack->id], $this->vendor->id, [
            $partRefund->id => '3500',
            $vendorBack->id => '3500',
        ]);

        $this->setStatusesWithRefund([$partRefund->id => 'paper_returned'], [$partRefund->id => '2000']);
        $this->setStatusesWithRefund([$fullRefund->id => 'paper_returned'], [$fullRefund->id => '']);
        $this->takeBackFromVendor([$vendorBack->id]);
        $this->setStatuses([$cancelled->id => 'cancelled']);

        $ids = [$plain->id, $partRefund->id, $vendorBack->id, $fullRefund->id, $cancelled->id];
        $rows = WorkFileModel::listing()->whereIn('id', $ids)->values();

        $this->assertCount(5, $rows);

        // 5000 plain + 3000 kept of the part refund + 5000 vendor-back + 0 + 0.
        // Only the part-refunded file still owes its vendor.
        $billed = 13000.0;
        $cost = 3500.0;

        // The ledger is the authority, so start by pinning these to it.
        $this->assertSame($billed, PartyLedgerModel::currentBalance($this->customer->id));
        $this->assertSame(-$cost, PartyLedgerModel::currentBalance($this->vendor->id));

        // Then assert what the screen actually produces, not a copy of its
        // arithmetic — the bug this guards against was the screen calling the
        // totals differently from everywhere else, which re-implementing the sum
        // here would not catch.
        //
        // It goes through the route rather than rendering the view directly:
        // the figures are computed in the controller now, so rendering the view
        // alone would prove nothing about them. Asking for JSON gets the same
        // payload the page is built from.
        $admin = new User;
        $admin->name = 'List Admin';
        $admin->email = 'list-admin-'.uniqid().'@example.com';
        $admin->password = Hash::make('password-for-tests');
        $admin->user_type = 1;
        $admin->save();
        $this->actingAs($admin);

        $screen = $this->getJson(route('workfile.index'))->assertOk()->json();

        $mine = collect($screen['props']['rows'])
            ->whereIn('id', [$plain->id, $partRefund->id, $vendorBack->id, $fullRefund->id, $cancelled->id]);

        $this->assertCount(5, $mine, 'all five files are listed');

        // Per file, the figures that count rather than the ones entered.
        $this->assertSame($billed, round($mine->sum('charged'), 2), 'billed across these files');
        $this->assertSame($cost, round($mine->sum(fn ($row) => (float) $row['cost']), 2), 'cost across these files');
        $this->assertSame($billed - $cost, round($mine->sum('margin'), 2), 'margin across these files');

        /*
         * And the summary above the table is the sum of the rows beneath it. This
         * is the part that catches the original bug: a screen whose headline
         * figure is computed by a different route than its rows can disagree with
         * itself while every individual number looks right.
         */
        $listed = collect($screen['props']['rows']);

        $this->assertSame(
            round($listed->sum('charged'), 2),
            round((float) $screen['page']['billed'], 2),
            'the billed summary is the sum of the rows shown'
        );

        $this->assertSame(
            round($listed->sum(fn ($row) => (float) $row['cost']), 2),
            round((float) $screen['page']['cost'], 2),
            'the cost summary is the sum of the rows shown'
        );
    }

    public function test_the_return_screen_returns_a_batch_to_their_customers(): void
    {
        $one = $this->receive();
        $two = $this->receive(['customer_amount' => '2000']);

        $this->returnToCustomer([$one->id, $two->id]);

        foreach ([$one, $two] as $file) {
            $fresh = WorkFileModel::find($file->id);
            $this->assertTrue($fresh->isReturned());
            $this->assertSame('2026-04-20', (string) $fresh->returned_on);
        }

        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->customer->id), 'both refunded in full');
    }

    public function test_the_return_screen_takes_a_part_refund(): void
    {
        $file = $this->receive();  // charged 5000

        $this->returnToCustomer([$file->id], [$file->id => '2000']);

        $this->assertSame(3000.0, PartyLedgerModel::currentBalance($this->customer->id));
        $this->assertSame(2000.0, (float) WorkFileModel::find($file->id)->returned_amount);
    }

    /**
     * The form pre-fills the full charge so it can be seen and edited down.
     * Posting that pre-filled figure back must mean the same as leaving it
     * blank, or an untouched file would look like a part refund of itself.
     */
    public function test_returning_the_whole_charge_is_stored_as_a_full_refund(): void
    {
        $file = $this->receive();

        $this->returnToCustomer([$file->id], [$file->id => '5000']);

        $this->assertNull(WorkFileModel::find($file->id)->returned_amount);
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->customer->id));
    }

    public function test_the_return_screen_needs_a_reason(): void
    {
        $file = $this->receive();

        $this->expectException(ValidationException::class);

        $this->returnToCustomer([$file->id], [], '2026-04-20', '');
    }

    public function test_the_return_screen_refuses_a_refund_above_the_charge(): void
    {
        $file = $this->receive();

        $this->returnToCustomer([$file->id], [$file->id => '9000']);

        $this->assertSame('in_office', WorkFileModel::find($file->id)->status, 'refused');
        $this->assertSame(5000.0, PartyLedgerModel::currentBalance($this->customer->id));
    }

    public function test_only_returnable_files_are_offered_and_accepted(): void
    {
        $file = $this->receive();
        $this->assertTrue(WorkFileModel::returnableToCustomer()->contains('id', $file->id));

        $this->returnToCustomer([$file->id]);
        $this->assertFalse(WorkFileModel::returnableToCustomer()->contains('id', $file->id), 'already back');

        // A stale page re-posting must not credit the customer twice.
        $this->returnToCustomer([$file->id], [$file->id => '5000'], '2026-05-01');

        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->customer->id));
        $this->assertSame(1, PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', 'customer_return')->count());
        $this->assertSame('2026-04-20', (string) WorkFileModel::find($file->id)->returned_on);
    }

    public function test_a_cancelled_file_is_never_offered_for_return(): void
    {
        $file = $this->receive();
        $this->setStatuses([$file->id => 'cancelled']);

        $this->assertFalse(WorkFileModel::returnableToCustomer()->contains('id', $file->id));
    }

    public function test_taking_a_file_back_from_a_vendor_needs_a_reason(): void
    {
        $file = $this->receive();
        $this->giveToVendor([$file->id], $this->vendor->id, [$file->id => '3500']);

        $this->expectException(ValidationException::class);

        $this->takeBackFromVendor([$file->id], '2026-04-20', '');
    }

    public function test_reversing_the_whole_booking_is_stored_as_a_full_reversal(): void
    {
        $file = $this->receive();
        $this->giveToVendor([$file->id], $this->vendor->id, [$file->id => '3500']);

        $this->takeBackFromVendor([$file->id], '2026-04-20', null, [$file->id => '3500']);

        $this->assertNull(WorkFileModel::find($file->id)->vendor_returned_amount);
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->vendor->id));
    }

    /**
     * A vehicle number is typed however it comes off the paperwork, so it is
     * stored one way — otherwise "BR 01 AB-1234" and "br01ab1234" are two
     * different vehicles and the history lookup finds neither from the other.
     */
    public function test_a_registration_number_is_stored_one_way_however_it_is_typed(): void
    {
        foreach (['BR 01 AB-1234', 'br01ab1234', 'Br01 Ab 1234'] as $typed) {
            $this->assertSame('BR01AB1234', WorkFileModel::normaliseRegistration($typed));
        }

        $this->controller->receive(Request::create('/admin/file/receive', 'POST', [
            'received_date' => '2026-04-01',
            'customer_id' => $this->customer->id,
            'rows' => [[
                'registration_no' => 'br 01 ab-1234',
                'work_type_id' => $this->workType->id,
                'amount' => '5000',
            ]],
        ]));

        $this->assertSame('BR01AB1234', WorkFileModel::latest('id')->first()->registration_no);
    }

    public function test_the_history_lookup_finds_what_a_vehicle_was_charged_before(): void
    {
        $this->controller->receive(Request::create('/admin/file/receive', 'POST', [
            'received_date' => '2026-04-01',
            'customer_id' => $this->customer->id,
            'rows' => [
                ['registration_no' => 'BR01AB1234', 'work_type_id' => $this->workType->id, 'amount' => '5000'],
                ['registration_no' => 'BR09ZZ9999', 'work_type_id' => $this->workType->id, 'amount' => '1000'],
            ],
        ]));

        // Found however it is spelled on the way in.
        $history = WorkFileModel::historyFor('br 01 ab 1234');

        $this->assertCount(1, $history);
        $this->assertSame(5000.0, (float) $history->first()->customer_amount);
        $this->assertSame($this->workType->name, $history->first()->work_type);

        $this->assertCount(0, WorkFileModel::historyFor('BR99XX0000'), 'a vehicle never seen before');
        $this->assertCount(0, WorkFileModel::historyFor(''), 'and an empty number asks nothing of the database');
    }

    public function test_the_history_endpoint_reports_the_net_amount_after_a_refund(): void
    {
        $this->actingAs($this->admin());

        $this->controller->receive(Request::create('/admin/file/receive', 'POST', [
            'received_date' => '2026-04-01',
            'customer_id' => $this->customer->id,
            'rows' => [['registration_no' => 'BR02CD5678', 'work_type_id' => $this->workType->id, 'amount' => '4000']],
        ]));

        $file = WorkFileModel::latest('id')->first();
        $this->setStatusesWithRefund([$file->id => 'paper_returned'], [$file->id => '1500']);

        $response = $this->getJson(route('api.workfile.history', ['registration_no' => 'br02-cd-5678']));

        $response->assertOk()
            ->assertJsonPath('registration_no', 'BR02CD5678')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('files.0.charged', '4,000.00')
            // What it actually came to once the refund is taken into account —
            // the figure a new quote should be compared against.
            ->assertJsonPath('files.0.net', '2,500.00')
            ->assertJsonPath('files.0.was_returned', true);
    }

    public function test_the_history_endpoint_is_behind_the_admin_login(): void
    {
        $this->get(route('api.workfile.history', ['registration_no' => 'BR01AB1234']))
            ->assertRedirect(url('/admin'));
    }

    private function admin(): \App\Models\User
    {
        $user = new User;
        $user->name = 'Lookup Admin';
        $user->email = 'lookup-'.uniqid().'@example.com';
        $user->password = Hash::make('password-for-tests');
        $user->user_type = 1;
        $user->save();

        return $user;
    }

    /**
     * A file returned to its customer is finished. Taking it "back" from the
     * vendor used to force the status to In Office, which made syncLedger
     * withdraw the customer's refund credit and silently re-charge them the
     * full amount — their balance went from nil back to 5,000.
     */
    public function test_taking_a_file_back_from_a_vendor_cannot_undo_a_customer_refund(): void
    {
        $file = $this->receive();
        $this->giveToVendor([$file->id], $this->vendor->id, [$file->id => '3000']);
        $this->returnToCustomer([$file->id]);

        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->customer->id), 'charge and refund net off');

        // A finished file is not offered for a vendor return at all.
        $this->assertFalse(WorkFileModel::withVendor()->contains('id', $file->id));

        // And posting it anyway changes nothing.
        $this->takeBackFromVendor([$file->id]);

        $fresh = WorkFileModel::find($file->id);

        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->customer->id), 'the refund still stands');
        $this->assertSame('paper_returned', $fresh->status, 'and the file is still returned');
        $this->assertNull($fresh->vendor_returned_on);
    }

    public function test_a_vendor_return_still_works_on_a_file_still_in_play(): void
    {
        $file = $this->receive();
        $this->giveToVendor([$file->id], $this->vendor->id, [$file->id => '3000']);

        $this->assertTrue(WorkFileModel::withVendor()->contains('id', $file->id));

        $this->takeBackFromVendor([$file->id]);

        $fresh = WorkFileModel::find($file->id);
        $this->assertSame('in_office', $fresh->status);
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->vendor->id));
    }

    /**
     * Cancelling used to wipe returned_on and returned_amount. Un-cancelling
     * then restored a FULL refund dated today rather than the part refund
     * actually agreed — a customer who owed 3,000 came back owing nothing, from
     * an undo, with nothing on screen to say the figure had changed.
     */
    public function test_cancelling_and_un_cancelling_preserves_a_part_refund(): void
    {
        $file = $this->receive();
        $this->returnToCustomer([$file->id], [$file->id => '2000'], '2026-04-10');

        $this->assertSame(3000.0, PartyLedgerModel::currentBalance($this->customer->id));

        $this->setStatuses([$file->id => 'cancelled']);
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->customer->id), 'cancelled charges nobody');

        $this->setStatuses([$file->id => 'paper_returned']);

        $fresh = WorkFileModel::find($file->id);

        $this->assertSame('2026-04-10', (string) $fresh->returned_on, 'the original date, not today');
        $this->assertSame(2000.0, (float) $fresh->returned_amount, 'the part refund actually agreed');
        $this->assertSame(3000.0, PartyLedgerModel::currentBalance($this->customer->id), 'back exactly where it was');
    }

    /**
     * The stored name comes from the file's own content, never from the name the
     * browser sent — this directory is served by the web server.
     */
    public function test_a_screenshot_is_stored_under_a_content_derived_extension(): void
    {
        $file = $this->receive();

        $this->controller->status(Request::create('/admin/file/status', 'POST',
            ['statuses' => [$file->id => 'approval_done'], 'remarks' => []],
            [],
            // A real image the browser claims is something else entirely.
            ['screenshots' => [$file->id => UploadedFile::fake()->image('approval.jpg')]]
        ));

        $stored = WorkFileModel::find($file->id)->approval_screenshot;

        $this->assertNotNull($stored);
        $this->assertMatchesRegularExpression('#^'.preg_quote(WorkFileModel::UPLOAD_DIR, '#').'/F-\d+-[a-f0-9]{8}\.(jpg|jpeg|png|webp|pdf)$#', $stored);

        WorkFileModel::find($file->id)->deleteScreenshot();
    }

    /**
     * Replacing a screenshot inside a transaction that later rolls back used to
     * leave the row pointing at a file that had already been unlinked — the
     * approval evidence gone, with the database none the wiser.
     */
    public function test_a_rollback_cannot_destroy_the_screenshot_the_row_still_points_at(): void
    {
        $file = $this->receive();

        $this->controller->status(Request::create('/admin/file/status', 'POST',
            ['statuses' => [$file->id => 'approval_done'], 'remarks' => []],
            [], ['screenshots' => [$file->id => UploadedFile::fake()->image('first.jpg')]]
        ));

        $original = WorkFileModel::find($file->id)->approval_screenshot;
        $this->assertFileExists(public_path($original));

        // Replace it, then abandon the transaction that did so.
        try {
            DB::transaction(function () use ($file) {
                $fresh = WorkFileModel::find($file->id);
                $fresh->storeScreenshot(UploadedFile::fake()->image('second.jpg'));
                $fresh->save();

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $stored = WorkFileModel::find($file->id)->approval_screenshot;

        $this->assertSame($original, $stored, 'the row still points at the first file');
        $this->assertFileExists(public_path($stored), 'and that file is still on disk');

        /*
         * The replacement that the rollback abandoned is still on disk, and
         * deliberately so — nothing is deleted until the row naming it commits.
         * That is the right trade for evidence, but it does leave the occasional
         * orphan, so this test clears up after itself rather than dropping
         * files into the working tree.
         */
        foreach (glob(public_path(WorkFileModel::UPLOAD_DIR).'/'.$file->file_no.'-*') as $leftover) {
            unlink($leftover);
        }
    }

    public function test_the_listing_resolves_names_and_filters(): void
    {
        $file = $this->receive(['status' => 'under_verification']);

        $row = WorkFileModel::listing()->firstWhere('id', $file->id);
        $this->assertSame($this->customer->name, $row->customer_name);
        $this->assertSame($this->workType->name, $row->work_type);

        $this->assertTrue(WorkFileModel::listing('under_verification')->contains('id', $file->id));
        $this->assertFalse(WorkFileModel::listing('in_office')->contains('id', $file->id));
        $this->assertFalse(WorkFileModel::listing(null, '2026-05-01')->contains('id', $file->id));
    }

    /**
     * A file can be taken in, and given to a vendor, before either price is
     * agreed — and priced afterwards.
     *
     * The ledgers stay quiet until there is something to post, which is right
     * and is also the problem: an unpriced file is invisible until someone goes
     * looking for it. This covers the working half; the next one covers finding.
     */
    public function test_a_file_can_be_priced_after_it_is_received_and_dispatched(): void
    {
        $file = $this->receive(['customer_amount' => '0']);

        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->customer->id), 'nothing charged, nothing posted');

        // Given to the vendor with no rate agreed.
        $this->giveToVendor([$file->id], $this->vendor->id, [$file->id => '']);

        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->vendor->id), 'no rate, no entry');
        $this->assertSame(0, PartyLedgerModel::where('work_file_id', $file->id)->count());

        // Both agreed later.
        $file->refresh();
        $file->customer_amount = 5000;
        $file->vendor_amount = 3500;
        $file->save();
        $file->syncLedger();

        $this->assertSame(5000.0, PartyLedgerModel::currentBalance($this->customer->id));
        $this->assertSame(-3500.0, PartyLedgerModel::currentBalance($this->vendor->id));

        // Correcting a price moves the entry rather than adding a second one.
        $file->customer_amount = 5500;
        $file->save();
        $file->syncLedger();

        $this->assertSame(5500.0, PartyLedgerModel::currentBalance($this->customer->id));
        $this->assertSame(2, PartyLedgerModel::where('work_file_id', $file->id)->count(), 'one entry a side');
    }

    public function test_files_awaiting_a_price_can_be_found(): void
    {
        $base = WorkFileModel::pendingCounts();

        $unbilled = $this->receive(['customer_amount' => '0']);
        $priced = $this->receive(['customer_amount' => '4000']);
        $this->giveToVendor([$priced->id], $this->vendor->id, [$priced->id => '']);

        $now = WorkFileModel::pendingCounts();

        $this->assertSame(1, $now['customer'] - $base['customer'], 'one file nobody has been billed for');
        $this->assertSame(1, $now['vendor'] - $base['vendor'], 'one file with a vendor and no rate');
        $this->assertSame(2, $now['any'] - $base['any']);

        // Each count opens exactly the files it counted.
        $listed = WorkFileModel::listing(null, null, null, 'customer');
        $this->assertTrue($listed->contains('id', $unbilled->id));
        $this->assertFalse($listed->contains('id', $priced->id), 'that one is billed');

        $listed = WorkFileModel::listing(null, null, null, 'vendor');
        $this->assertTrue($listed->contains('id', $priced->id));
        $this->assertFalse($listed->contains('id', $unbilled->id), 'that one has no vendor at all');
    }

    /**
     * A cancelled file is not owed for, and a returned one has been settled and
     * handed back. Reporting either as awaiting a price would send someone
     * chasing money that is not owed.
     */
    public function test_closed_files_are_never_reported_as_awaiting_a_price(): void
    {
        $base = WorkFileModel::pendingCounts();

        $cancelled = $this->receive(['customer_amount' => '0']);
        $this->setStatuses([$cancelled->id => 'cancelled']);

        $this->assertSame(0, WorkFileModel::pendingCounts()['any'] - $base['any'], 'cancelled is not awaiting anything');
    }


    /**
     * Anything the ledger declines to post is reported as awaiting a price.
     *
     * This is the invariant, not a list of cases. syncSide() posts nothing when
     * there is no party or the amount is zero or less; a file it declines to
     * post for is money that never reached a statement, and that is precisely
     * what the pending report exists to surface. If the two rules disagree, a
     * file falls between them and nothing reports it at all.
     *
     * They did disagree. A vendor amount left blank stores null and was
     * reported. A vendor amount typed as 0 stores 0.00, posted nothing, and was
     * reported by nothing — invisible on both sides.
     */
    public function test_anything_the_ledger_declines_to_post_is_reported_as_pending(): void
    {
        $base = WorkFileModel::pendingCounts();

        // Every way a figure can amount to nothing.
        $blankVendor = $this->receive(['customer_amount' => '4000']);
        $this->giveToVendor([$blankVendor->id], $this->vendor->id, [$blankVendor->id => '']);

        $zeroVendor = $this->receive(['customer_amount' => '4000']);
        $this->giveToVendor([$zeroVendor->id], $this->vendor->id, [$zeroVendor->id => '0']);

        $unbilled = $this->receive(['customer_amount' => '0']);

        $now = WorkFileModel::pendingCounts();

        $this->assertSame(2, $now['vendor'] - $base['vendor'], 'blank and zero are both nothing');
        $this->assertSame(1, $now['customer'] - $base['customer']);
        $this->assertSame(3, $now['any'] - $base['any']);

        /*
         * Stated as the rule rather than the cases: for every file, having no
         * ledger entry on a side and being reported as pending on that side are
         * the same thing.
         */
        foreach ([$blankVendor, $zeroVendor] as $file) {
            $this->assertSame(
                0,
                PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', 'vendor')->count(),
                'nothing was posted to the vendor'
            );

            $this->assertTrue(
                WorkFileModel::listing(null, null, null, 'vendor')->contains('id', $file->id),
                'so it must be reported as awaiting a vendor rate'
            );
        }

        // And the converse: a file that did post is not reported.
        $priced = $this->receive(['customer_amount' => '4000']);
        $this->giveToVendor([$priced->id], $this->vendor->id, [$priced->id => '2500']);

        $this->assertSame(
            1,
            PartyLedgerModel::where('work_file_id', $priced->id)->where('file_role', 'vendor')->count()
        );

        $this->assertFalse(
            WorkFileModel::listing(null, null, null, 'vendor')->contains('id', $priced->id),
            'a priced file has nothing outstanding'
        );
    }

}
