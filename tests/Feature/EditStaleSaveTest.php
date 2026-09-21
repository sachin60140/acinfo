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
 * An edit page left open must not write the file back over what changed since.
 *
 * The edit form posts every field it shows, the status among them, and for a
 * folder of one work that status reaches the work. So saving a page drawn
 * before a colleague returned the file undid the return and took its refund
 * off the ledger; an approval made meanwhile lost its date; a corrected price
 * went back. The page now carries a fingerprint of what it was drawn from
 * (WorkFileModel::editFingerprint) and a save from a page that no longer
 * matches the file is refused.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class EditStaleSaveTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private WorkTypeModel $tr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Stale Edit Admin';
        $this->admin->email = 'stale-edit-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = new PartyModel;
        $this->customer->party_type = 'customer';
        $this->customer->name = 'Customer '.uniqid().' stale edit';
        $this->customer->mobile = '93400'.random_int(10000, 99999);
        $this->customer->is_active = 1;
        $this->customer->save();

        $this->tr = new WorkTypeModel;
        $this->tr->name = 'TR '.uniqid();
        $this->tr->is_active = 1;
        $this->tr->save();
    }

    /** A folder of $works works, charged 3,000 each. */
    private function file(int $works = 1, string $status = WorkFileModel::IN_OFFICE): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-SE-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01SE'.random_int(1000, 9999);
        $file->work_type_id = $this->tr->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 3000 * $works;
        $file->status = $status;
        $file->save();

        for ($i = 0; $i < $works; $i++) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $i === 0 ? $this->tr->id : $this->otherType()->id;
            $item->customer_amount = 3000;
            $item->status = $status;
            $item->save();
        }

        $file->syncLedger();

        return $file->fresh();
    }

    private function otherType(): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = 'HPA '.uniqid();
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    /** The edit page as a browser gets it: what it shows, and what it was drawn from. */
    private function draw(WorkFileModel $file): array
    {
        return $this->actingAs($this->admin)
            ->getJson(route('workfile.edit', $file->id))->assertOk()->json('props');
    }

    /** The save the page makes, from what it drew, with anything typed over it. */
    private function saveFrom(WorkFileModel $file, array $page, array $typed = [])
    {
        $values = $page['values'];

        $post = [
            'file_no' => $values['file_no'],
            'received_date' => '2026-09-01',
            'work_type_id' => $values['work_type_id'],
            'registration_no' => $values['registration_no'],
            'customer_id' => $values['customer_id'],
            'customer_amount' => $values['customer_amount'],
            'status' => $values['status'],
            'drawn' => $page['drawn'],
            'was_status' => $page['wasStatus'],
        ];

        // A folder of several works posts each of them as drawn.
        if (count($page['items']) > 1) {
            foreach ($page['items'] as $item) {
                $post['items'][$item['id']] = [
                    'work_type_id' => $item['work_type_id'],
                    'customer_amount' => $item['customer_amount'],
                ];
            }
        }

        return $this->actingAs($this->admin)
            ->from(route('workfile.edit', $file->id))
            ->post(route('workfile.edit', $file->id), array_merge($post, $typed));
    }

    private function returnIt(WorkFileModel $file): void
    {
        $this->actingAs($this->admin)->post(route('workfile.customerreturn'), [
            'returned_on' => now()->toDateString(),
            'files' => [$file->id],
            'remark' => 'Customer took the papers back',
        ])->assertSessionHasNoErrors();

        $this->assertSame(WorkFileModel::RETURNED, $file->fresh()->status, 'the premise: returned');
    }

    private function refunds(WorkFileModel $file): int
    {
        return PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', 'customer_return')->count();
    }

    // ------------------------------------------------------ the reported cases

    /** Returned by a colleague, then the vehicle number fixed on a page drawn before. */
    public function test_a_return_made_since_is_not_undone_by_an_old_edit_page(): void
    {
        $file = $this->file();
        $page = $this->draw($file);

        $this->returnIt($file);
        $this->assertSame(1, $this->refunds($file), 'the premise: refunded');
        $balance = PartyLedgerModel::currentBalance($this->customer->id);

        $this->saveFrom($file, $page, ['registration_no' => 'BR01ZZ9999'])
            ->assertRedirect(route('workfile.edit', $file->id))
            ->assertSessionHas('error');

        $file->refresh();

        $this->assertSame(WorkFileModel::RETURNED, $file->status, 'the return was undone');
        $this->assertSame(WorkFileModel::RETURNED, $file->items()->first()->status, 'the work was put back');
        $this->assertNotNull($file->returned_on, 'the return lost its date');
        $this->assertSame(1, $this->refunds($file), 'the refund was taken off the ledger');
        $this->assertSame($balance, PartyLedgerModel::currentBalance($this->customer->id), 'the customer was charged again');
        $this->assertNotSame('BR01ZZ9999', $file->registration_no, 'and nothing else was saved either');
    }

    public function test_an_approval_made_since_is_not_undone_by_an_old_edit_page(): void
    {
        $file = $this->file(1, WorkFileModel::DISPATCHED);
        $page = $this->draw($file);

        // A colleague records the approval, with its document and its day.
        $job = $file->items()->first();
        $job->status = WorkFileModel::APPROVED;
        $job->approved_on = '2026-09-15';
        $job->approval_screenshot = 'approval-'.uniqid().'.png';
        $job->save();
        $file->load('items');
        $file->rollUp();
        $file->save();

        $this->saveFrom($file, $page)->assertSessionHas('error');

        $job->refresh();

        $this->assertSame(WorkFileModel::APPROVED, $job->status, 'the approval was undone');
        $this->assertSame('2026-09-15', date('Y-m-d', strtotime($job->approved_on)), 'the approval lost its date');
    }

    /** A folder of several works: its status went down first and wiped the return before the roll-up put it back. */
    public function test_a_return_made_since_survives_an_old_edit_page_on_a_folder_of_several_works(): void
    {
        $file = $this->file(2);
        $page = $this->draw($file);

        $this->returnIt($file);

        $this->saveFrom($file, $page)->assertSessionHas('error');

        $file->refresh();

        $this->assertSame(WorkFileModel::RETURNED, $file->status);
        $this->assertNotNull($file->returned_on, 'the return lost its date');
        $this->assertSame(1, $this->refunds($file));
    }

    /** Not only the status: a price corrected by a colleague is not put back either. */
    public function test_a_price_corrected_since_is_not_put_back(): void
    {
        $file = $this->file();
        $page = $this->draw($file);

        $file->customer_amount = 3500;
        $file->save();
        $job = $file->items()->first();
        $job->customer_amount = 3500;
        $job->save();

        // With a reason given, so it is this guard that stops it and not the
        // one asking why a price moved.
        $this->saveFrom($file, $page, ['registration_no' => 'BR01ZZ1111', 'price_remark' => 'Fixing the number plate'])
            ->assertSessionHas('error');

        $this->assertStringContainsString('has changed since you opened it', session('error'));
        $this->assertEquals(3500, $file->fresh()->customer_amount);
    }

    /**
     * A document's name is what the customer sees it as, and the form sends
     * back every name box as it was drawn. Found in review: a name a colleague
     * gave since was put back to the scanner's.
     */
    public function test_a_document_named_since_is_not_put_back(): void
    {
        $file = $this->file();

        $doc = new \App\Models\WorkFileDocumentModel;
        $doc->work_file_id = $file->id;
        $doc->path = 'uploads/test-'.uniqid().'.pdf';
        $doc->original_name = 'SCAN0001.pdf';
        $doc->save();

        $page = $this->draw($file);

        // A colleague names it.
        $doc->title = 'Form 30';
        $doc->save();

        $this->saveFrom($file, $page, [
            'registration_no' => 'BR01ZZ5555',
            'document_names' => [$doc->id => 'SCAN0001'],
        ])->assertSessionHas('error');

        $this->assertSame('Form 30', $doc->fresh()->title);
    }

    // ------------------------------------------------- the same save, twice

    /**
     * A double click sends the form twice. The first save changes the file,
     * so the second no longer matches it — and refused, it said nothing was
     * saved and that a colleague had changed the file. It is told it was saved.
     */
    public function test_the_same_save_sent_twice_is_told_it_was_saved(): void
    {
        $expense = new \App\Models\ExpenseTypeModel;
        $expense->name = 'Challan '.uniqid();
        $expense->is_active = 1;
        $expense->save();

        $file = $this->file();
        $page = $this->draw($file);

        $typed = [
            'registration_no' => 'BR01ZZ6666',
            'new_expenses' => [['expense_type_id' => $expense->id, 'amount' => 150, 'spent_on' => '2026-09-10', 'remark' => '']],
        ];

        $this->saveFrom($file, $page, $typed)->assertRedirect(route('workfile.index'))->assertSessionHas('success');

        $this->saveFrom($file, $page, $typed)
            ->assertRedirect(route('workfile.index'))
            ->assertSessionHas('success');

        $this->assertStringContainsString('was saved', session('success'));
        $this->assertSame('BR01ZZ6666', $file->fresh()->registration_no);
        $this->assertSame(1, $file->expenses()->count(), 'the expense was added twice');
    }

    /** A different change from the same old page is not a repeat, and is refused like any stale page. */
    public function test_a_different_change_from_the_same_old_page_is_still_refused(): void
    {
        $file = $this->file();
        $page = $this->draw($file);

        $this->saveFrom($file, $page, ['registration_no' => 'BR01ZZ7777'])->assertSessionHas('success');

        // Back to the old page, and something else changed on it.
        $this->saveFrom($file, $page, ['registration_no' => 'BR01ZZ8888'])
            ->assertRedirect(route('workfile.edit', $file->id))
            ->assertSessionHas('error');

        $this->assertSame('BR01ZZ7777', $file->fresh()->registration_no);
    }

    /**
     * Found in review: the same page and the same typed answers, but a
     * different file attached — Back, the right screenshot picked, saved
     * again. That is a different change, not a repeat, and must not be told it
     * was saved while its file is thrown away.
     */
    public function test_a_repeat_with_a_different_file_attached_is_not_taken_for_a_repeat(): void
    {
        $file = $this->file();
        $page = $this->draw($file);

        $this->saveFrom($file, $page, ['registration_no' => 'BR01ZZ9191'])->assertSessionHas('success');

        $this->saveFrom($file, $page, [
            'registration_no' => 'BR01ZZ9191',
            'approval_screenshot' => \Illuminate\Http\UploadedFile::fake()->image('the-right-one.png'),
        ])
            ->assertRedirect(route('workfile.edit', $file->id))
            ->assertSessionHas('error');

        $this->assertStringContainsString('has changed since you opened it', session('error'));
        $this->assertNull($file->fresh()->approval_screenshot);
    }

    // ------------------------------------------------------ what the refusal says

    public function test_it_says_what_the_file_is_now_and_what_to_do(): void
    {
        $file = $this->file();
        $page = $this->draw($file);

        $this->returnIt($file);

        $this->saveFrom($file, $page);

        $error = session('error');

        $this->assertStringContainsString($file->file_no.' has changed since you opened it', $error);
        $this->assertStringContainsString('(now Paper Returned to Customer)', $error);
        $this->assertStringContainsString('Nothing was saved', $error);
    }

    /**
     * The typed values are not carried back: put into the form again they
     * would carry the old status straight into the next save.
     */
    public function test_the_page_it_sends_back_to_shows_the_file_as_it_is_now(): void
    {
        $file = $this->file();
        $page = $this->draw($file);

        $this->returnIt($file);

        $this->saveFrom($file, $page, ['registration_no' => 'BR01ZZ2222']);

        $again = $this->draw($file);

        $this->assertSame(WorkFileModel::RETURNED, $again['values']['status']);
        $this->assertNotSame('BR01ZZ2222', $again['values']['registration_no']);
        $this->assertNotSame($page['drawn'], $again['drawn']);
    }

    /** Checked before validation, so a stale page with a mistake on it is still told it is stale. */
    public function test_a_stale_page_is_told_so_before_anything_else(): void
    {
        $file = $this->file();
        $page = $this->draw($file);

        $this->returnIt($file);

        $this->saveFrom($file, $page, ['customer_amount' => 'not a number'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('error');

        $this->assertStringContainsString('has changed since you opened it', session('error'));
    }

    /**
     * A save sent back for some other reason redraws the typed values, the
     * old status among them. The redraw still answers for when the page was
     * first drawn, so a change made since is caught on the next try.
     */
    public function test_a_page_sent_back_for_another_reason_still_answers_for_when_it_was_drawn(): void
    {
        $file = $this->file();
        $page = $this->draw($file);

        // Refused for a reason of its own: a price moved with no reason given.
        $this->saveFrom($file, $page, ['customer_amount' => 4000])->assertSessionHasErrors('price_remark');

        /*
         * In the moment between that refusal and the page being drawn again,
         * a colleague corrects the vehicle number. Made directly rather than
         * through a request, because a request here would be the one the typed
         * values are kept for.
         */
        $file->registration_no = 'BR01COLL01';
        $file->save();

        // The redraw puts the typed values back, and answers for when the page
        // was first drawn — not for the file as it is now.
        $redrawn = $this->draw($file);
        $this->assertEquals(4000, $redrawn['values']['customer_amount'], 'the typed values were not put back');
        $this->assertSame($page['drawn'], $redrawn['drawn'], 'the redraw took a fresh fingerprint');

        // So the next save, made against values typed before the colleague's
        // change, is refused rather than writing their number plate back.
        $this->saveFrom($file, $redrawn, [
            'customer_amount' => 4000,
            'price_remark' => 'Agreed on the phone',
            'registration_no' => $page['values']['registration_no'],
        ])->assertSessionHas('error');

        $this->assertSame('BR01COLL01', $file->fresh()->registration_no);
        $this->assertEquals(3000, $file->fresh()->customer_amount);
    }

    // ------------------------------------------------------ nothing else moved

    public function test_an_edit_from_an_up_to_date_page_saves(): void
    {
        $file = $this->file();
        $page = $this->draw($file);

        $this->saveFrom($file, $page, ['registration_no' => 'BR01ZZ3333'])
            ->assertRedirect(route('workfile.index'))
            ->assertSessionHas('success');

        $this->assertSame('BR01ZZ3333', $file->fresh()->registration_no);
    }

    /** Drawing the page twice gives the same answer: nothing about looking at a file changes it. */
    public function test_the_fingerprint_is_the_same_each_time_the_file_is_drawn(): void
    {
        $file = $this->file(2);

        $this->assertSame($this->draw($file)['drawn'], $this->draw($file)['drawn']);
        $this->assertSame($file->editFingerprint(), $file->fresh()->editFingerprint());
    }

    /** A page drawn before this was added posts no fingerprint, and is saved as it always was. */
    public function test_a_post_from_an_older_page_without_a_fingerprint_behaves_as_before(): void
    {
        $file = $this->file();
        $page = $this->draw($file);

        $this->saveFrom($file, $page, ['drawn' => null, 'was_status' => null, 'registration_no' => 'BR01ZZ4444'])
            ->assertSessionHas('success');

        $this->assertSame('BR01ZZ4444', $file->fresh()->registration_no);
    }

    public function test_the_receive_screen_carries_no_fingerprint(): void
    {
        $props = $this->actingAs($this->admin)->getJson(route('workfile.receive'))->assertOk()->json('props');

        $this->assertEmpty($props['drawn'] ?? '');
    }
}
