<?php

namespace Tests\Feature;

use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileDocumentModel;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The papers scanned against a file.
 *
 * Different from the approval screenshots: those are evidence that one work came
 * through, and this is the file's own documents — several of them, because a
 * document gets revised. A corrected form is a new upload and never an
 * overwrite, so what the office actually sent at the time survives.
 *
 * The customer is offered the newest. Through a route that checks whose file it
 * is, for the reason the approval image is: these sit under public/ and are
 * web-served with no authentication, so a link handed out is a link that keeps
 * working for whoever it is forwarded to.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class FileDocumentTest extends TestCase
{
    use DatabaseTransactions;

    /** Temporary files this test made, outside the upload directory. */
    private array $written = [];

    /**
     * What was in the upload directory before the test ran.
     *
     * Swept by listing rather than by following the rows, because the rows are
     * exactly what is missing when something goes wrong: a request that threw
     * after storeUpload() had already moved the file leaves it on disk with
     * nothing pointing at it, and a teardown that reads the table cleans up
     * none of them. Forty-two of those accumulated under public/ while this
     * suite was being written.
     *
     * @var array<string, true>
     */
    private array $before = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->before = array_flip(glob(public_path(WorkFileModel::DOC_DIR).'/*') ?: []);
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        foreach (glob(public_path(WorkFileModel::DOC_DIR).'/*') ?: [] as $path) {
            if (! isset($this->before[$path]) && is_file($path)) {
                unlink($path);
            }
        }

        $this->written = [];
        $this->before = [];

        parent::tearDown();
    }

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Document Admin';
        $user->email = 'document-'.uniqid().'@example.com';
        $user->password = Hash::make('password-for-tests');
        $user->user_type = 1;
        $user->save();

        return $user;
    }

    private function customer(): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = 'customer';
        $party->name = 'Doc Customer '.uniqid();
        $party->mobile = '95000'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function file(PartyModel $customer): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-'.substr((string) microtime(true), -6).random_int(10, 99);
        $file->received_date = '2026-09-01';
        $file->work_type_id = WorkTypeModel::query()->value('id');
        $file->customer_id = $customer->id;
        $file->customer_amount = 5000;
        $file->status = 'in_office';
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $file->work_type_id;
        $item->customer_amount = 5000;
        $item->status = 'in_office';
        $item->save();

        return $file;
    }

    /** A real PDF on disk, small but with the header a mime check reads. */
    private function pdf(string $name = 'form-34.pdf'): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid().'.pdf';

        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        $this->written[] = $path;

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function upload(WorkFileModel $file, array $pdfs)
    {
        $response = $this->actingAs($this->admin())->post('/admin/file/edit/'.$file->id, [
            'file_no' => $file->file_no,
            'received_date' => '2026-09-01',
            'status' => $file->status,
            'work_type_id' => $file->work_type_id,
            'customer_id' => $file->customer_id,
            'customer_amount' => $file->customer_amount,
            'documents' => $pdfs,
        ]);

        foreach (WorkFileDocumentModel::where('work_file_id', $file->id)->get() as $doc) {
            $this->written[] = public_path($doc->path);
        }

        return $response;
    }

    // -------------------------------------------------------------- the office

    public function test_several_documents_can_be_uploaded_at_once(): void
    {
        $file = $this->file($this->customer());

        $this->upload($file, [$this->pdf('form-34.pdf'), $this->pdf('annexure.pdf')]);

        $docs = WorkFileDocumentModel::where('work_file_id', $file->id)->orderBy('id')->get();

        $this->assertCount(2, $docs, 'a form and its annexure are one trip to the scanner');
        $this->assertSame('form-34.pdf', $docs[0]->original_name);
        $this->assertSame('annexure.pdf', $docs[1]->original_name);

        foreach ($docs as $doc) {
            $this->assertTrue(is_file(public_path($doc->path)), 'the file reached disk');
            $this->assertStringStartsWith(WorkFileModel::DOC_DIR.'/', $doc->path);
        }
    }

    /**
     * A revised document supersedes the earlier one; it does not erase it. What
     * the office sent at the time is the record.
     */
    public function test_a_second_upload_does_not_replace_the_first(): void
    {
        $file = $this->file($this->customer());

        $this->upload($file, [$this->pdf('first.pdf')]);
        $this->upload($file, [$this->pdf('second.pdf')]);

        $this->assertSame(2, WorkFileDocumentModel::where('work_file_id', $file->id)->count());
        $this->assertSame('second.pdf', WorkFileDocumentModel::latestFor($file->id)->original_name);
    }

    public function test_the_stored_name_is_never_the_one_the_browser_sent(): void
    {
        $file = $this->file($this->customer());

        $this->upload($file, [$this->pdf('../../evil.pdf')]);

        $doc = WorkFileDocumentModel::latestFor($file->id);

        $this->assertNotNull($doc);
        // The path is generated. Whatever the name claimed, it cannot climb out
        // of the directory this application writes to.
        $this->assertStringStartsWith(WorkFileModel::DOC_DIR.'/', $doc->path);
        $this->assertStringNotContainsString('..', $doc->path);
        $this->assertTrue(WorkFileModel::isStoredUpload($doc->path));
    }

    public function test_something_that_is_not_a_pdf_is_refused(): void
    {
        $file = $this->file($this->customer());

        $path = sys_get_temp_dir().'/'.uniqid().'.pdf';
        file_put_contents($path, 'GIF89a this is not a pdf');
        $this->written[] = $path;

        // Named .pdf and claiming to be one. The check is on the content.
        $this->upload($file, [new UploadedFile($path, 'sneaky.pdf', 'application/pdf', null, true)])
            ->assertSessionHasErrors('documents.0');

        $this->assertSame(0, WorkFileDocumentModel::where('work_file_id', $file->id)->count());
    }

    public function test_a_document_can_be_taken_off_the_file(): void
    {
        $file = $this->file($this->customer());

        $this->upload($file, [$this->pdf()]);

        $doc = WorkFileDocumentModel::latestFor($file->id);
        $onDisk = public_path($doc->path);

        $this->actingAs($this->admin())->post('/admin/file/edit/'.$file->id, [
            'file_no' => $file->file_no,
            'received_date' => '2026-09-01',
            'status' => $file->status,
            'work_type_id' => $file->work_type_id,
            'customer_id' => $file->customer_id,
            'customer_amount' => $file->customer_amount,
            'remove_documents' => [$doc->id],
        ]);

        $this->assertNull($doc->fresh(), 'the row is gone');
        $this->assertFalse(is_file($onDisk), 'and so is the file it named');
    }

    /**
     * The ids arrive in the form body. A document on another file must not be
     * deletable from a page that has no business with it.
     */
    public function test_a_document_on_another_file_is_untouchable(): void
    {
        $mine = $this->file($this->customer());
        $theirs = $this->file($this->customer());

        $this->upload($theirs, [$this->pdf()]);

        $doc = WorkFileDocumentModel::latestFor($theirs->id);

        $this->actingAs($this->admin())->post('/admin/file/edit/'.$mine->id, [
            'file_no' => $mine->file_no,
            'received_date' => '2026-09-01',
            'status' => $mine->status,
            'work_type_id' => $mine->work_type_id,
            'customer_id' => $mine->customer_id,
            'customer_amount' => $mine->customer_amount,
            'remove_documents' => [$doc->id],
        ]);

        $this->assertNotNull($doc->fresh(), 'another file deleted it');
    }

    public function test_the_edit_screen_lists_them_newest_first(): void
    {
        $file = $this->file($this->customer());

        $this->upload($file, [$this->pdf('first.pdf')]);
        $this->upload($file, [$this->pdf('second.pdf')]);

        $docs = $this->actingAs($this->admin())
            ->getJson('/admin/file/edit/'.$file->id)->assertOk()->json('props.documents');

        $this->assertCount(2, $docs);
        $this->assertSame('second.pdf', $docs[0]['name'], 'the newest is read first');
    }

    // ------------------------------------------------------------ the customer

    private function portal(PartyModel $customer, WorkFileModel $file)
    {
        return $this->withSession(['customer_id' => $customer->id])
            ->get(route('customer.file.document', $file->id));
    }

    public function test_a_customer_downloads_the_latest_document_on_their_file(): void
    {
        $customer = $this->customer();
        $file = $this->file($customer);

        $this->upload($file, [$this->pdf('first.pdf')]);
        $this->upload($file, [$this->pdf('the-corrected-form.pdf')]);

        $response = $this->portal($customer, $file)->assertOk();

        // Under the name it arrived with, not the generated one it is stored
        // as: "the-corrected-form.pdf" is what was asked for.
        $this->assertStringContainsString(
            'the-corrected-form.pdf',
            (string) $response->headers->get('content-disposition')
        );

        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
    }

    /**
     * The reason this goes through a route at all. These files sit under
     * public/ with no authentication of their own.
     */
    public function test_another_customers_document_is_not_served(): void
    {
        $mine = $this->customer();
        $theirs = $this->customer();

        $file = $this->file($theirs);
        $this->upload($file, [$this->pdf()]);

        $this->portal($mine, $file)->assertNotFound();

        $this->flushSession();

        $this->get(route('customer.file.document', $file->id))
            ->assertRedirect(route('customer.login'));
    }

    public function test_a_file_with_no_document_has_nothing_to_download(): void
    {
        $customer = $this->customer();
        $file = $this->file($customer);

        $this->portal($customer, $file)->assertNotFound();

        $page = $this->withSession(['customer_id' => $customer->id])
            ->getJson(route('customer.file', $file->id))->assertOk();

        $this->assertNull($page->json('page.document'), 'and nothing offered on the page');
    }

    public function test_the_file_page_offers_the_latest_through_the_application(): void
    {
        $customer = $this->customer();
        $file = $this->file($customer);

        $this->upload($file, [$this->pdf('form-34.pdf')]);

        $page = $this->withSession(['customer_id' => $customer->id])
            ->getJson(route('customer.file', $file->id))->assertOk();

        $this->assertSame('form-34.pdf', $page->json('page.document.name'));
        $this->assertSame(
            route('customer.file.document', $file->id),
            $page->json('page.document.url'),
            'offered through the route, not at its path'
        );

        // The stored path never reaches the customer in any form.
        $body = $this->withSession(['customer_id' => $customer->id])
            ->get(route('customer.file', $file->id))->assertOk()->getContent();

        $this->assertStringNotContainsString(WorkFileModel::DOC_DIR, $body);
    }
}
