<?php

namespace Tests\Feature;

use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileDocumentModel;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Who can fetch a file's paperwork.
 *
 * Approval screenshots and scanned documents live under public/ because that is
 * where this application has always written them. A file under public/ is served
 * by the web server to whoever asks — no session, no check, forever — so the URL
 * of a customer's paperwork kept working for anyone it was forwarded to, and
 * kept working after they stopped being a customer.
 *
 * The customer portal was given guarded routes for that reason. The office went
 * on linking at the path, so the same document had two addresses: one that
 * checked who was asking and one that did not. Now every screen asks through a
 * route, public/uploads carries a deny rule, and the only way in is a session.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class UploadAccessTest extends TestCase
{
    use DatabaseTransactions;

    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->written = [];

        parent::tearDown();
    }

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Upload Admin';
        $user->email = 'upload-'.uniqid().'@example.com';
        $user->password = Hash::make('password-for-tests');
        $user->user_type = 1;
        $user->save();

        return $user;
    }

    private function customer(): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = 'customer';
        $party->name = 'Upload Customer '.uniqid();
        $party->mobile = '96000'.random_int(10000, 99999);
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
        $file->status = 'approval_done';
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $file->work_type_id;
        $item->customer_amount = 5000;
        $item->status = 'approval_done';
        $item->approved_on = '2026-09-05';
        $item->save();

        return $file;
    }

    /** A real file on disk in the directory the application writes to. */
    private function writeUpload(string $dir, string $extension = 'png'): string
    {
        $folder = public_path($dir);

        if (! is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        $name = 'phpunit-'.uniqid().'.'.$extension;

        file_put_contents(
            $folder.'/'.$name,
            $extension === 'pdf'
                ? "%PDF-1.4\n%%EOF\n"
                : base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==')
        );

        $this->written[] = $folder.'/'.$name;

        return $dir.'/'.$name;
    }

    private function withApproval(WorkFileModel $file): WorkFileItemModel
    {
        $item = WorkFileItemModel::where('work_file_id', $file->id)->firstOrFail();
        $item->approval_screenshot = $this->writeUpload(WorkFileModel::UPLOAD_DIR);
        $item->save();

        return $item;
    }

    private function withDocument(WorkFileModel $file, string $name = 'form-34.pdf'): WorkFileDocumentModel
    {
        $doc = new WorkFileDocumentModel;
        $doc->work_file_id = $file->id;
        $doc->path = $this->writeUpload(WorkFileModel::DOC_DIR, 'pdf');
        $doc->original_name = $name;
        $doc->size = 32;
        $doc->save();

        return $doc;
    }

    // ------------------------------------------------------------ the office

    public function test_the_office_can_fetch_an_approval(): void
    {
        $file = $this->file($this->customer());
        $item = $this->withApproval($file);

        $response = $this->actingAs($this->admin())
            ->get(route('workfile.approval', ['id' => $file->id, 'item' => $item->id]))
            ->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
    }

    public function test_the_office_can_fetch_a_document_under_its_own_name(): void
    {
        $file = $this->file($this->customer());
        $doc = $this->withDocument($file, 'the-scanned-form.pdf');

        $response = $this->actingAs($this->admin())
            ->get(route('workfile.document', ['id' => $file->id, 'doc' => $doc->id]))
            ->assertOk();

        $disposition = (string) $response->headers->get('content-disposition');

        // Inline, because the office opens these to read them — but named, so
        // saving one writes the name it was scanned under.
        $this->assertStringContainsString('inline', $disposition);
        $this->assertStringContainsString('the-scanned-form.pdf', $disposition);
    }

    /** The whole point: without a session there is no way in. */
    public function test_nobody_signed_out_can_fetch_either(): void
    {
        $file = $this->file($this->customer());
        $item = $this->withApproval($file);
        $doc = $this->withDocument($file);

        $this->get(route('workfile.approval', ['id' => $file->id, 'item' => $item->id]))
            ->assertRedirect(url('/admin'));

        $this->get(route('workfile.document', ['id' => $file->id, 'doc' => $doc->id]))
            ->assertRedirect(url('/admin'));
    }

    /**
     * A customer's session is not an office session. They have their own routes,
     * scoped to their own files; these are not those.
     */
    public function test_a_customer_session_does_not_open_the_office_route(): void
    {
        $customer = $this->customer();
        $file = $this->file($customer);
        $doc = $this->withDocument($file);

        $this->withSession(['customer_id' => $customer->id])
            ->get(route('workfile.document', ['id' => $file->id, 'doc' => $doc->id]))
            ->assertRedirect(url('/admin'));
    }

    /**
     * The ids arrive in the URL. A document belonging to another file must not
     * be reachable by naming this one.
     */
    public function test_a_document_from_another_file_is_not_served(): void
    {
        $mine = $this->file($this->customer());
        $theirs = $this->file($this->customer());

        $doc = $this->withDocument($theirs);

        $this->actingAs($this->admin())
            ->get(route('workfile.document', ['id' => $mine->id, 'doc' => $doc->id]))
            ->assertNotFound();
    }

    public function test_an_approval_from_another_file_is_not_served(): void
    {
        $mine = $this->file($this->customer());
        $theirs = $this->file($this->customer());

        $item = $this->withApproval($theirs);

        $this->actingAs($this->admin())
            ->get(route('workfile.approval', ['id' => $mine->id, 'item' => $item->id]))
            ->assertNotFound();
    }

    /**
     * A stored path that climbs out of the upload directory is refused.
     *
     * These paths come from our own rows, so nothing writes one like this
     * today. The guard is for the day something else writes that column: a
     * path assembled from an upload name and handed to a file reader is how a
     * screen that serves a screenshot starts serving the environment file.
     *
     * The escaping path is checked against a file that really is there, or the
     * test would pass on the reader simply finding nothing.
     */
    public function test_a_path_that_climbs_out_of_the_upload_directory_is_refused(): void
    {
        $escape = WorkFileModel::UPLOAD_DIR."/../../../.env";

        $this->assertFileExists(public_path($escape), "the escaping path must reach a real file, or this proves nothing");

        $file = $this->file($this->customer());

        $item = WorkFileItemModel::where("work_file_id", $file->id)->firstOrFail();
        $item->approval_screenshot = $escape;
        $item->save();

        $this->actingAs($this->admin())
            ->get(route("workfile.approval", ["id" => $file->id, "item" => $item->id]))
            ->assertNotFound();
    }

    public function test_a_document_path_that_climbs_out_is_refused(): void
    {
        $escape = WorkFileModel::DOC_DIR."/../../../.env";

        $this->assertFileExists(public_path($escape));

        $file = $this->file($this->customer());

        $doc = new WorkFileDocumentModel;
        $doc->work_file_id = $file->id;
        $doc->path = $escape;
        $doc->original_name = "anything.pdf";
        $doc->size = 1;
        $doc->save();

        $this->actingAs($this->admin())
            ->get(route("workfile.document", ["id" => $file->id, "doc" => $doc->id]))
            ->assertNotFound();
    }
    // --------------------------------------------- nothing hands out the path

    /**
     * The invariant that matters, and the one a route alone does not give you.
     *
     * Adding a guarded route changes nothing while a screen goes on printing
     * the unguarded address beside it. So every office screen that can carry a
     * document is fetched and checked for the stored path in any form.
     */
    public static function screens(): array
    {
        return [
            'files list' => ['admin/files'],
            'approved files' => ['admin/files/approved'],
            'status board' => ['admin/file/status'],
            'work report' => ['admin/reports/files?party_type=customer'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('screens')]
    public function test_no_office_screen_hands_out_the_stored_path(string $url): void
    {
        $file = $this->file($this->customer());
        $this->withApproval($file);
        $this->withDocument($file);

        $body = $this->actingAs($this->admin())->get($url)->assertOk()->getContent();

        $this->assertStringNotContainsString(WorkFileModel::UPLOAD_DIR, $body, "$url prints the upload path");
        $this->assertStringNotContainsString(WorkFileModel::DOC_DIR, $body, "$url prints the document path");
    }

    public function test_the_file_screen_hands_out_neither(): void
    {
        $file = $this->file($this->customer());
        $this->withApproval($file);
        $this->withDocument($file);

        $body = $this->actingAs($this->admin())
            ->get('/admin/file/edit/'.$file->id)->assertOk()->getContent();

        $this->assertStringNotContainsString(WorkFileModel::UPLOAD_DIR, $body);
        $this->assertStringNotContainsString(WorkFileModel::DOC_DIR, $body);

        // And it does offer them, so the test is not passing on an empty page.
        $this->assertStringContainsString('/approval/', $body);
        $this->assertStringContainsString('/document/', $body);
    }

    /** The directory itself refuses to be served, for anything still linking. */
    public function test_the_upload_directory_denies_direct_access(): void
    {
        $rules = public_path('uploads/.htaccess');

        $this->assertTrue(is_file($rules), 'public/uploads carries a deny rule');

        $body = file_get_contents($rules);

        $this->assertMatchesRegularExpression('/Require all denied|Deny from all/', $body);
    }
}
