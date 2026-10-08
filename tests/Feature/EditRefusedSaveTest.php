<?php

namespace Tests\Feature;

use App\Models\ExpenseTypeModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileDocumentModel;
use App\Models\WorkFileExpenseModel;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * An edit save sent back comes back with everything that was typed.
 *
 * The boxes at the top of the edit screen always came back as typed. The rest
 * was read from the database again: a save refused for a PDF over 10 MB
 * returned without the corrected charge on the second work, the work added,
 * the expense added and the reason for the price. The office fixed what was
 * reported, saved again and was told "updated successfully" — and the
 * customer's statement kept the old charge, and the expense was never
 * recorded. Found in the health check.
 *
 * So the page is handed what the refused save posted, and the form starts from
 * it. Only a refused save's: a page sent back because the file changed under
 * it is drawn as the file is now, on purpose (see EditStaleSaveTest).
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class EditRefusedSaveTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpa;

    private WorkTypeModel $hpt;

    private ExpenseTypeModel $challan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Refused Save Admin';
        $this->admin->email = 'refused-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = new PartyModel;
        $this->customer->party_type = 'customer';
        $this->customer->name = 'Customer '.uniqid().' refused save';
        $this->customer->mobile = '93800'.random_int(10000, 99999);
        $this->customer->is_active = 1;
        $this->customer->save();

        $this->tr = $this->workType('TR');
        $this->hpa = $this->workType('HPA');
        $this->hpt = $this->workType('HPT');

        $this->challan = new ExpenseTypeModel;
        $this->challan->name = 'Challan '.uniqid();
        $this->challan->is_active = 1;
        $this->challan->save();
    }

    private function workType(string $name): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    /** @param  array<int, WorkTypeModel>  $types  one work each, charged 3,000 */
    private function file(array $types): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-RS-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01RS'.random_int(1000, 9999);
        $file->work_type_id = $types[0]->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 3000 * count($types);
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        foreach ($types as $type) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = 3000;
            $item->status = WorkFileModel::IN_OFFICE;
            $item->save();
        }

        $file->syncLedger();

        return $file->fresh();
    }

    private function spend(WorkFileModel $file, float $amount): WorkFileExpenseModel
    {
        $expense = new WorkFileExpenseModel;
        $expense->work_file_id = $file->id;
        $expense->expense_type_id = $this->challan->id;
        $expense->amount = $amount;
        $expense->spent_on = '2026-09-02';
        $expense->save();

        return $expense;
    }

    /** A document row only: nothing here is saved, so nothing reaches the disk. */
    private function document(WorkFileModel $file, string $name): WorkFileDocumentModel
    {
        $doc = new WorkFileDocumentModel;
        $doc->work_file_id = $file->id;
        $doc->path = WorkFileModel::DOC_DIR.'/never-written-'.uniqid().'.pdf';
        $doc->original_name = $name.'.pdf';
        $doc->size = 1024;
        $doc->uploaded_by = $this->admin->id;
        $doc->save();

        return $doc;
    }

    private function jobOf(WorkFileModel $file, WorkTypeModel $type): WorkFileItemModel
    {
        return WorkFileItemModel::where('work_file_id', $file->id)->where('work_type_id', $type->id)->firstOrFail();
    }

    /** The edit page as a browser gets it. */
    private function draw(WorkFileModel $file): array
    {
        return $this->actingAs($this->admin)
            ->getJson(route('workfile.edit', $file->id))->assertOk()->json('props');
    }

    private function save(WorkFileModel $file, array $post)
    {
        return $this->actingAs($this->admin)
            ->from(route('workfile.edit', $file->id))
            ->post(route('workfile.edit', $file->id), $post + [
                'file_no' => $file->file_no,
                'received_date' => '2026-09-01',
                'work_type_id' => $file->work_type_id,
                'registration_no' => $file->registration_no,
                'customer_id' => $file->customer_id,
                'customer_amount' => (string) $file->customer_amount,
                'status' => $file->status,
                'drawn' => $file->editFingerprint(),
                'was_status' => $file->status,
            ]);
    }

    /** A scanned PDF too large to take: the case the office reported. */
    private function bigPdf(): UploadedFile
    {
        return UploadedFile::fake()->create('scan.pdf', WorkFileDocumentModel::MAX_KB + 1024, 'application/pdf');
    }

    // ------------------------------------------------------- the reported case

    public function test_a_save_refused_for_a_large_pdf_comes_back_with_everything_typed(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);
        $transfer = $this->jobOf($file, $this->tr);
        $addition = $this->jobOf($file, $this->hpa);
        $kept = $this->spend($file, 300);
        $dropped = $this->spend($file, 120);
        $named = $this->document($file, 'scan_0001');
        $going = $this->document($file, 'scan_0002');

        $this->save($file, [
            'customer_amount' => '6500',
            'items' => [
                $transfer->id => ['work_type_id' => $this->tr->id, 'customer_amount' => '3000', 'vendor_amount' => '', 'in_house' => '1'],
                $addition->id => ['work_type_id' => $this->hpa->id, 'customer_amount' => '3500', 'vendor_amount' => '2000'],
            ],
            'price_remark' => 'Customer agreed the higher charge',
            'new_works' => [['work_type_id' => $this->hpt->id, 'amount' => '2500', 'vendor_amount' => '1500']],
            'expenses' => [$kept->id => ['expense_type_id' => $this->challan->id, 'amount' => '350', 'spent_on' => '2026-09-02', 'remark' => 'Corrected']],
            'remove_expenses' => [$dropped->id],
            'new_expenses' => [['expense_type_id' => $this->challan->id, 'amount' => '450', 'spent_on' => '2026-09-03', 'remark' => 'Transfer challan']],
            'document_names' => [$named->id => 'RC', $going->id => 'scan_0002'],
            'remove_documents' => [$going->id],
            'documents' => [3 => ['file' => $this->bigPdf(), 'title' => 'Form 29']],
        ])->assertSessionHasErrors('documents.3.file');

        // The premise: refused, and nothing of it saved.
        $this->assertEquals(3000, $addition->fresh()->customer_amount);
        $this->assertSame(2, $file->items()->count());
        $this->assertSame(2, $file->expenses()->count());

        $typed = $this->draw($file)['typed'];

        $this->assertNotNull($typed, 'the page came back without what was typed');

        // Every work as typed: the corrected charge, the rate, the tick.
        $this->assertEquals(3500, $typed['items'][$addition->id]['customer_amount']);
        $this->assertEquals(2000, $typed['items'][$addition->id]['vendor_amount']);
        $this->assertTrue($typed['items'][$transfer->id]['in_house']);
        $this->assertFalse($typed['items'][$addition->id]['in_house']);
        $this->assertSame('Customer agreed the higher charge', $typed['priceRemark']);

        // The work added.
        $this->assertCount(1, $typed['newWorks']);
        $this->assertEquals($this->hpt->id, $typed['newWorks'][0]['work_type_id']);
        $this->assertEquals(2500, $typed['newWorks'][0]['amount']);
        $this->assertEquals(1500, $typed['newWorks'][0]['vendor_amount']);

        // The expenses: corrected, taken off, added.
        $this->assertEquals(350, $typed['expenses'][$kept->id]['amount']);
        $this->assertSame('Corrected', $typed['expenses'][$kept->id]['remark']);
        $this->assertSame([$dropped->id], $typed['removeExpenses']);
        $this->assertCount(1, $typed['newExpenses']);
        $this->assertEquals(450, $typed['newExpenses'][0]['amount']);
        $this->assertSame('2026-09-03', $typed['newExpenses'][0]['spent_on']);
        $this->assertEquals($this->challan->id, $typed['newExpenses'][0]['expense_type_id']);

        // The documents: renamed, taken off, and the name typed for the PDF
        // that was too large, under the row it was typed in.
        $this->assertSame('RC', $typed['documentNames'][$named->id]);
        $this->assertSame([$going->id], $typed['removeDocuments']);
        $this->assertSame([['key' => 3, 'title' => 'Form 29']], $typed['newDocuments']);
    }

    /** Work marked to come off comes back marked. */
    public function test_work_marked_to_come_off_comes_back_marked(): void
    {
        $file = $this->file([$this->tr, $this->hpa, $this->hpt]);
        $transfer = $this->jobOf($file, $this->tr);
        $addition = $this->jobOf($file, $this->hpa);
        $termination = $this->jobOf($file, $this->hpt);

        $this->save($file, [
            'items' => [
                $transfer->id => ['work_type_id' => $this->tr->id, 'customer_amount' => '3000', 'vendor_amount' => ''],
                $addition->id => ['work_type_id' => $this->hpa->id, 'customer_amount' => '3000', 'vendor_amount' => ''],
            ],
            'remove_works' => [$termination->id],
            'documents' => [0 => ['file' => $this->bigPdf(), 'title' => 'Form 29']],
        ])->assertSessionHasErrors('documents.0.file');

        $this->assertSame([$termination->id], $this->draw($file)['typed']['removeWorks']);
    }

    /**
     * A file of one work is priced in the boxes at the top, which come back as
     * typed. What the page compares them with is what is stored, so a price
     * typed and refused still reads as a price changing — and the reason
     * typed for it is on the page to be sent again.
     */
    public function test_a_one_work_file_compares_what_was_typed_with_what_is_stored(): void
    {
        $file = $this->file([$this->tr]);

        $this->save($file, [
            'customer_amount' => '3600',
            'price_remark' => 'Customer agreed it',
            'documents' => [0 => ['file' => $this->bigPdf(), 'title' => 'Form 29']],
        ])->assertSessionHasErrors('documents.0.file');

        $page = $this->draw($file);

        $this->assertEquals(3600, $page['values']['customer_amount']);
        $this->assertEquals(3000, $page['priced']['customer_amount']);
        $this->assertNull($page['priced']['vendor_amount']);
        $this->assertSame('Customer agreed it', $page['typed']['priceRemark']);
    }

    // ---------------------------------------------------- and only a refused save

    public function test_a_page_drawn_fresh_starts_from_the_file(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $page = $this->draw($file);

        $this->assertNull($page['typed']);
        $this->assertEquals(6000, $page['priced']['customer_amount']);
    }

    /**
     * Sent back because the file changed under the page: drawn as it is now,
     * without the typed values, which were typed against the old file.
     */
    public function test_a_page_sent_back_as_stale_is_not_handed_what_was_typed(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);
        $drawn = $file->editFingerprint();
        $addition = $this->jobOf($file, $this->hpa);

        // A colleague's change, made since the page was drawn.
        $file->registration_no = 'BR01COLL02';
        $file->save();

        $this->save($file->fresh(), [
            'drawn' => $drawn,
            'items' => [$addition->id => ['work_type_id' => $this->hpa->id, 'customer_amount' => '3500', 'vendor_amount' => '']],
            'new_expenses' => [['expense_type_id' => $this->challan->id, 'amount' => '450', 'spent_on' => '2026-09-03']],
        ])->assertSessionHas('error');

        $this->assertNull($this->draw($file)['typed']);
    }

    // ------------------------------------------------------- the sizes it checks

    /**
     * The page checks a PDF and a screenshot against the figures it is
     * handed. Those have to be the save's own, or the page passes a file the
     * save refuses — which is how the office reached the refusal in the first
     * place — or refuses one the save would take.
     */
    public function test_the_sizes_the_page_checks_are_the_ones_the_save_refuses(): void
    {
        $file = $this->file([$this->tr]);
        $page = $this->draw($file);

        $pdf = (int) $page['pdfMaxKb'];
        $shot = (int) $page['screenshotMaxKb'];

        $this->assertGreaterThan(0, $pdf);
        $this->assertGreaterThan(0, $shot);

        // One kilobyte over each is refused for its size.
        $this->save($file, [
            'status' => WorkFileModel::APPROVED,
            'approval_screenshot' => UploadedFile::fake()->create('shot.png', $shot + 1, 'image/png'),
            'documents' => [0 => ['file' => UploadedFile::fake()->create('scan.pdf', $pdf + 1, 'application/pdf'), 'title' => 'Form 29']],
        ])->assertSessionHasErrors(['documents.0.file', 'approval_screenshot']);

        // Each at the figure is not. Refused for a remark too long, so the
        // validator answers for both files and nothing is written to disk.
        $this->save($file, [
            'status' => WorkFileModel::APPROVED,
            'remarks' => str_repeat('x', 300),
            'approval_screenshot' => UploadedFile::fake()->create('shot.png', $shot, 'image/png'),
            'documents' => [0 => ['file' => UploadedFile::fake()->create('scan.pdf', $pdf, 'application/pdf'), 'title' => 'Form 29']],
        ])->assertSessionHasErrors('remarks')
            ->assertSessionDoesntHaveErrors(['documents.0.file', 'approval_screenshot']);
    }
}
