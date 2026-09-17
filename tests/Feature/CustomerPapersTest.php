<?php

namespace Tests\Feature;

use App\Models\PaperTypeModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkFilePaperModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * What a customer is told about their papers.
 *
 * The point of recording pendency paper by paper was that the customer could
 * be told which papers, rather than "documents pending" and a phone call. So:
 * the file page names each paper still needed, what it is for and the note
 * written for them; the files list and the home page say that something is
 * needed. And the office's own notes appear nowhere on any of it.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class CustomerPapersTest extends TestCase
{
    use DatabaseTransactions;

    private PartyModel $customer;

    private WorkTypeModel $hpt;

    private WorkTypeModel $tr;

    /** @var array<string, PaperTypeModel> */
    private array $paper = [];

    protected function setUp(): void
    {
        parent::setUp();

        $admin = new User;
        $admin->name = 'Customer Papers Admin';
        $admin->email = 'cpapers-'.uniqid().'@example.com';
        $admin->password = Hash::make('password-for-tests');
        $admin->user_type = 1;
        $admin->save();
        Auth::login($admin);

        $this->customer = $this->party();

        foreach (['RC', 'Form 35', 'Form 30'] as $i => $name) {
            $paper = new PaperTypeModel;
            $paper->name = $name.' '.uniqid();
            $paper->sort = $i + 1;
            $paper->save();
            $this->paper[$name] = $paper;
        }

        $this->hpt = $this->workType('HPT', ['RC', 'Form 35']);
        $this->tr = $this->workType('TR', ['RC', 'Form 30']);
    }

    private function party(): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = 'customer';
        $party->name = 'Customer '.uniqid().' for papers';
        $party->mobile = '93300'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function workType(string $name, array $papers): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->is_active = 1;
        $type->save();

        foreach ($papers as $paper) {
            $this->paper[$paper]->setNeeds([$type->id => 'required']);
        }

        return $type;
    }

    /** A folder for both works, checked with Form 30 pending. */
    private function file(?PartyModel $customer = null): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-CP-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01CP'.random_int(1000, 9999);
        $file->work_type_id = $this->hpt->id;
        $file->customer_id = ($customer ?? $this->customer)->id;
        $file->customer_amount = 2000;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        foreach ([$this->hpt, $this->tr] as $type) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = 1000;
            $item->status = WorkFileModel::IN_OFFICE;
            $item->save();
        }

        $file->savePaperChecklist([
            $this->paper['RC']->id => ['state' => 'received'],
            $this->paper['Form 35']->id => ['state' => 'received'],
            $this->paper['Form 30']->id => [
                'state' => 'pending',
                'note' => 'Buyer has not signed it',
                'office_note' => 'Rakesh owes us for last month',
            ],
        ]);

        return $file->fresh();
    }

    private function asCustomer(?PartyModel $customer = null)
    {
        return $this->withSession(['customer_id' => ($customer ?? $this->customer)->id]);
    }

    public function test_the_file_page_names_each_paper_still_needed(): void
    {
        $file = $this->file();

        $papers = $this->asCustomer()->getJson(route('customer.file', $file->id))->assertOk()->json('page.papers');

        $this->assertCount(1, $papers['needed']);
        $this->assertSame($this->paper['Form 30']->name, $papers['needed'][0]['name']);
        $this->assertSame([$this->tr->name], $papers['needed'][0]['works'], 'what it is for');
        $this->assertSame('Buyer has not signed it', $papers['needed'][0]['note']);

        $this->assertEqualsCanonicalizing(
            [$this->paper['RC']->name, $this->paper['Form 35']->name],
            $papers['received']
        );

        $body = $this->asCustomer()->get(route('customer.file', $file->id))->assertOk()->getContent();

        $this->assertStringContainsString('Papers we still need from you', $body);
        $this->assertStringContainsString('Buyer has not signed it', $body);
    }

    /** The office note is the office's, on every page a customer can open. */
    public function test_the_office_note_reaches_no_customer_page(): void
    {
        $file = $this->file();

        foreach ([route('customer.file', $file->id), route('customer.files'), route('customer.dashboard')] as $url) {
            $body = $this->asCustomer()->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('Rakesh owes us', $body, "$url shows the office note");
        }
    }

    public function test_nothing_is_asked_for_once_it_has_come_in(): void
    {
        $file = $this->file();

        WorkFileModel::receivePapers(WorkFilePaperModel::where('work_file_id', $file->id)->where('state', 'pending')->pluck('id')->all());

        $papers = $this->asCustomer()->getJson(route('customer.file', $file->id))->json('page.papers');

        $this->assertSame([], $papers['needed']);
        $this->assertCount(3, $papers['received']);

        $body = $this->asCustomer()->get(route('customer.file', $file->id))->getContent();
        $this->assertStringNotContainsString('Papers we still need from you', $body);
    }

    /** A paper only a cancelled work needed is not something to bring in. */
    public function test_a_paper_for_cancelled_work_is_not_asked_for(): void
    {
        $file = $this->file();

        WorkFileItemModel::where('work_file_id', $file->id)->where('work_type_id', $this->tr->id)
            ->update(['status' => WorkFileModel::CANCELLED]);

        $papers = $this->asCustomer()->getJson(route('customer.file', $file->id))->json('page.papers');

        $this->assertSame([], $papers['needed']);
    }

    public function test_the_files_list_says_what_is_needed(): void
    {
        $file = $this->file();

        $row = collect($this->asCustomer()->getJson(route('customer.files'))->assertOk()->json('props.rows'))
            ->firstWhere('file_no', $file->file_no);

        $this->assertStringContainsString('Papers needed: '.$this->paper['Form 30']->name, $row['works_note']);
    }

    public function test_the_home_page_says_how_many_files_need_papers(): void
    {
        $this->file();
        $this->file();

        $body = $this->asCustomer()->get(route('customer.dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/We need papers from you on\s*<strong>2 files<\/strong>/', $body);
    }

    public function test_the_home_page_says_nothing_when_nothing_is_needed(): void
    {
        $file = $this->file();
        WorkFileModel::receivePapers(WorkFilePaperModel::where('work_file_id', $file->id)->where('state', 'pending')->pluck('id')->all());

        $body = $this->asCustomer()->get(route('customer.dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('We need papers from you', $body);
    }

    /** Their history says when papers were asked for, and when they were all in. */
    public function test_the_history_says_when_papers_were_needed_and_when_they_came(): void
    {
        $file = $this->file();

        WorkFileModel::receivePapers(WorkFilePaperModel::where('work_file_id', $file->id)->where('state', 'pending')->pluck('id')->all());

        $labels = array_column(WorkFileModel::customerTimeline($file->id), 'to');

        $this->assertContains('Papers needed from you', $labels);
        $this->assertSame('Papers complete', end($labels));
    }

    /** Another customer's papers are another customer's. */
    public function test_another_customers_file_is_not_found(): void
    {
        $theirs = $this->file($this->party());

        $this->asCustomer()->get(route('customer.file', $theirs->id))->assertNotFound();

        $body = $this->asCustomer()->get(route('customer.dashboard'))->getContent();
        $this->assertStringNotContainsString('We need papers from you', $body);
    }
}
