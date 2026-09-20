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
        $file->work_type_id = $this->anyWorkType()->id;
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

    /**
     * Post documents the way the edit screen does: one row per PDF, each with
     * the name the office gave it.
     *
     * A bare UploadedFile is named after its own filename, as the form suggests
     * — which keeps the tests that are about something other than naming
     * readable. A row given as an array is sent exactly as written, keys and
     * all, for the tests that are about naming.
     */
    private function upload(WorkFileModel $file, array $pdfs, array $extra = [])
    {
        $rows = [];

        foreach ($pdfs as $key => $pdf) {
            $rows[$key] = $pdf instanceof UploadedFile
                ? ['file' => $pdf, 'title' => preg_replace('/\.pdf$/i', '', basename($pdf->getClientOriginalName()))]
                : $pdf;
        }

        $response = $this->actingAs($this->admin())->post('/admin/file/edit/'.$file->id, [
            'file_no' => $file->file_no,
            'received_date' => '2026-09-01',
            'status' => $file->status,
            'work_type_id' => $file->work_type_id,
            'customer_id' => $file->customer_id,
            'customer_amount' => $file->customer_amount,
            'documents' => $rows,
        ] + $extra);

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
            ->assertSessionHasErrors('documents.0.file');

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
        $this->assertSame('second', $docs[0]['name'], 'the newest is read first');
        $this->assertSame('second.pdf', $docs[0]['arrived'], 'and what it arrived as is still there to see');
    }

    // ------------------------------------------------------------ naming them

    /**
     * The point of the change: each PDF carries the name the office gave it.
     *
     * A folder's papers are an RC, a Form 29 and an NOC, and the customer is
     * offered all of them — from a list, which is only any use if the entries
     * are called something a person would recognise.
     */
    public function test_each_pdf_is_saved_under_the_name_it_was_given(): void
    {
        $file = $this->file($this->customer());

        $this->upload($file, [
            ['file' => $this->pdf('scan_00123.pdf'), 'title' => 'RC'],
            ['file' => $this->pdf('IMG-20260912-WA0004.pdf'), 'title' => 'Form 29'],
        ])->assertSessionHasNoErrors();

        $docs = WorkFileDocumentModel::where('work_file_id', $file->id)->orderBy('id')->get();

        $this->assertSame(['RC', 'Form 29'], $docs->pluck('title')->all());

        // What the scanner called them is kept, for telling scans apart.
        $this->assertSame('scan_00123.pdf', $docs[0]->original_name);
    }

    public function test_a_pdf_with_no_name_is_refused(): void
    {
        $file = $this->file($this->customer());

        $this->upload($file, [['file' => $this->pdf(), 'title' => '']])
            ->assertSessionHasErrors('documents.0.title');

        $this->assertSame(0, WorkFileDocumentModel::where('work_file_id', $file->id)->count());
    }

    /** A name typed with no PDF behind it is a document someone thinks they attached. */
    public function test_a_name_with_no_pdf_is_refused(): void
    {
        $file = $this->file($this->customer());

        $this->upload($file, [['title' => 'NOC']])
            ->assertSessionHasErrors('documents.0.file');
    }

    /** The form always offers one more row than is filled. It saves nothing. */
    public function test_the_spare_empty_row_is_ignored(): void
    {
        $file = $this->file($this->customer());

        $this->upload($file, [
            ['file' => $this->pdf(), 'title' => 'RC'],
            ['title' => ''],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, WorkFileDocumentModel::where('work_file_id', $file->id)->count());
    }

    /**
     * Names are paired with files by the row's own key.
     *
     * The form names its rows by a counter that never reuses a number, so a
     * row removed from the middle leaves a gap. Pairing by position in a second
     * list would slide every name after the gap onto the wrong PDF.
     */
    public function test_names_stay_with_their_own_pdfs_across_a_gap(): void
    {
        $file = $this->file($this->customer());

        $this->upload($file, [
            3 => ['file' => $this->pdf('a.pdf'), 'title' => 'RC'],
            7 => ['title' => ''],
            9 => ['file' => $this->pdf('b.pdf'), 'title' => 'NOC'],
        ])->assertSessionHasNoErrors();

        $docs = WorkFileDocumentModel::where('work_file_id', $file->id)->get()->keyBy('original_name');

        $this->assertSame('RC', $docs['a.pdf']->title);
        $this->assertSame('NOC', $docs['b.pdf']->title);
    }

    /**
     * A document uploaded before names existed can be named afterwards.
     *
     * Otherwise every one of them reaches the customer as whatever the scanner
     * called it, with no way to put that right short of uploading it again.
     */
    public function test_a_document_already_on_the_file_can_be_named(): void
    {
        $file = $this->file($this->customer());
        $this->upload($file, [$this->pdf('scan_00123.pdf')]);

        $doc = WorkFileDocumentModel::latestFor($file->id);
        $doc->title = null;
        $doc->save();

        $this->upload($file, [], ['document_names' => [$doc->id => 'Insurance']])
            ->assertSessionHasNoErrors();

        $this->assertSame('Insurance', $doc->fresh()->title);
    }

    /**
     * The form sends every box, touched or not. A blank one leaves the name
     * alone, and one still holding what it showed writes nothing at all.
     */
    public function test_an_untouched_or_blank_name_changes_nothing(): void
    {
        $file = $this->file($this->customer());
        $this->upload($file, [['file' => $this->pdf(), 'title' => 'RC']]);

        $doc = WorkFileDocumentModel::latestFor($file->id);
        $stamp = $doc->updated_at;

        $this->travel(5)->minutes();

        $this->upload($file, [], ['document_names' => [$doc->id => '   ']]);
        $this->assertSame('RC', $doc->fresh()->title, 'a blank box cleared the name');

        $this->upload($file, [], ['document_names' => [$doc->id => 'RC']]);
        $this->assertEquals($stamp, $doc->fresh()->updated_at, 'an untouched box rewrote the row');
    }

    /** The ids arrive in the form body, and are looked up inside this file. */
    public function test_a_document_on_another_file_cannot_be_renamed(): void
    {
        $mine = $this->file($this->customer());
        $theirs = $this->file($this->customer());

        $this->upload($theirs, [['file' => $this->pdf(), 'title' => 'RC']]);
        $doc = WorkFileDocumentModel::latestFor($theirs->id);

        $this->upload($mine, [], ['document_names' => [$doc->id => 'Renamed from elsewhere']]);

        $this->assertSame('RC', $doc->fresh()->title);
    }

    /**
     * The office opens one under its name too, including a name in Hindi —
     * which, written raw into that header, some browsers refuse outright.
     */
    public function test_the_office_opens_a_document_under_its_name(): void
    {
        $file = $this->file($this->customer());
        $this->upload($file, [['file' => $this->pdf('scan.pdf'), 'title' => 'फॉर्म 29']]);

        $doc = WorkFileDocumentModel::latestFor($file->id);

        $disposition = (string) $this->actingAs($this->admin())
            ->get(route('workfile.document', ['id' => $file->id, 'doc' => $doc->id]))
            ->assertOk()
            ->headers->get('content-disposition');

        $this->assertStringStartsWith('inline', $disposition);

        // The UTF-8 name, encoded, and a plain one beside it for the rest.
        $this->assertStringContainsString("filename*=utf-8''".rawurlencode('फॉर्म 29.pdf'), $disposition);
        $this->assertMatchesRegularExpression('/filename="?[\x20-\x7E]+\.pdf"?/', $disposition);
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

        $this->assertSame([], $page->json('page.documents'), 'and nothing offered on the page');
    }

    /**
     * Every document on the file, not only the newest.
     *
     * The newest was offered alone on the idea that a document gets revised and
     * the latest supersedes the rest. That holds for two copies of one form. It
     * does not hold for an RC and a Form 29, and a customer offered only
     * whichever was uploaded last could not get the other at all.
     */
    public function test_the_file_page_offers_every_document_under_its_name(): void
    {
        $customer = $this->customer();
        $file = $this->file($customer);

        $this->upload($file, [
            ['file' => $this->pdf('scan_1.pdf'), 'title' => 'RC'],
            ['file' => $this->pdf('scan_2.pdf'), 'title' => 'Form 29'],
        ]);

        $docs = $this->withSession(['customer_id' => $customer->id])
            ->getJson(route('customer.file', $file->id))->assertOk()->json('page.documents');

        $this->assertSame(['Form 29', 'RC'], array_column($docs, 'name'), 'every one, newest first');

        $ids = WorkFileDocumentModel::where('work_file_id', $file->id)->orderByDesc('id')->pluck('id');

        foreach ($docs as $i => $doc) {
            $this->assertSame(
                route('customer.file.document', ['id' => $file->id, 'doc' => $ids[$i]]),
                $doc['url'],
                'offered through the route, each its own'
            );
        }

        // The stored path never reaches the customer in any form, and neither
        // does what the scanner called it.
        $body = $this->withSession(['customer_id' => $customer->id])
            ->get(route('customer.file', $file->id))->assertOk()->getContent();

        $this->assertStringNotContainsString(WorkFileModel::DOC_DIR, $body);
        $this->assertStringNotContainsString('scan_1', $body);
        $this->assertStringContainsString('Form 29', $body);
    }

    public function test_a_customer_downloads_the_one_they_chose_under_its_name(): void
    {
        $customer = $this->customer();
        $file = $this->file($customer);

        $this->upload($file, [
            ['file' => $this->pdf('scan_1.pdf'), 'title' => 'RC'],
            ['file' => $this->pdf('scan_2.pdf'), 'title' => 'Form 29'],
        ]);

        // The older of the two, which the page used to have no way to offer.
        $rc = WorkFileDocumentModel::where('work_file_id', $file->id)->where('title', 'RC')->firstOrFail();

        $response = $this->withSession(['customer_id' => $customer->id])
            ->get(route('customer.file.document', ['id' => $file->id, 'doc' => $rc->id]))
            ->assertOk();

        $disposition = (string) $response->headers->get('content-disposition');

        $this->assertStringStartsWith('attachment', $disposition);
        $this->assertStringContainsString('RC.pdf', $disposition);
        $this->assertStringNotContainsString('scan_1', $disposition);
    }

    /**
     * The document id is looked up inside the file named beside it, and that
     * file inside the signed-in customer's own. Either one belonging elsewhere
     * is not found.
     */
    public function test_a_document_from_another_file_is_not_served_through_this_one(): void
    {
        $customer = $this->customer();
        $mine = $this->file($customer);
        $alsoMine = $this->file($customer);
        $stranger = $this->file($this->customer());

        $this->upload($alsoMine, [['file' => $this->pdf(), 'title' => 'RC']]);
        $this->upload($stranger, [['file' => $this->pdf(), 'title' => 'NOC']]);

        $session = ['customer_id' => $customer->id];

        // Their own document, asked for through the wrong file.
        $this->withSession($session)
            ->get(route('customer.file.document', ['id' => $mine->id, 'doc' => WorkFileDocumentModel::latestFor($alsoMine->id)->id]))
            ->assertNotFound();

        // Somebody else's, through their own file.
        $this->withSession($session)
            ->get(route('customer.file.document', ['id' => $mine->id, 'doc' => WorkFileDocumentModel::latestFor($stranger->id)->id]))
            ->assertNotFound();
    }

    /** The old address, with no document named, still gives the newest. */
    public function test_a_link_saved_before_the_list_still_works(): void
    {
        $customer = $this->customer();
        $file = $this->file($customer);

        $this->upload($file, [['file' => $this->pdf(), 'title' => 'First']]);
        $this->upload($file, [['file' => $this->pdf(), 'title' => 'Second']]);

        $disposition = (string) $this->portal($customer, $file)->assertOk()->headers->get('content-disposition');

        $this->assertStringContainsString('Second.pdf', $disposition);
    }
}
