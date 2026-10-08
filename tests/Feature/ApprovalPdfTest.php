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
 * An approval kept as a PDF, opened from the screens that offer it.
 *
 * The preview decided what it had from a ".pdf" at the end of the address. The
 * address stopped carrying one when approvals moved behind the application —
 * every screen now links /admin/file/{id}/approval/{item}, which has no
 * extension at all — so a PDF was drawn as an image, the image would not load,
 * and the office was told the RTO's evidence had been removed from the server.
 *
 * The stored name does know: its extension is guessed from the content when it
 * is saved. So each screen says, beside each link, whether what is behind it is
 * a PDF, and these check that every one of them does — the board, the file's
 * own screen, both lists, and the customer's portal.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class ApprovalPdfTest extends TestCase
{
    use DatabaseTransactions;

    /** A day nothing real was received on, so the lists can be narrowed to these. */
    private const RECEIVED = '2001-03-04';

    private User $admin;

    private PartyModel $customer;

    private WorkTypeModel $type;

    /** Files written to public/ by a test, which no transaction rolls back. */
    private array $uploads = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Approval PDF Admin';
        $this->admin->email = 'approval-pdf-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = new PartyModel;
        $this->customer->party_type = 'customer';
        $this->customer->name = 'Approval PDF Customer '.uniqid();
        $this->customer->mobile = '9'.random_int(600000000, 999999999);
        $this->customer->is_active = 1;
        $this->customer->password = Hash::make('a-real-password-8');
        $this->customer->save();

        // Its own, so the board can be narrowed to the folders made here.
        $this->type = new WorkTypeModel;
        $this->type->name = 'PDF '.uniqid();
        $this->type->is_active = 1;
        $this->type->save();
    }

    protected function tearDown(): void
    {
        foreach ($this->uploads as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->uploads = [];

        parent::tearDown();
    }

    /**
     * A folder of one work, approved on the board with the document given.
     *
     * Through the board rather than written into the row, so the stored name
     * is the one the application gives an upload and not one this test chose.
     */
    private function approvedWith(UploadedFile $document): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-PDF-'.uniqid();
        $file->received_date = self::RECEIVED;
        $file->registration_no = 'BR06PD'.random_int(1000, 9999);
        $file->work_type_id = $this->type->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 2000;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $this->type->id;
        $item->customer_amount = 2000;
        $item->status = WorkFileModel::IN_OFFICE;
        $item->save();

        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        $this->actingAs($this->admin)->post(route('workfile.status'), [
            'statuses' => [$item->id => WorkFileModel::APPROVED],
            'approved_on' => [$item->id => '2001-03-10'],
            'screenshots' => [$item->id => $document],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $item->refresh();
        $file->refresh();

        $this->assertSame(WorkFileModel::APPROVED, $item->status, 'the approval went through');
        $this->assertNotNull($item->approval_screenshot, 'and kept its document');

        $this->uploads[] = public_path($item->approval_screenshot);

        return $file;
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('rto-approval.pdf', 20, 'application/pdf');
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->image('rto-approval.jpg');
    }

    private function onBoard(WorkFileModel $file): array
    {
        $files = $this->actingAs($this->admin)
            ->getJson(route('workfile.status', ['status' => WorkFileModel::APPROVED, 'work_type' => $this->type->id]))
            ->assertOk()->json('props.files');

        $row = collect($files)->firstWhere('id', $file->id);
        $this->assertNotNull($row, 'the folder is on the board');

        return $row['items'][0];
    }

    private function onList(string $route, WorkFileModel $file): array
    {
        $props = $this->actingAs($this->admin)
            ->getJson(route($route, ['from' => self::RECEIVED, 'to' => self::RECEIVED]))
            ->assertOk()->json('props');

        $row = collect($props['rows'])->firstWhere('id', $file->id);
        $this->assertNotNull($row, 'the folder is on the list');

        return [$row, collect($props['columns'])];
    }

    // ------------------------------------------------------------- the office

    public function test_the_board_says_a_pdf_is_one(): void
    {
        $work = $this->onBoard($this->approvedWith($this->pdf()));

        // The address cannot say: it is a route, with no extension on it.
        $this->assertStringEndsNotWith('.pdf', $work['screenshot_url']);
        $this->assertTrue($work['screenshot_is_pdf']);
    }

    public function test_the_file_screen_says_so_for_the_folder_and_for_each_work(): void
    {
        $file = $this->approvedWith($this->pdf());

        $props = $this->actingAs($this->admin)
            ->getJson(route('workfile.edit', $file->id))
            ->assertOk()->json('props');

        $this->assertTrue($props['screenshotIsPdf'], 'the folder\'s own link');
        $this->assertTrue($props['items'][0]['screenshot_is_pdf'], 'the work\'s');
    }

    public static function lists(): array
    {
        return [
            'all work files' => ['workfile.index', 'status'],
            'approved files' => ['workfile.approved', 'works_done'],
        ];
    }

    /**
     * The row says what is behind its link, and the column that draws the
     * link says where in the row to look.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('lists')]
    public function test_the_lists_say_so(string $route, string $column): void
    {
        [$row, $columns] = $this->onList($route, $this->approvedWith($this->pdf()));

        $this->assertTrue($row['screenshot_is_pdf']);

        $drawn = $columns->first(fn ($c) => $c['key'] === $column && empty($c['exportOnly']));
        $this->assertSame('screenshot_url', $drawn['subLinkTo']);
        $this->assertSame('screenshot_is_pdf', $drawn['subPdf'] ?? null);
    }

    /** And an image is not called a PDF, which would draw it in a frame. */
    public function test_an_image_is_still_an_image_everywhere(): void
    {
        $file = $this->approvedWith($this->image());

        $this->assertFalse($this->onBoard($file)['screenshot_is_pdf']);

        $props = $this->actingAs($this->admin)
            ->getJson(route('workfile.edit', $file->id))->assertOk()->json('props');
        $this->assertFalse($props['screenshotIsPdf']);
        $this->assertFalse($props['items'][0]['screenshot_is_pdf']);

        [$row] = $this->onList('workfile.index', $file);
        $this->assertFalse($row['screenshot_is_pdf']);
    }

    // ----------------------------------------------------------- the customer

    /**
     * The portal draws its approvals through the same grid and the same
     * preview, and its address is a route too.
     */
    public function test_the_customer_portal_says_so(): void
    {
        $file = $this->approvedWith($this->pdf());

        $props = $this->withSession(['customer_id' => $this->customer->id])
            ->getJson(route('customer.file', $file->id))
            ->assertOk()->json('props');

        $this->assertNotNull($props['rows'][0]['screenshot_url'], 'the approval is offered');
        $this->assertTrue($props['rows'][0]['screenshot_is_pdf']);

        $status = collect($props['columns'])->firstWhere('key', 'status');
        $this->assertSame('screenshot_is_pdf', $status['subPdf'] ?? null);
    }

    /** A PDF the customer is sent to is served as one, which a frame then shows. */
    public function test_the_document_behind_the_link_is_served_as_a_pdf(): void
    {
        $file = $this->approvedWith($this->pdf());
        $item = WorkFileItemModel::where('work_file_id', $file->id)->firstOrFail();

        // Real bytes, so the type is read from the content as a browser's is.
        file_put_contents(public_path($item->approval_screenshot), "%PDF-1.4\n%%EOF\n");

        $this->actingAs($this->admin)
            ->get(route('workfile.approval', ['id' => $file->id, 'item' => $item->id]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
