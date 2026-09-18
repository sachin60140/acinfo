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
 * finished.
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

    /** The count stops when the work does. */
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

        // Finished: the date stands, the count stops.
        $this->assertSame(now()->subDays(30)->format('d-m-Y'), $rows[$done->id]['dispatched']);
        $this->assertNull($rows[$done->id]['days_out']);
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
}
