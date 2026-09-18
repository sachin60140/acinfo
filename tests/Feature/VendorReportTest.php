<?php

namespace Tests\Feature;

use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * How long each vendor takes.
 *
 * Batches are handed out on a memory of who was quick last time. This is the
 * same judgement with the numbers behind it, and the numbers have to be ones
 * the office can act on: what a vendor is holding now, how long the oldest of
 * it has waited, and how long the work they did finish actually took.
 *
 * The period is the day the work went out, so a row follows one batch through
 * rather than mixing what was sent this month with what came back in it.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class VendorReportTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private WorkTypeModel $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Vendor Report Admin';
        $this->admin->email = 'vendorreport-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');

        $this->type = new WorkTypeModel;
        $this->type->name = 'Vendor Report Work '.uniqid();
        $this->type->is_active = 1;
        $this->type->save();
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' for the vendor report';
        $party->mobile = '93800'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /**
     * One file, given out $out days ago.
     *
     * $finishedDaysAgo is when the work came through, counted the same way, so
     * a file sent 20 days ago and approved 5 days ago took 15.
     */
    private function file(?PartyModel $vendor, ?int $out, string $status = WorkFileModel::DISPATCHED, ?int $finishedDaysAgo = null): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-VR-'.uniqid();
        $file->received_date = now()->subDays(($out ?? 0) + 2)->toDateString();
        $file->registration_no = 'BR01VR'.random_int(1000, 9999);
        $file->work_type_id = $this->type->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 5000;

        if ($vendor && $out !== null) {
            $file->vendor_id = $vendor->id;
            $file->vendor_date = now()->subDays($out)->toDateString();
            $file->vendor_amount = 3000;
        }

        $file->status = $status;

        if ($status === WorkFileModel::RETURNED && $finishedDaysAgo !== null) {
            $file->returned_on = now()->subDays($finishedDaysAgo)->toDateString();
        }

        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $this->type->id;
        $item->customer_amount = 5000;
        $item->vendor_amount = 3000;
        $item->status = $status;
        $item->approved_on = ($status === WorkFileModel::APPROVED && $finishedDaysAgo !== null)
            ? now()->subDays($finishedDaysAgo)->toDateString()
            : null;
        $item->save();

        $file->syncLedger();

        return $file->fresh();
    }

    /** The report row for one vendor, whatever else is in the database. */
    private function row(PartyModel $vendor, array $query = []): ?array
    {
        return collect($this->actingAs($this->admin)->getJson(route('report.vendors', $query))
            ->assertOk()->json('props.rows'))->firstWhere('id', $vendor->id);
    }

    // ------------------------------------------------------------- what is out now

    public function test_it_counts_what_a_vendor_is_holding_and_how_long(): void
    {
        $vendor = $this->party('vendor');

        $this->file($vendor, 30);
        $this->file($vendor, 4);

        $row = $this->row($vendor);

        $this->assertSame(2, $row['files']);
        $this->assertSame(2, $row['out_now']);
        $this->assertSame(30, $row['longest_out'], 'the oldest file is not the one reported');
        $this->assertSame(0, $row['finished']);
        $this->assertNull($row['average_days'], 'an average was claimed with nothing finished');
    }

    public function test_work_that_came_back_is_no_longer_out(): void
    {
        $vendor = $this->party('vendor');

        $this->file($vendor, 20, WorkFileModel::APPROVED, 5);

        $row = $this->row($vendor);

        $this->assertSame(1, $row['files']);
        $this->assertSame(0, $row['out_now']);
        $this->assertNull($row['longest_out'], 'a finished file was counted as waiting');
    }

    // ------------------------------------------------------------ how long it took

    public function test_it_averages_the_work_that_finished(): void
    {
        $vendor = $this->party('vendor');

        $this->file($vendor, 20, WorkFileModel::APPROVED, 10);   // took 10
        $this->file($vendor, 30, WorkFileModel::APPROVED, 10);   // took 20
        $this->file($vendor, 12, WorkFileModel::RETURNED, 6);    // took 6, and still counts

        $row = $this->row($vendor);

        $this->assertSame(3, $row['finished']);
        $this->assertSame(12, $row['average_days'], '(10 + 20 + 6) / 3');
        $this->assertSame(20, $row['slowest']);
    }

    /** An average over one or two files is not a record, and says so. */
    public function test_a_thin_average_says_how_thin(): void
    {
        $vendor = $this->party('vendor');
        $this->file($vendor, 9, WorkFileModel::APPROVED, 2);

        $this->assertSame('on 1 file', $this->row($vendor)['average_note']);

        $steady = $this->party('vendor');

        foreach ([[9, 2], [8, 1], [10, 3]] as [$out, $done]) {
            $this->file($steady, $out, WorkFileModel::APPROVED, $done);
        }

        $this->assertNull($this->row($steady)['average_note'], 'three files is a record');
    }

    /**
     * Cancelled work never finished and is not waiting either. It stays in the
     * file count, so the row still adds up to what the vendor was given.
     */
    public function test_cancelled_work_counts_as_neither(): void
    {
        $vendor = $this->party('vendor');

        $this->file($vendor, 15, WorkFileModel::CANCELLED);
        $this->file($vendor, 5);

        $row = $this->row($vendor);

        $this->assertSame(2, $row['files']);
        $this->assertSame(1, $row['out_now']);
        $this->assertSame(5, $row['longest_out']);
        $this->assertSame(0, $row['finished']);
    }

    /** Papers dated before they were sent are a typo, not a nought-day job. */
    public function test_a_backwards_date_is_not_averaged_in(): void
    {
        $vendor = $this->party('vendor');

        $this->file($vendor, 10, WorkFileModel::APPROVED, 20);  // approved before it went out
        $this->file($vendor, 10, WorkFileModel::APPROVED, 4);   // took 6

        $row = $this->row($vendor);

        $this->assertSame(1, $row['finished'], 'a backwards file was counted as finished');
        $this->assertSame(6, $row['average_days'], 'a backwards file dragged the average down');
    }

    // ----------------------------------------------------------------- the period

    public function test_the_period_follows_the_day_the_work_went_out(): void
    {
        $vendor = $this->party('vendor');

        $this->file($vendor, 40, WorkFileModel::APPROVED, 30);
        $this->file($vendor, 5);

        $recent = $this->row($vendor, ['from' => now()->subDays(10)->toDateString()]);

        $this->assertSame(1, $recent['files'], 'the older batch is still in the period');
        $this->assertSame(1, $recent['out_now']);

        $old = $this->row($vendor, ['to' => now()->subDays(10)->toDateString()]);

        $this->assertSame(1, $old['files']);
        $this->assertSame(1, $old['finished']);
    }

    // ------------------------------------------------------------ who is left out

    public function test_work_kept_in_house_has_no_vendor_to_judge(): void
    {
        $this->file(null, null, WorkFileModel::IN_OFFICE);

        $rows = collect($this->actingAs($this->admin)->getJson(route('report.vendors'))->assertOk()->json('props.rows'));

        $this->assertTrue($rows->every(fn ($row) => $row['id'] > 0 && $row['vendor'] !== null));
    }

    /** A file booked to a vendor but not yet sent is not out with them. */
    public function test_a_file_with_no_dispatch_date_is_not_counted(): void
    {
        $vendor = $this->party('vendor');

        $file = $this->file($vendor, 6);
        $file->vendor_date = null;
        $file->save();

        $this->assertNull($this->row($vendor), 'a file that never went out put the vendor on the report');
    }

    // ------------------------------------------------------------------ the screen

    public function test_the_screen_reads_as_a_report(): void
    {
        $vendor = $this->party('vendor');
        $this->file($vendor, 25);

        $page = $this->actingAs($this->admin)->getJson(route('report.vendors'))->assertOk();

        $this->assertSame(
            ['vendor', 'files', 'out_now', 'longest_out', 'finished', 'average_days', 'slowest'],
            collect($page->json('props.columns'))->pluck('key')->all()
        );

        // The name opens the statement: the money side of the same vendor.
        $this->assertSame(route('party.statement', $vendor->id), $this->row($vendor)['vendor_url']);

        $this->assertGreaterThanOrEqual(25, $page->json('page.totals.oldest'));
        $this->assertStringContainsString('All work ever given out', $page->json('props.title'));
    }

    public function test_the_page_itself_loads(): void
    {
        $vendor = $this->party('vendor');
        $this->file($vendor, 3);

        $this->actingAs($this->admin)->get(route('report.vendors'))
            ->assertOk()
            ->assertSee('Vendor Report')
            ->assertSee($vendor->name);
    }

    public function test_it_is_reachable_from_the_menu(): void
    {
        $this->actingAs($this->admin)->get(route('report.profit'))
            ->assertOk()
            ->assertSee(route('report.vendors'));
    }

    public function test_a_stranger_cannot_read_it(): void
    {
        $this->get(route('report.vendors'))->assertRedirect();
    }
}
