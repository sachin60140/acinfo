<?php

namespace Tests\Feature;

use App\Models\PaperTypeModel;
use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * No customer is told who does the work — not even where the office typed it.
 *
 * After the timeline was put right (VendorNameHiddenTest), a sweep of every
 * page and message a customer gets found text typed by hand reaching them as
 * typed: a file's details and remarks, a statement's particulars and
 * reference, a note against a paper they still owe, a document's name, the
 * client portal's statement. Each is read for vendors now: a line that has to
 * stay keeps its place with the vendor taken out, a loose remark naming one
 * is not shown.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class VendorNamesInTypedTextTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Typed Text Admin';
        $this->admin->email = 'typed-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer', 'Customer '.uniqid().' typed', '93500'.random_int(10000, 99999));
        $this->vendor = $this->party('vendor', 'Shailendra Pandey Motihari', '9431012345');
    }

    private function party(string $type, string $name, string $mobile): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = $name;
        $party->mobile = $mobile;
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /** A file whose details and remarks the office typed with the vendor in them. */
    private function file(): WorkFileModel
    {
        $type = new WorkTypeModel;
        $type->name = 'HPA '.uniqid();
        $type->is_active = 1;
        $type->save();

        $file = new WorkFileModel;
        $file->file_no = 'F-TT-'.uniqid();
        $file->received_date = '2026-09-15';
        $file->registration_no = 'BR05TT'.random_int(1000, 9999);
        $file->work_type_id = $type->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 2500;
        $file->description = 'HPA via Shailendra ji';
        $file->remarks = 'Sent with Shailendra Pandey Motihari';
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $type->id;
        $item->customer_amount = 2500;
        $item->status = WorkFileModel::IN_OFFICE;
        $item->save();

        $file->syncLedger();

        return $file->fresh();
    }

    private function asCustomer()
    {
        return $this->withSession(['customer_id' => $this->customer->id]);
    }

    // ------------------------------------------------------------- the helper

    public function test_a_vendor_is_taken_out_of_a_line_that_stays(): void
    {
        $said = fn ($text) => WorkFileModel::redactVendors($text);

        $this->assertSame('Paid to … for HPA', $said('Paid to Shailendra Pandey Motihari for HPA'));
        $this->assertSame('HPA via … ji', $said('HPA via Shailendra ji'));
        $this->assertSame('Paid to … for HPA', $said('Paid to SHAILENDRA  PANDEY MOTIHARI for HPA'));
        $this->assertSame('Call …', $said('Call 94310 12345'));
        $this->assertSame('Call …', $said('Call +91-9431012345'));

        // Nothing of a vendor, nothing changed.
        $this->assertSame('Shailendranath to collect from Motihari', $said('Shailendranath to collect from Motihari'));
        $this->assertSame('Call 9876543210', $said('Call 9876543210'));
        $this->assertNull($said(null));
    }

    // ---------------------------------------------------------------- portal

    /** The file page: its details keep their line, its remarks go, and so does any vendor in a paper's note. */
    public function test_the_file_page_names_no_vendor(): void
    {
        $file = $this->file();

        $paper = new PaperTypeModel;
        $paper->name = 'NOC '.uniqid();
        $paper->sort = 1;
        $paper->save();
        $paper->setNeeds([$file->work_type_id => 'required']);

        $file->savePaperChecklist([$paper->id => ['state' => 'pending', 'note' => 'Shailendra will get it from the bank']]);

        DB::table('work_file_document')->insert([
            'work_file_id' => $file->id,
            'title' => 'Shailendra HPA approval',
            'original_name' => 'scan.pdf',
            'path' => 'uploads/documents/none.pdf',
            'size' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->asCustomer()->getJson(route('customer.file', $file->id))->assertOk();
        $page = $response->getContent();

        $this->assertStringNotContainsString('Shailendra', $page);
        $this->assertSame('HPA via … ji', $response->json('page.description'));
        $this->assertNull($response->json('page.remarks'));
        $this->assertSame('… will get it from the bank', $response->json('page.papers.needed.0.note'));
    }

    /** The files list: details, remarks, and the latest remark it falls back to. */
    public function test_the_files_list_names_no_vendor(): void
    {
        $this->file();

        $list = $this->asCustomer()->getJson(route('customer.files'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Shailendra', $list);
    }

    /** The statement: a file's particulars, a hand-typed entry and its reference, and the remarks column. */
    public function test_the_customers_statement_names_no_vendor(): void
    {
        $this->file();

        $entry = new PartyLedgerModel;
        $entry->party_id = $this->customer->id;
        $entry->txn_date = '2026-09-16';
        $entry->entry_type = 'debit';
        $entry->amount = 300;
        $entry->ref_no = '9431012345';
        $entry->particular = 'Paid to Shailendra for HPA fee';
        $entry->save();

        $portal = $this->asCustomer()->getJson(route('customer.statement'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Shailendra', $portal);
        $this->assertStringNotContainsString('9431012345', $portal);

        // And the office's copy of it, which is the one printed and sent.
        $office = $this->actingAs($this->admin)->getJson(route('party.statement', $this->customer->id))->assertOk();
        $this->assertStringNotContainsString('Shailendra', $office->getContent());
        $this->assertStringNotContainsString('9431012345', $office->getContent());
        $this->assertSame('Paid to … for HPA fee', collect($office->json('props.rows'))->keyBy('id')[$entry->id]['particular']);
    }

    /** A vendor's own statement is theirs, and says what was typed on it. */
    public function test_a_vendors_statement_is_left_as_typed(): void
    {
        $entry = new PartyLedgerModel;
        $entry->party_id = $this->vendor->id;
        $entry->txn_date = '2026-09-16';
        $entry->entry_type = 'debit';
        $entry->amount = 1000;
        $entry->particular = 'Advance to Shailendra';
        $entry->save();

        $rows = collect($this->actingAs($this->admin)->getJson(route('party.statement', $this->vendor->id))->json('props.rows'))->keyBy('id');

        $this->assertSame('Advance to Shailendra', $rows[$entry->id]['particular']);
    }

    // ------------------------------------------------------------ WhatsApp

    /** Paper Audit's message to the customer uses the note with the vendor taken out. */
    public function test_the_papers_message_names_no_vendor(): void
    {
        $file = $this->file();

        $paper = new PaperTypeModel;
        $paper->name = 'Form 35 '.uniqid();
        $paper->sort = 1;
        $paper->save();
        $paper->setNeeds([$file->work_type_id => 'required']);

        $file->savePaperChecklist([$paper->id => ['state' => 'pending', 'note' => 'Ask Shailendra ji, 94310 12345']]);

        $pending = collect($this->actingAs($this->admin)->getJson(route('workfile.paperaudit'))->assertOk()->json('props.pending'))
            ->firstWhere('file_id', $file->id);

        $this->assertSame('Ask … ji, …', $pending['share_note']);
        $this->assertSame('Ask Shailendra ji, 94310 12345', $pending['note'], 'the office still sees what it typed');
    }

    // ------------------------------------------------------- client portal

    public function test_the_client_portal_statement_names_no_vendor(): void
    {
        $client = DB::table('client')->first();

        if (! $client) {
            $this->markTestSkipped('No client in this database to sign in as.');
        }

        DB::table('client_ledger')->insert([
            'client_id' => $client->id,
            'txn_date' => now()->toDateString(),
            'amount' => -500,
            'particular' => 'Paid via Shailendra Pandey Motihari',
            'payment_by' => DB::table('payment_type')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $page = $this->withSession(['userid' => $client->id])->getJson(route('userstatement'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Shailendra', $page);
    }

    /**
     * And the office's copy of it, which is the one printed and exported for
     * the client. Found in the vendor-side sweep printing the vendor's name
     * and number exactly as typed.
     */
    public function test_the_office_client_statement_names_no_vendor(): void
    {
        $client = new \App\Models\ClientModel;
        $client->name = 'Props Client '.uniqid();
        $client->mobile = '92400'.random_int(10000, 99999);
        $client->address = 'Nowhere in particular';
        $client->save();

        $id = DB::table('client_ledger')->insertGetId([
            'client_id' => $client->id,
            'txn_date' => now()->toDateString(),
            'amount' => -500,
            'particular' => 'Paid via Shailendra Pandey Motihari, 94310 12345',
            'payment_by' => (string) (DB::table('payment_type')->value('id') ?? 'Cash'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $json = $this->actingAs($this->admin)->getJson(route('clientstatement', $client->id))->assertOk();

        $this->assertSame('Paid via …, …', collect($json->json('props.rows'))->keyBy('id')[$id]['particular']);

        $page = $this->actingAs($this->admin)->get(route('clientstatement', $client->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('Shailendra', $page);
        $this->assertStringNotContainsString('94310', $page);
    }
}
