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
 * How long a file has been out, counted from the day it went to the vendor.
 *
 * The question every list of dispatched work is read for: what has been
 * sitting at the RTO, and for how long. So the date is on the lists and in
 * what they export, the days are beside it, and a dispatch date opens newest
 * first rather than oldest.
 *
 * The count stops when the work does. On a file that is approved, returned or
 * cancelled it would otherwise keep climbing after the thing it measures has
 * finished — so it stops, and says instead how long the whole thing took,
 * which is the number a vendor is judged on.
 *
 * And a list of work still in hand opens on the file that has been out longest,
 * because that is the one to ask about.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class DispatchDaysTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $vendor;

    private WorkTypeModel $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Dispatch Admin';
        $this->admin->email = 'dispatch-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');
        $this->vendor = $this->party('vendor');

        $this->type = new WorkTypeModel;
        $this->type->name = 'Dispatch Work '.uniqid();
        $this->type->is_active = 1;
        $this->type->save();
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' for dispatch';
        $party->mobile = '93500'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function file(?int $daysAgo, string $status = WorkFileModel::DISPATCHED): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-DD-'.uniqid();
        $file->received_date = now()->subDays(($daysAgo ?? 0) + 2)->toDateString();
        $file->registration_no = 'BR01DD'.random_int(1000, 9999);
        $file->work_type_id = $this->type->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 5000;

        if ($daysAgo !== null) {
            $file->vendor_id = $this->vendor->id;
            $file->vendor_date = now()->subDays($daysAgo)->toDateString();
            $file->vendor_amount = 3000;
        }

        $file->status = $status;
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $this->type->id;
        $item->customer_amount = 5000;
        $item->vendor_amount = $daysAgo === null ? null : 3000;
        $item->status = $status;
        $item->approved_on = $status === WorkFileModel::APPROVED ? now()->toDateString() : null;
        $item->save();

        $file->syncLedger();

        return $file->fresh();
    }

    private function rowsOf(string $url): \Illuminate\Support\Collection
    {
        return collect($this->actingAs($this->admin)->getJson($url)->assertOk()->json('props.rows'))->keyBy('id');
    }

    /** The finishing day as the listing query works it out, or null. */
    private function finishedOn(WorkFileModel $file): ?string
    {
        $row = WorkFileModel::listing()->firstWhere('id', $file->id);

        return $row?->finished_on ? date('Y-m-d', strtotime($row->finished_on)) : null;
    }
    private function column(string $url, string $key): ?array
    {
        return collect($this->actingAs($this->admin)->getJson($url)->json('props.columns'))->firstWhere('key', $key);
    }

    // ------------------------------------------------------------------- counting

    public function test_the_days_are_counted_from_the_day_it_went_out(): void
    {
        $this->assertSame('today', WorkFileModel::daysOutText(now()->toDateString(), WorkFileModel::DISPATCHED));
        $this->assertSame('1 day', WorkFileModel::daysOutText(now()->subDay()->toDateString(), WorkFileModel::DISPATCHED));
        $this->assertSame('6 days', WorkFileModel::daysOutText(now()->subDays(6)->toDateString(), WorkFileModel::DISPATCHED));
        $this->assertSame(6, WorkFileModel::daysOut(now()->subDays(6)->toDateString(), WorkFileModel::DISPATCHED));
    }

    public function test_work_never_sent_out_has_no_count(): void
    {
        $this->assertNull(WorkFileModel::daysOutText(null, WorkFileModel::IN_OFFICE));
    }

    /** The count stops when the work does, with nothing to count to. */
    public function test_finished_work_stops_counting(): void
    {
        $out = now()->subDays(9)->toDateString();

        foreach ([WorkFileModel::APPROVED, WorkFileModel::RETURNED, WorkFileModel::CANCELLED] as $status) {
            $this->assertNull(WorkFileModel::daysOutText($out, $status), "$status kept counting");
        }

        $this->assertSame('9 days', WorkFileModel::daysOutText($out, WorkFileModel::DISPATCHED));
    }

    // ------------------------------------------------------------------ the lists

    public function test_the_files_list_shows_the_date_and_the_days(): void
    {
        $out = $this->file(6);
        $inOffice = $this->file(null, WorkFileModel::IN_OFFICE);
        $done = $this->file(30, WorkFileModel::APPROVED);

        $rows = $this->rowsOf(route('workfile.index'));

        $this->assertSame(now()->subDays(6)->format('d-m-Y'), $rows[$out->id]['dispatched']);
        $this->assertSame(now()->subDays(6)->format('Y-m-d'), $rows[$out->id]['dispatched_raw']);
        $this->assertSame('6 days', $rows[$out->id]['days_out']);

        $this->assertNull($rows[$inOffice->id]['dispatched'], 'never sent out');
        $this->assertNull($rows[$inOffice->id]['days_out']);

        // Finished: the date stands, and the count becomes how long it took.
        $this->assertSame(now()->subDays(30)->format('d-m-Y'), $rows[$done->id]['dispatched']);
        $this->assertSame('took 30 days', $rows[$done->id]['days_out']);
    }

    public function test_the_work_report_shows_them_too(): void
    {
        $out = $this->file(4);

        $row = collect($this->actingAs($this->admin)
            ->getJson(route('report.files', ['party_type' => 'customer', 'party_id' => $this->customer->id]))
            ->assertOk()->json('props.rows'))->firstWhere('file_no', $out->file_no);

        $this->assertSame(now()->subDays(4)->format('d-m-Y'), $row['dispatched']);
        $this->assertSame('4 days', $row['days_out']);
    }

    /**
     * A dispatch date is asked "what went out lately", so it opens downwards.
     * The grid sorts on the ISO date beside it, or 02-03 would come before 01-12.
     */
    public function test_the_dispatched_column_opens_newest_first_and_sorts_on_a_real_date(): void
    {
        foreach ([[route('workfile.index'), 'dispatched_raw'],
            [route('workfile.approved'), 'dispatched_raw'],
            [route('report.files', ['party_type' => 'customer']), 'dispatched_sort']] as [$url, $sortBy]) {
            $column = $this->column($url, 'dispatched');

            $this->assertNotNull($column, "$url has no Dispatched column");
            $this->assertSame('Dispatched', $column['label']);
            $this->assertTrue($column['sortDesc'], "$url does not open newest first");
            $this->assertSame($sortBy, $column['sortBy']);
            $this->assertSame('days_out', $column['sub'], 'the days are not under the date');
        }
    }

    /** It goes into every export: nothing marks it as screen-only. */
    public function test_the_date_is_exported(): void
    {
        foreach ([route('workfile.index'), route('workfile.approved'), route('report.files', ['party_type' => 'customer'])] as $url) {
            $column = $this->column($url, 'dispatched');

            $this->assertArrayNotHasKey('exportOnly', $column);
            $this->assertTrue($column['exportable'] ?? true, "$url keeps the dispatch date out of its exports");
        }
    }

    // ------------------------------------------------- Return from Vendor and the board

    public function test_return_from_vendor_lists_the_newest_out_first_with_its_days(): void
    {
        $older = $this->file(20);
        $newer = $this->file(2);

        $files = collect($this->actingAs($this->admin)->getJson(route('workfile.vendorreturn'))->assertOk()->json('props.files'));

        $mine = $files->whereIn('id', [$older->id, $newer->id])->values();

        $this->assertSame([$newer->id, $older->id], $mine->pluck('id')->all(), 'the newest out is not first');
        $this->assertSame('2 days', $mine[0]['days_out']);
        $this->assertSame('20 days', $mine[1]['days_out']);
    }

    public function test_the_status_board_says_when_a_file_went_out(): void
    {
        $out = $this->file(7);

        $file = collect($this->actingAs($this->admin)->getJson(route('workfile.status'))->assertOk()->json('props.files'))
            ->firstWhere('id', $out->id);

        $this->assertSame(now()->subDays(7)->format('d-m-Y'), $file['dispatched']);
        $this->assertSame('7 days', $file['days_out']);
    }
    // ----------------------------------------------------------- once it is over

    /**
     * A file that finished says how long it took.
     *
     * Blank was the honest answer to "how long has this been out" and a wasted
     * column: the span is the vendor's record, and it is what the office looks
     * back over when deciding who gets the next batch.
     */
    public function test_a_file_that_is_over_says_how_long_it_took(): void
    {
        $file = $this->file(30, WorkFileModel::APPROVED);

        $this->assertSame('took 30 days', $this->rowsOf(route('workfile.index'))[$file->id]['days_out']);
    }

    /** Said, like the days beside it: "took 1 day", "took the same day". */
    public function test_the_short_ones_read_as_sentences(): void
    {
        $out = now()->subDays(4)->toDateString();

        $this->assertSame('took the same day', WorkFileModel::daysOutText($out, WorkFileModel::APPROVED, $out));
        $this->assertSame('took 1 day', WorkFileModel::daysOutText($out, WorkFileModel::APPROVED, now()->subDays(3)->toDateString()));
        $this->assertSame('took 4 days', WorkFileModel::daysOutText($out, WorkFileModel::APPROVED, now()->toDateString()));
    }

    /** A folder of three is not through until the third one is. */
    public function test_a_folder_is_counted_to_its_last_approval(): void
    {
        $file = $this->file(10, WorkFileModel::APPROVED);

        $second = new WorkFileItemModel;
        $second->work_file_id = $file->id;
        $second->work_type_id = $this->type->id;
        $second->customer_amount = 2000;
        $second->vendor_amount = 1000;
        $second->status = WorkFileModel::APPROVED;
        $second->approved_on = now()->subDays(6)->toDateString();
        $second->save();

        // The first job was approved today, the second six days ago: ten days.
        $this->assertSame(now()->toDateString(), $file->fresh()->finishedOn());
        $this->assertSame('took 10 days', $this->rowsOf(route('workfile.index'))[$file->id]['days_out']);
    }

    /** A file that came back counts to the day it came back, not to today. */
    public function test_a_returned_file_counts_to_the_day_it_came_back(): void
    {
        $file = $this->file(20, WorkFileModel::RETURNED);
        $file->returned_on = now()->subDays(5)->toDateString();
        $file->save();

        $this->assertSame('took 15 days', $this->rowsOf(route('workfile.index'))[$file->id]['days_out']);
    }

    /**
     * Cancelled work stopped rather than finished. A turnaround against it
     * would be a number for work nobody did.
     */
    public function test_a_cancelled_file_claims_no_turnaround(): void
    {
        $file = $this->file(12, WorkFileModel::CANCELLED);

        $this->assertNull($file->fresh()->finishedOn());
        $this->assertNull($this->finishedOn($file), 'the query gave a cancelled file a finishing day');
        $this->assertNull($this->rowsOf(route('workfile.index'))[$file->id]['days_out']);
    }

    /**
     * The day itself, as the listing query works it out.
     *
     * Asserted apart from the sentence above it because the two are worked out
     * twice — once in SQL for the lists, once in PHP for the board — and a
     * wrong day in one of them is hidden by the guard against backwards dates.
     */
    public function test_the_query_and_the_model_agree_on_the_day_it_finished(): void
    {
        $approved = $this->file(10, WorkFileModel::APPROVED);

        $returned = $this->file(20, WorkFileModel::RETURNED);
        $returned->returned_on = now()->subDays(5)->toDateString();
        $returned->save();

        $running = $this->file(3);

        $this->assertSame(now()->toDateString(), $this->finishedOn($approved));
        $this->assertSame(now()->subDays(5)->toDateString(), $this->finishedOn($returned));
        $this->assertNull($this->finishedOn($running), 'work still running has no finishing day');

        foreach ([$approved, $returned, $running] as $file) {
            $this->assertSame($this->finishedOn($file), $file->fresh()->finishedOn(), "$file->file_no disagrees");
        }
    }

    public function test_work_that_never_went_out_has_no_turnaround_either(): void
    {
        $file = $this->file(null, WorkFileModel::APPROVED);

        $this->assertNull($this->rowsOf(route('workfile.index'))[$file->id]['days_out']);
    }

    /** Papers dated before they were sent are a typo, not a negative span. */
    public function test_a_date_before_the_dispatch_claims_nothing(): void
    {
        $this->assertNull(WorkFileModel::turnaround(now()->toDateString(), now()->subDays(3)->toDateString()));
        $this->assertNull(WorkFileModel::daysOutText(now()->toDateString(), WorkFileModel::APPROVED, now()->subDays(3)->toDateString()));
    }

    public function test_the_party_report_says_how_long_it_took_too(): void
    {
        $file = $this->file(9, WorkFileModel::APPROVED);

        $row = collect($this->actingAs($this->admin)
            ->getJson(route('report.files', ['party_type' => 'customer', 'party_id' => $this->customer->id]))
            ->assertOk()->json('props.rows'))->firstWhere('file_no', $file->file_no);

        $this->assertSame('took 9 days', $row['days_out']);
    }

    public function test_the_status_board_says_it_as_well(): void
    {
        $file = $this->file(14, WorkFileModel::APPROVED);

        $row = collect($this->actingAs($this->admin)->getJson(route('workfile.status', ['status' => WorkFileModel::APPROVED]))
            ->assertOk()->json('props.files'))->firstWhere('id', $file->id);

        $this->assertNotNull($row, 'the board does not show this file');
        $this->assertSame('took 14 days', $row['days_out']);
    }

    /**
     * A folder with one job through and one still at the RTO is not over.
     *
     * The board holds these: a transfer approved on Tuesday and a hypothecation
     * addition still waiting. Reading the approved job as the folder's finish
     * would stop the clock on a file that is still out — and that clock is the
     * whole reason the office looks at the board.
     */
    public function test_a_folder_with_work_still_out_keeps_counting(): void
    {
        $file = $this->file(8, WorkFileModel::PARTLY_APPROVED);

        $through = new WorkFileItemModel;
        $through->work_file_id = $file->id;
        $through->work_type_id = $this->type->id;
        $through->customer_amount = 2000;
        $through->vendor_amount = 1000;
        $through->status = WorkFileModel::APPROVED;
        $through->approved_on = now()->subDays(2)->toDateString();
        $through->save();

        $file = $file->fresh();

        $this->assertNull($file->finishedOn(), 'a part-approved folder was read as finished');
        $this->assertNull($this->finishedOn($file));

        $row = collect($this->actingAs($this->admin)->getJson(route('workfile.status', ['status' => WorkFileModel::PARTLY_APPROVED]))
            ->assertOk()->json('props.files'))->firstWhere('id', $file->id);

        $this->assertNotNull($row, 'the board does not show this file');
        $this->assertSame('8 days', $row['days_out'], 'the clock stopped on a file that is still out');
    }
    // -------------------------------------------------------- what to chase first

    /**
     * Work still in hand opens on what has been out longest.
     *
     * The list is read to find what is overdue. Newest-first puts the file that
     * has been with a vendor three weeks on the last page, which is where it
     * has been all along.
     */
    public function test_the_open_list_puts_the_longest_out_first(): void
    {
        $recent = $this->file(2);
        $ancient = $this->file(25);
        $middle = $this->file(9);
        $inOffice = $this->file(null, WorkFileModel::IN_OFFICE);

        $ids = collect($this->actingAs($this->admin)->getJson(route('workfile.index', ['status' => 'open']))
            ->assertOk()->json('props.rows'))->pluck('id')
            ->intersect([$recent->id, $ancient->id, $middle->id, $inOffice->id])->values();

        $this->assertSame([$ancient->id, $middle->id, $recent->id, $inOffice->id], $ids->all());
    }

    /** Everything else still opens with the newest file at the top. */
    public function test_the_whole_list_is_left_alone(): void
    {
        $older = $this->file(25);
        $newer = $this->file(2);

        $ids = collect($this->actingAs($this->admin)->getJson(route('workfile.index'))
            ->assertOk()->json('props.rows'))->pluck('id')
            ->intersect([$older->id, $newer->id])->values();

        // Received dates follow the dispatch dates in file(): newer went out later.
        $this->assertSame([$newer->id, $older->id], $ids->all());
    }
}