<?php

namespace Tests\Feature;

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
 * The Paper Returns report.
 *
 * Asked for by the owner on 2026-11-23: papers returned to customers, and
 * papers vendors handed back, with the switch the other reports have. Read
 * for: what each side lists and with which figures; that the figures are the
 * statements' own; the period being the day the papers went back; the party
 * filter; a file charged nothing, and work given at nothing, listed at
 * nothing; two vendors on one folder listed once each; a vendor still holding
 * part of their work said to; an older folder read off the folder; what is no
 * longer returned, or cancelled, left out; and a vendor never named
 * customer-wise.
 *
 * Every return goes through the screens that make one, so the history lines
 * the reasons come from are the ones those screens write.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class PaperReturnsReportTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $sharma;

    private PartyModel $shailendra;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Paper Returns Admin';
        $this->admin->email = 'paper-returns-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer', 'Car4Sales');
        $this->sharma = $this->party('vendor', 'Sharma DTO');
        $this->shailendra = $this->party('vendor', 'Shailendra Services');

        $this->tr = $this->workType('TR');
        $this->hpa = $this->workType('HPA');
    }

    private function party(string $type, string $name): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = $name.' '.uniqid();
        $party->mobile = '93600'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function workType(string $name): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    /** A folder of these works, 3,000 each unless said, received on the 1st of September. */
    private function file(array $types, ?float $each = 3000, ?PartyModel $customer = null): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-PR-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01PR'.random_int(1000, 9999);
        $file->work_type_id = $types[0]->id;
        $file->customer_id = ($customer ?? $this->customer)->id;
        $file->customer_amount = $each * count($types);
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        foreach ($types as $type) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = $each;
            $item->status = WorkFileModel::IN_OFFICE;
            $item->save();
        }

        $file->syncLedger();

        return $file->fresh();
    }

    private function job(WorkFileModel $file, WorkTypeModel $type): WorkFileItemModel
    {
        return WorkFileItemModel::where('work_file_id', $file->id)->where('work_type_id', $type->id)->firstOrFail();
    }

    private function give(PartyModel $vendor, WorkFileModel $file, array $jobs, array $rates, string $on = '2026-09-05'): void
    {
        $amounts = [];

        foreach ($jobs as $i => $job) {
            $amounts[$job->id] = $rates[$i];
        }

        $this->actingAs($this->admin)->post(route('workfile.assign'), [
            'vendor_id' => $vendor->id,
            'vendor_date' => $on,
            'files' => [$file->id],
            'jobs' => array_map(fn ($job) => $job->id, $jobs),
            'amounts' => $amounts,
        ])->assertSessionHasNoErrors();
    }

    private function takeBack(WorkFileModel $file, PartyModel $vendor, string $on, string $why, ?string $amount = null): void
    {
        $key = $file->id.':'.$vendor->id;

        $this->actingAs($this->admin)->post(route('workfile.vendorreturn'), [
            'returned_on' => $on,
            'files' => [$key],
            'amounts' => $amount === null ? [] : [$key => $amount],
            'remark' => $why,
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');
    }

    private function returnToCustomer(WorkFileModel $file, string $on, string $why, ?string $amount = null): void
    {
        $this->actingAs($this->admin)->post(route('workfile.customerreturn'), [
            'returned_on' => $on,
            'files' => [$file->id],
            'amounts' => $amount === null ? [] : [$file->id => $amount],
            'remark' => $why,
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');
    }

    /** The report as JSON: its rows by id, and the figures on the page. */
    private function report(array $query = []): array
    {
        $json = $this->actingAs($this->admin)
            ->getJson(route('report.paperreturns', $query))
            ->assertOk()
            ->json();

        return [collect($json['props']['rows'])->keyBy('id'), $json['page']['totals']];
    }

    // ---- Customer-wise -------------------------------------------------------

    public function test_a_file_returned_to_its_customer_is_listed_with_what_was_kept(): void
    {
        $whole = $this->file([$this->tr]);
        $part = $this->file([$this->tr, $this->hpa]);

        $this->returnToCustomer($whole, '2026-10-05', 'Customer cancelled the sale');
        $this->returnToCustomer($part, '2026-10-10', 'Trip to the RTO already made', '2000');

        [$rows, $totals] = $this->report(['party_id' => $this->customer->id]);

        $one = $rows[(string) $whole->id];
        $this->assertSame($whole->file_no, $one['file_no']);
        $this->assertSame('05-10-2026', $one['returned']);
        $this->assertSame('01-09-2026', $one['received']);
        $this->assertEquals(3000, $one['charged']);
        $this->assertEquals(3000, $one['refunded']);
        $this->assertEquals(0, $one['kept']);
        $this->assertSame('Customer cancelled the sale', $one['reason']);

        $two = $rows[(string) $part->id];
        $this->assertEquals(6000, $two['charged']);
        $this->assertEquals(2000, $two['refunded']);
        $this->assertEquals(4000, $two['kept']);
        $this->assertStringContainsString($this->tr->name, $two['work_type']);
        $this->assertStringContainsString($this->hpa->name, $two['work_type']);

        // The figures are the statement's own: the refund it was credited.
        $this->assertEquals(2000, PartyLedgerModel::where('work_file_id', $part->id)->where('file_role', 'customer_return')->value('amount'));

        $this->assertSame(2, $totals['files']);
        $this->assertEquals(9000, $totals['charged']);
        $this->assertEquals(5000, $totals['refunded']);
        $this->assertEquals(4000, $totals['kept']);

        // The latest return first.
        $this->assertSame([(string) $part->id, (string) $whole->id], $rows->pluck('id')->values()->all());
    }

    /** The file that started this: charged nothing, and still on the report. */
    public function test_a_file_charged_nothing_is_listed_at_nothing(): void
    {
        $file = $this->file([$this->tr], 0);
        $this->returnToCustomer($file, '2026-10-05', 'Customer took the papers back');

        [$rows] = $this->report(['party_id' => $this->customer->id]);

        $this->assertEquals(0, $rows[(string) $file->id]['charged']);
        $this->assertEquals(0, $rows[(string) $file->id]['refunded']);
        $this->assertEquals(0, $rows[(string) $file->id]['kept']);
    }

    public function test_the_period_is_the_day_the_papers_went_back(): void
    {
        $file = $this->file([$this->tr]);
        $this->returnToCustomer($file, '2026-10-10', 'Customer asked');

        $other = $this->party('customer', 'Maa Durga Auto');
        $theirs = $this->file([$this->tr], 3000, $other);
        $this->returnToCustomer($theirs, '2026-10-12', 'Customer asked');

        // Received in September, returned in October: October's.
        [$october] = $this->report(['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertTrue($october->has((string) $file->id));

        [$september] = $this->report(['from' => '2026-09-01', 'to' => '2026-09-30']);
        $this->assertFalse($september->has((string) $file->id));

        // And one customer's, not another's.
        [$mine] = $this->report(['party_id' => $this->customer->id]);
        $this->assertTrue($mine->has((string) $file->id));
        $this->assertFalse($mine->has((string) $theirs->id));
    }

    /** Taken off Paper Returned, its refund leaves the statement, and it leaves the report. */
    public function test_a_file_no_longer_returned_is_not_listed(): void
    {
        $file = $this->file([$this->tr]);
        $this->returnToCustomer($file, '2026-10-05', 'Customer asked');

        $file->refresh();
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();
        $file->items()->update(['status' => WorkFileModel::IN_OFFICE]);
        $file->syncLedger();

        [$rows] = $this->report(['party_id' => $this->customer->id]);

        $this->assertFalse($rows->has((string) $file->id));
    }

    /** The owner's rule: a customer is never shown who did their work. */
    public function test_customer_wise_never_names_a_vendor(): void
    {
        $file = $this->file([$this->tr]);
        $this->give($this->sharma, $file, [$this->job($file, $this->tr)], [1500]);
        $this->takeBack($file, $this->sharma, '2026-09-20', 'Vendor could not do it');
        $this->returnToCustomer($file, '2026-10-05', 'Customer took the papers back');

        $page = $this->actingAs($this->admin)
            ->get(route('report.paperreturns', ['party_id' => $this->customer->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($file->file_no, $page);
        $this->assertStringNotContainsString($this->sharma->name, $page);
    }

    // ---- Vendor-wise ---------------------------------------------------------

    public function test_work_a_vendor_handed_back_is_listed_with_what_was_reversed(): void
    {
        $whole = $this->file([$this->tr]);
        $this->give($this->sharma, $whole, [$this->job($whole, $this->tr)], [1500]);
        $this->takeBack($whole, $this->sharma, '2026-09-20', 'Vendor could not do it');

        $part = $this->file([$this->tr]);
        $this->give($this->sharma, $part, [$this->job($part, $this->tr)], [2000]);
        $this->takeBack($part, $this->sharma, '2026-09-25', 'Half done, half paid', '500');

        [$rows, $totals] = $this->report(['party_type' => 'vendor', 'party_id' => $this->sharma->id]);

        $one = $rows[$whole->id.'-'.$this->sharma->id];
        $this->assertSame($this->sharma->name, $one['party_name']);
        $this->assertSame($this->customer->name, $one['counterparty']);
        $this->assertSame('05-09-2026', $one['given']);
        $this->assertSame('20-09-2026', $one['returned']);
        $this->assertEquals(1500, $one['booked']);
        $this->assertEquals(1500, $one['reversed']);
        $this->assertStringContainsString('Vendor could not do it', $one['reason']);
        $this->assertSame('', $one['part']);

        $two = $rows[$part->id.'-'.$this->sharma->id];
        $this->assertEquals(2000, $two['booked']);
        $this->assertEquals(500, $two['reversed']);

        // The figures are the statement's own: the reversal it carries.
        $this->assertEquals(500, PartyLedgerModel::where('work_file_id', $part->id)
            ->where('party_id', $this->sharma->id)->where('file_role', 'vendor_return')->value('amount'));

        $this->assertSame(2, $totals['files']);
        $this->assertEquals(3500, $totals['booked']);
        $this->assertEquals(2000, $totals['reversed']);
    }

    public function test_work_given_at_nothing_is_listed_at_nothing(): void
    {
        $file = $this->file([$this->tr]);
        $this->give($this->sharma, $file, [$this->job($file, $this->tr)], [0]);
        $this->takeBack($file, $this->sharma, '2026-09-20', 'Vendor sent the papers back');

        [$rows] = $this->report(['party_type' => 'vendor', 'party_id' => $this->sharma->id]);

        $row = $rows[$file->id.'-'.$this->sharma->id];
        $this->assertEquals(0, $row['booked']);
        $this->assertEquals(0, $row['reversed']);
        $this->assertStringContainsString('Vendor sent the papers back', $row['reason']);
    }

    public function test_two_vendors_on_one_folder_are_listed_once_each(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);
        $this->give($this->sharma, $file, [$this->job($file, $this->tr)], [1500]);
        $this->give($this->shailendra, $file, [$this->job($file, $this->hpa)], [800]);
        $this->takeBack($file, $this->sharma, '2026-09-20', 'Transfer stuck');
        $this->takeBack($file, $this->shailendra, '2026-09-22', 'Addition stuck');

        [$rows] = $this->report(['party_type' => 'vendor']);

        $sharma = $rows[$file->id.'-'.$this->sharma->id];
        $shailendra = $rows[$file->id.'-'.$this->shailendra->id];

        $this->assertSame($this->tr->name, $sharma['work_type']);
        $this->assertEquals(1500, $sharma['reversed']);
        $this->assertStringContainsString('Transfer stuck', $sharma['reason']);

        $this->assertSame($this->hpa->name, $shailendra['work_type']);
        $this->assertEquals(800, $shailendra['reversed']);
        $this->assertSame('22-09-2026', $shailendra['returned']);
        $this->assertStringContainsString('Addition stuck', $shailendra['reason']);
    }

    /**
     * Both taken back in one go write one history line naming both. Found on
     * 2026-11-23, each row read the other vendor's part too; each says its own.
     */
    public function test_two_vendors_taken_back_together_each_keep_their_own_reason(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);
        $this->give($this->sharma, $file, [$this->job($file, $this->tr)], [1500]);
        $this->give($this->shailendra, $file, [$this->job($file, $this->hpa)], [800]);

        $this->actingAs($this->admin)->post(route('workfile.vendorreturn'), [
            'returned_on' => '2026-09-20',
            'files' => [$file->id.':'.$this->sharma->id, $file->id.':'.$this->shailendra->id],
            'remark' => 'Stuck at the RTO',
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');

        [$rows] = $this->report(['party_type' => 'vendor']);

        $sharma = $rows[$file->id.'-'.$this->sharma->id]['reason'];
        $shailendra = $rows[$file->id.'-'.$this->shailendra->id]['reason'];

        $this->assertStringStartsWith('Stuck at the RTO — ', $sharma);
        $this->assertStringContainsString('returned by '.$this->sharma->name, $sharma);
        $this->assertStringNotContainsString($this->shailendra->name, $sharma);

        $this->assertStringStartsWith('Stuck at the RTO — ', $shailendra);
        $this->assertStringContainsString('returned by '.$this->shailendra->name, $shailendra);
        $this->assertStringNotContainsString($this->sharma->name, $shailendra);
    }

    /** Handed one work back and given another since: listed with the one, and said to have the rest. */
    public function test_a_vendor_still_holding_part_of_their_work_is_said_to(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);
        $this->give($this->sharma, $file, [$this->job($file, $this->tr)], [1500]);
        $this->takeBack($file, $this->sharma, '2026-09-20', 'Transfer stuck');
        $this->give($this->sharma, $file, [$this->job($file, $this->hpa)], [800], '2026-09-25');

        [$rows] = $this->report(['party_type' => 'vendor', 'party_id' => $this->sharma->id]);

        $row = $rows[$file->id.'-'.$this->sharma->id];
        $this->assertSame($this->tr->name, $row['work_type']);
        $this->assertSame('Still with them: '.$this->hpa->name, $row['part']);
        $this->assertEquals(1500, $row['reversed']);
    }

    /** A folder from before works carried a vendor, read off the folder as its statement is. */
    public function test_an_older_folder_is_read_off_the_folder(): void
    {
        $file = $this->file([$this->tr]);
        DB::table('work_file')->where('id', $file->id)->update([
            'vendor_id' => $this->sharma->id,
            'vendor_amount' => 1200,
            'vendor_date' => '2026-09-03',
            'vendor_returned_on' => '2026-09-21',
            'vendor_returned_amount' => 700,
        ]);
        $file->refresh()->syncLedger();

        [$rows] = $this->report(['party_type' => 'vendor', 'party_id' => $this->sharma->id]);

        $row = $rows[$file->id.'-'.$this->sharma->id];
        $this->assertSame('03-09-2026', $row['given']);
        $this->assertSame('21-09-2026', $row['returned']);
        $this->assertEquals(1200, $row['booked']);
        $this->assertEquals(700, $row['reversed']);
    }

    public function test_the_vendor_side_keeps_to_its_vendor_and_period_and_leaves_out_cancelled(): void
    {
        $september = $this->file([$this->tr]);
        $this->give($this->sharma, $september, [$this->job($september, $this->tr)], [1500]);
        $this->takeBack($september, $this->sharma, '2026-09-20', 'Stuck');

        $october = $this->file([$this->tr]);
        $this->give($this->shailendra, $october, [$this->job($october, $this->tr)], [900]);
        $this->takeBack($october, $this->shailendra, '2026-10-02', 'Stuck');

        $cancelled = $this->file([$this->tr]);
        $this->give($this->sharma, $cancelled, [$this->job($cancelled, $this->tr)], [400]);
        $this->takeBack($cancelled, $this->sharma, '2026-09-21', 'Stuck');
        DB::table('work_file')->where('id', $cancelled->id)->update(['status' => WorkFileModel::CANCELLED]);

        [$rows] = $this->report(['party_type' => 'vendor', 'from' => '2026-09-01', 'to' => '2026-09-30']);
        $this->assertTrue($rows->has($september->id.'-'.$this->sharma->id));
        $this->assertFalse($rows->has($october->id.'-'.$this->shailendra->id), 'returned in October');
        $this->assertFalse($rows->has($cancelled->id.'-'.$this->sharma->id), 'cancelled');

        [$theirs] = $this->report(['party_type' => 'vendor', 'party_id' => $this->shailendra->id]);
        $this->assertSame([$october->id.'-'.$this->shailendra->id], $theirs->keys()->all());
    }

    // ---- The page ------------------------------------------------------------

    public function test_the_page_switches_sides_and_is_on_the_sidebar(): void
    {
        $page = $this->actingAs($this->admin)->get(route('report.paperreturns'))->assertOk()->getContent();

        $this->assertStringContainsString('Papers returned to customers', $page);
        $this->assertStringContainsString('Vendor-wise', $page);
        $this->assertStringContainsString(route('report.paperreturns'), $page);

        $this->actingAs($this->admin)->get(route('report.paperreturns', ['party_type' => 'vendor']))
            ->assertOk()
            ->assertSee('Papers returned by vendors');

        // A customer chosen on the vendor side is no filter at all.
        $this->actingAs($this->admin)->getJson(route('report.paperreturns', ['party_type' => 'vendor', 'party_id' => $this->customer->id]))
            ->assertOk()
            ->assertJsonPath('page.partyId', null);
    }

    public function test_only_the_office_sees_it(): void
    {
        $this->get(route('report.paperreturns'))->assertRedirect();
    }
}
